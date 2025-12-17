<?php
namespace local_aigrading\engine;

defined('MOODLE_INTERNAL') || die;

class processor {

    private function save_file_to_disk($stored_file, $request_id) {
        global $CFG;

        if ($stored_file->is_directory()) {
            return null;
        }

        $temp_dir = $CFG->dataroot . '/local_aigrading_temp/' . $request_id;
        
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        }

        $filename = $stored_file->get_filename();
        $clean_filename = clean_param($filename, PARAM_FILE);
        $target_path = $temp_dir . '/' . $clean_filename;

        $stored_file->copy_content_to($target_path);

        return $target_path;
    }

    private function process_file_list($files, $request_id) {
        $paths = [];
        foreach ($files as $f) {
            $path = $this->save_file_to_disk($f, $request_id);
            if ($path) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    public function prepare_payload($task) {
        global $DB;

        $assignmentid = $task->assignmentid;
        $submissionid = $task->submissionid;
        
        $request_id_folder = $submissionid . '_' . time(); 
        $request_id_tracking = $task->unique_request_id ?? \core\uuid::generate();

        $assign = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assignmentid);
        $context = \context_module::instance($cm->id);

        $intro_text = strip_tags($assign->intro);
        $fs = get_file_storage();
        
        $assign_files = $fs->get_area_files($context->id, 'mod_assign', 'intro', 0, 'sortorder', false);
        $assign_file_paths = $this->process_file_list($assign_files, $request_id_folder);

        $submission_text = "";
        $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submissionid]);
        if ($onlinetext) {
            $submission_text = strip_tags($onlinetext->onlinetext);
        }

        $sub_files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submissionid, 'sortorder', false);
        $sub_file_paths = $this->process_file_list($sub_files, $request_id_folder);

        $ai_config = $DB->get_record('local_aigrading_config', ['assignmentid' => $assignmentid]);
        $reference_text_content = "";
        $explicit_instruction = "";
        $grading_criteria = ""; 
        
        if ($ai_config) {
            $reference_text_content = $ai_config->reference_text ?? "";
            $explicit_instruction = $ai_config->teacher_instruction ?? "";
        }
        
        $reference_files = $fs->get_area_files($context->id, 'local_aigrading', 'reference_file', 0, 'sortorder', false);
        
        $reference_file_path = null;
        foreach ($reference_files as $rf) {
            if (!$rf->is_directory()) {
                $reference_file_path = $this->save_file_to_disk($rf, $request_id_folder);
                break; 
            }
        }

        $rag_history = $this->get_teacher_history($assignmentid);
        $final_instruction = "";
        if (!empty($explicit_instruction)) $final_instruction .= "YÊU CẦU GIÁO VIÊN:\n" . $explicit_instruction . "\n\n";
        if (!empty($rag_history)) $final_instruction .= $rag_history;

        $callback_url_str = "http://DEBUG_MODE_NO_CALLBACK";
        if (!empty($task->secret_token)) {
             $cb = new \moodle_url('/local/aigrading/api/callback.php', ['token' => $task->secret_token]);
             $callback_url_str = $cb->out(false);
        }

        return [
            'callback_url'             => $callback_url_str,
            'request_id'               => $request_id_tracking,
            'assignment_content'       => $intro_text,
            'assignment_attachments'   => $assign_file_paths,
            'student_submission_text'  => $submission_text,
            'student_submission_files' => $sub_file_paths,
            'reference_answer_text'    => $reference_text_content,
            'reference_answer_file'    => $reference_file_path,
            'grading_criteria'         => $grading_criteria,
            'teacher_instruction'      => $final_instruction,
            'max_score'                => (float)$assign->grade
        ];
    }

    public function process_submission($task) {
        global $DB;

        $task_token = bin2hex(random_bytes(32));
        $task->secret_token = $task_token;
        
        if (empty($task->unique_request_id)) {
            $task->unique_request_id = \core\uuid::generate();
        }
        
        $DB->update_record('local_aigrading_tasks', $task);

        $payload = $this->prepare_payload($task);

        $client = new \local_aigrading\external\llm_client();
        
        $result = $client->send_grading_request($payload);

        if (!$result['success']) {
            throw new \Exception($result['message']);
        }

        return ['status' => 'sent'];
    }

    private function get_teacher_history($assignid) {
        global $DB;
        
        $sql = "SELECT teacher_notes FROM {local_aigrading_tasks} 
                WHERE assignmentid = :aid 
                  AND teacher_grade IS NOT NULL 
                  AND teacher_notes IS NOT NULL 
                  AND teacher_notes != ''
                ORDER BY timemodified DESC";
        
        $recs = $DB->get_records_sql($sql, ['aid' => $assignid], 0, 3);
        
        $txt = "";
        if (!empty($recs)) {
            $txt .= "LƯU Ý QUAN TRỌNG TỪ GIÁO VIÊN (Dựa trên lịch sử chấm trước đây):\n";
            foreach ($recs as $r) {
                $txt .= "- " . $r->teacher_notes . "\n";
            }
        }
        return $txt;
    }
}
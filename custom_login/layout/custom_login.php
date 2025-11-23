<?php
defined('MOODLE_INTERNAL') || die();

$OUTPUT->doctype();
?>
<html <?php echo $OUTPUT->htmlattributes(); ?>>
<head>
    <title><?php echo $PAGE->title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="<?php echo $OUTPUT->favicon(); ?>" />
    <link rel="stylesheet" href="<?php echo $CFG->wwwroot; ?>/theme/custom_login/layout/style.css">
    <?php echo $OUTPUT->standard_head_html(); ?>
</head>

<body <?php echo $OUTPUT->body_attributes(); ?>>
    <?php echo $OUTPUT->standard_top_of_body_html(); ?>

    <img src="<?php echo $OUTPUT->image_url('bg_left', 'theme_custom_login'); ?>" 
         alt="Decor Left"
         style="position: fixed; 
                bottom: 2vh;          /* Cách đáy 2% màn hình */
                left: 2vw;            /* Cách lề trái 2% màn hình */
                width: 30vw;          /* Độ rộng 22% màn hình */
                max-width: 800px;     /* Không to quá 300px */
                height: auto; 
                z-index: -1;          /* Nằm dưới form login */
                pointer-events: none;">

    <img src="<?php echo $OUTPUT->image_url('bg_right', 'theme_custom_login'); ?>" 
         alt="Decor Right"
         style="position: fixed; 
                bottom: 2vh;          /* Cách đáy 2% màn hình */
                right: 2vw;           /* Cách lề phải 2% màn hình */
                width: 40vw;          /* Hình người cần to hơn -> 30% màn hình */
                max-width: 800px;     /* Max to hơn */
                height: auto; 
                z-index: -1; 
                pointer-events: none;">

    <div class="login-card-wrapper">

        <div class="login-language">
            <?php echo $OUTPUT->lang_menu(); ?>
        </div>

        <div class="login-header">
            <img src="<?php echo $OUTPUT->image_url('logo_ptit', 'theme_custom_login'); ?>" class="login-logo-img" alt="PTIT Logo">
        </div>

        <div id="region-main">
            <div class="login-scale">
                <?php echo $OUTPUT->main_content(); ?>
            </div>
        </div>

    </div>

    <?php echo $OUTPUT->standard_end_of_body_html(); ?>
</body>
</html>

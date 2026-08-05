<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Extends the settings navigation inside Feedback activities.
 *
 * @param settings_navigation $settingsnav Settings navigation instance.
 * @param context $context Current page context.
 * @return void
 */
function local_feedbackdashboard_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
): void {
    global $PAGE;

    // The plugin item should only appear inside a course module.
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return;
    }

    // Confirm that the current page belongs to a course module.
    if (empty($PAGE->cm)) {
        return;
    }

    // Show the item only inside native Moodle Feedback activities.
    if ($PAGE->cm->modname !== 'feedback') {
        return;
    }

    // Only users allowed to view Feedback reports can access the Dashboard.
    if (!has_capability('mod/feedback:viewreports', $context)) {
        return;
    }

    $dashboardurl = new moodle_url(
        '/local/feedbackdashboard/index.php',
        ['id' => $PAGE->cm->id]
    );

    /*
     * The modulesettings node represents the activity's settings area.
     * Depending on the theme and available screen width, Moodle may place
     * this link directly in the secondary navigation or inside "More".
     */
    $modulesettings = $settingsnav->find(
        'modulesettings',
        navigation_node::TYPE_SETTING
    );

    if (!$modulesettings) {
        return;
    }

    $dashboardnode = $modulesettings->add(
        get_string('dashboard', 'local_feedbackdashboard'),
        $dashboardurl,
        navigation_node::TYPE_SETTING,
        null,
        'local_feedbackdashboard'
    );

    $dashboardnode->set_show_in_secondary_navigation(true);
}


<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the Dashboard item to Feedback activity navigation.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 * @return void
 */
function local_feedbackdashboard_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
): void {
    global $PAGE;

    // Execute only inside a course module.
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return;
    }

    // Confirm that the current module is a Feedback activity.
    if (empty($PAGE->cm) || $PAGE->cm->modname !== 'feedback') {
        return;
    }

    // Administrators are allowed automatically.
    // Editing teachers receive this capability through db/access.php.
    if (!has_capability('local/feedbackdashboard:view', $context)) {
        return;
    }

    // Main settings node of the current activity.
    $feedbacknode = $settingsnav->find(
        'modulesettings',
        navigation_node::TYPE_SETTING
    );

    if (!$feedbacknode) {
        return;
    }

    $url = new moodle_url('/local/feedbackdashboard/index.php', [
        'id' => $PAGE->cm->id,
    ]);

    $node = navigation_node::create(
        get_string('dashboard', 'local_feedbackdashboard'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'feedbackdashboard'
    );

    /*
     * Add Dashboard before the native Responses item.
     * This increases the chance of it remaining visible in the bar
     * instead of being moved into the "More" menu.
     */
    $feedbacknode->add_node($node, 'responses');
}
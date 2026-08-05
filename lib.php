<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published
// by the Free Software Foundation, either version 3 of the License,
// or any later version.

/**
 * Library callbacks for the Feedback Dashboard plugin.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the Dashboard link to Feedback activity navigation.
 *
 * @param settings_navigation $settingsnav Settings navigation.
 * @param context $context Current page context.
 * @return void
 */
function local_feedbackdashboard_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
): void {
    // Use the page associated with the navigation instead of global $PAGE.
    $page = $settingsnav->get_page();

    // The page must belong to a course module.
    if (empty($page->cm)) {
        return;
    }

    // Display the link only inside Moodle Feedback activities.
    if ($page->cm->modname !== 'feedback') {
        return;
    }

    // Always create the correct module context from the course module ID.
    /** @var context $modulecontext */
    $modulecontext = context_module::instance($page->cm->id, MUST_EXIST);
    assert($modulecontext instanceof context_module);

    /*
     * Site administrators are explicitly allowed.
     * Other users need the plugin capability.
     */
    if (
        !is_siteadmin() &&
        !has_capability('local/feedbackdashboard:view', $modulecontext)
    ) {
        return;
    }

    // Find the main navigation node of the current activity.
    $modulenode = $settingsnav->find(
        'modulesettings',
        navigation_node::TYPE_SETTING
    );

    if (!$modulenode) {
        return;
    }

    // Prevent duplicate links if the callback is executed more than once.
    if ($modulenode->get('feedbackdashboard')) {
        return;
    }

    $dashboardurl = new moodle_url(
        '/local/feedbackdashboard/index.php',
        ['id' => $page->cm->id]
    );

    $dashboardnode = navigation_node::create(
        get_string('dashboard', 'local_feedbackdashboard'),
        $dashboardurl,
        navigation_node::TYPE_CUSTOM,
        null,
        'feedbackdashboard'
    );

    // Allow the node to appear in the activity secondary navigation.
    $dashboardnode->set_show_in_secondary_navigation(true);
    $dashboardnode->set_force_into_more_menu(false);

    /*
     * Insert Dashboard immediately before the native Responses item.
     *
     * The local plugin callback is executed after the Feedback module
     * has already created its native navigation items.
     */
    $modulenode->add_node($dashboardnode, 'responses');

    /*
     * Moodle displays at most five items directly in the activity bar.
     * Move Responses into "More" so Dashboard remains directly visible.
     */
    $responsesnode = $modulenode->get('responses');

    if ($responsesnode) {
        $responsesnode->set_force_into_more_menu(true);
    }
}
<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Navigation hook callbacks.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_feedbackdashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks used by the plugin.
 */
class hook_callbacks {

    /**
     * Adds Dashboard directly to the activity secondary navigation.
     *
     * @param \core\hook\navigation\secondary_extend $hook Navigation hook.
     * @return void
     */
    public static function extend_secondary_navigation(
        \core\hook\navigation\secondary_extend $hook
    ): void {
        global $PAGE;

        // The current page must belong to a course module.
        if (
            empty($PAGE->cm) ||
            empty($PAGE->context) ||
            $PAGE->context->contextlevel !== CONTEXT_MODULE
        ) {
            return;
        }

        // Display Dashboard only in the native Feedback activity.
        if ($PAGE->cm->modname !== 'feedback') {
            return;
        }

        $context = \context_module::instance($PAGE->cm->id);

        // Administrators pass capability checks automatically.
        // Editing teachers receive this capability through db/access.php.
        if (!has_capability('local/feedbackdashboard:view', $context)) {
            return;
        }

        $secondarynavigation = $hook->get_secondaryview();

        // Avoid duplicate nodes.
        if ($secondarynavigation->get('feedbackdashboard')) {
            return;
        }

        $url = new \moodle_url(
            '/local/feedbackdashboard/index.php',
            ['id' => $PAGE->cm->id]
        );

        $dashboardnode = \navigation_node::create(
            get_string('dashboard', 'local_feedbackdashboard'),
            $url,
            \navigation_node::TYPE_CUSTOM,
            null,
            'feedbackdashboard'
        );

        /*
         * Insert Dashboard before Responses.
         *
         * The secondary navigation displays at most five direct items.
         * Because Dashboard is inserted before Responses, Dashboard should
         * remain visible and Responses may move into the "Mais" menu.
         */
        $secondarynavigation->add_node(
            $dashboardnode,
            'responses'
        );
    }
}
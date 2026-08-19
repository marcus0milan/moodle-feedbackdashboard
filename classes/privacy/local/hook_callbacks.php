<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Hook callbacks for the Feedback Dashboard plugin.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_feedbackdashboard\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Feedback Dashboard hook callbacks.
 */
class hook_callbacks {

    /**
     * Adds a native Dashboard NPS action to the header of Feedback pages.
     *
     * The action is available throughout the current Feedback activity
     * (Pesquisa, Configurações, Modelos, Análise and Respostas), but only
     * to users authorised to view reports and this plugin's dashboard.
     *
     * Using moodle_page::add_header_action() is more reliable across Moodle
     * 4.4/4.5 themes than attempting to inject a local-plugin node into the
     * activity's secondary navigation or its overflow "More" menu.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     * @return void
     */
    public static function add_activity_header_action(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        global $OUTPUT, $PAGE, $SCRIPT;

        // Only native Feedback pages should receive the contextual action.
        if (!is_string($SCRIPT) || strpos($SCRIPT, '/mod/feedback/') !== 0) {
            return;
        }

        // A concrete Feedback course module is required.
        if (empty($PAGE->cm) || $PAGE->cm->modname !== 'feedback') {
            return;
        }

        $context = \context_module::instance($PAGE->cm->id);

        // Match Moodle Feedback's own report permission and our capability.
        if (
            !has_capability('mod/feedback:viewreports', $context)
            || !has_capability('local/feedbackdashboard:view', $context)
        ) {
            return;
        }

        $dashboardurl = new \moodle_url(
            '/local/feedbackdashboard/index.php',
            ['id' => $PAGE->cm->id]
        );

        $label = get_string('opendashboard', 'local_feedbackdashboard');
        $icon = $OUTPUT->pix_icon('i/report', '', 'moodle', [
            'class' => 'icon mr-1',
        ]);

        $button = \html_writer::link(
            $dashboardurl,
            $icon . \html_writer::span($label),
            [
                'class' => 'btn btn-primary',
                'role' => 'button',
                'title' => $label,
                'aria-label' => $label,
            ]
        );

        $PAGE->add_header_action($button);
    }
}

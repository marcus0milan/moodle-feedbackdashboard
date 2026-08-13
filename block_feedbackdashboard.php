<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Feedback Dashboard access block.
 *
 * @package    block_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Feedback Dashboard block.
 */
class block_feedbackdashboard extends block_base {

    /**
     * Initialise the block.
     *
     * @return void
     */
    public function init(): void {
        $this->title = get_string(
            'pluginname',
            'block_feedbackdashboard'
        );
    }

    /**
     * Allow the block only on Feedback activity pages.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'all' => false,
            'mod-feedback' => true,
        ];
    }

    /**
     * Only one instance per page.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Build the block content.
     *
     * @return stdClass
     */
    public function get_content() {
        global $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        /*
         * Try to get the current course-module ID.
         *
         * First from the page object.
         */
        $cmid = 0;

        if (
            !empty($this->page->cm) &&
            !empty($this->page->cm->id)
        ) {
            $cmid = (int) $this->page->cm->id;
        }

        /*
         * Fallback:
         * analysis.php?id=7
         */
        if ($cmid <= 0) {
            $cmid = optional_param(
                'id',
                0,
                PARAM_INT
            );
        }

        /*
         * No valid activity ID.
         */
        if ($cmid <= 0) {
            $this->content->text =
                html_writer::div(
                    'Não foi possível identificar esta pesquisa.',
                    'small text-muted'
                );

            return $this->content;
        }

        /*
         * Validate that the ID really belongs to a Feedback activity.
         */
        $cm = get_coursemodule_from_id(
            'feedback',
            $cmid,
            0,
            false,
            IGNORE_MISSING
        );

        if (!$cm) {
            $this->content->text =
                html_writer::div(
                    'Este bloco está disponível apenas em atividades de Feedback.',
                    'small text-muted'
                );

            return $this->content;
        }

        /*
         * Activity context.
         */
        $context = context_module::instance(
            $cm->id
        );

        /*
         * Use Moodle's native Feedback report capability.
         *
         * Administrators pass this automatically.
         * Editing teachers normally receive this capability as part
         * of their Feedback permissions.
         */
        if (
            !has_capability(
                'mod/feedback:viewreports',
                $context
            )
        ) {
            $this->content->text =
                html_writer::div(
                    'Você não possui permissão para visualizar este Dashboard.',
                    'small text-muted'
                );

            return $this->content;
        }

        /*
         * URL of the Dashboard for THIS Feedback.
         *
         * Example:
         * /local/feedbackdashboard/index.php?id=7
         */
        $dashboardurl = new moodle_url(
            '/local/feedbackdashboard/index.php',
            [
                'id' => $cm->id,
            ]
        );

        /*
         * Short native-looking description.
         */
        $description = html_writer::tag(
            'p',
            'Visualize o NPS, gráficos, respostas e relatório desta pesquisa.',
            [
                'class' => 'small text-muted mb-3',
            ]
        );

        /*
         * Moodle / Bootstrap native button.
         */
        $buttoncontent =
            $OUTPUT->pix_icon(
                'i/report',
                ''
            )
            . ' Abrir Dashboard NPS';

        $button = html_writer::link(
            $dashboardurl,
            $buttoncontent,
            [
                'class' => 'btn btn-primary w-100',
                'title' => 'Abrir Dashboard NPS desta pesquisa',
            ]
        );

        $this->content->text =
            $description .
            $button;

        return $this->content;
    }
}
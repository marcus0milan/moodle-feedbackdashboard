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
     * Initialise block.
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
     * Only allow this block on Feedback module pages.
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
     * Generates block content.
     *
     * @return stdClass
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        /*
         * Must belong to a course module.
         */
        if (empty($this->page->cm)) {
            return $this->content;
        }

        /*
         * Only Feedback.
         */
        if ($this->page->cm->modname !== 'feedback') {
            return $this->content;
        }

        $context = context_module::instance(
            $this->page->cm->id
        );

        /*
         * Same permissions used by the Dashboard.
         */
        if (
            !has_capability(
                'mod/feedback:viewreports',
                $context
            )
        ) {
            return $this->content;
        }

        if (
            !has_capability(
                'local/feedbackdashboard:view',
                $context
            )
        ) {
            return $this->content;
        }

        $dashboardurl = new moodle_url(
            '/local/feedbackdashboard/index.php',
            [
                'id' => $this->page->cm->id,
            ]
        );

        /*
         * Use Moodle's standard button classes.
         */
        $button = html_writer::link(
            $dashboardurl,
            get_string(
                'opendashboard',
                'block_feedbackdashboard'
            ),
            [
                'class' => 'btn btn-primary w-100',
            ]
        );

        $description = html_writer::tag(
            'p',
            get_string(
                'description',
                'block_feedbackdashboard'
            ),
            [
                'class' => 'small text-muted mb-3',
            ]
        );

        $this->content->text =
            $description .
            $button;

        return $this->content;
    }
}
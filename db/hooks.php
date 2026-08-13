<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Hook registrations for Feedback Dashboard.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,

        'callback' => [
            \local_feedbackdashboard\local\hook_callbacks::class,
            'add_analysis_dashboard_button',
        ],

        'priority' => 500,
    ],
];
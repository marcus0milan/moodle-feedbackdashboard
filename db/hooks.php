<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Hook callbacks registration.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\secondary_extend::class,
        'callback' => [
            \local_feedbackdashboard\hook_callbacks::class,
            'extend_secondary_navigation',
        ],
    ],
];
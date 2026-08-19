<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site administration registration for Feedback Dashboard.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add(
    'reports',
    new admin_externalpage(
        'local_feedbackdashboard_admin',
        get_string('admindashboard', 'local_feedbackdashboard'),
        new moodle_url('/local/feedbackdashboard/admin.php'),
        'local/feedbackdashboard:viewall'
    )
);

// This plugin currently has no editable admin settings page.
$settings = null;

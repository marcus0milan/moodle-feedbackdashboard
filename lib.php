<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Library callbacks for the Feedback Dashboard plugin.
 *
 * The contextual activity access is registered through Moodle's Hooks API
 * in db/hooks.php. No settings-navigation callback is used because some
 * Moodle 4.4 themes do not surface local-plugin nodes in the activity's
 * secondary navigation or "More" menu.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

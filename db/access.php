<?php
// This file is part of Moodle - https://moodle.org/

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'block/feedbackdashboard:addinstance' => [
        'riskbitmask' => 0,
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,

        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],

        'clonepermissionsfrom' =>
            'moodle/site:manageblocks',
    ],

    'block/feedbackdashboard:myaddinstance' => [
        'riskbitmask' => 0,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,

        'archetypes' => [],

        'clonepermissionsfrom' =>
            'moodle/my:manageblocks',
    ],
];
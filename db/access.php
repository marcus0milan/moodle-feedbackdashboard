<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/feedbackdashboard:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
        ],
    ],
];
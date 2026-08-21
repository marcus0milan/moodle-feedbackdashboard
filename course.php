<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site-wide NPS overview for Feedback activities.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');

use local_feedbackdashboard\local\nps_service;

$courseid = required_param('id', PARAM_INT);

$search = optional_param('search', '', PARAM_TEXT);
$search = trim($search);

$course = get_course($courseid);

require_login($course);

$coursecontext = context_course::instance($courseid);

require_capability(
    'local/feedbackdashboard:viewall',
    context_system::instance()
);

$PAGE->set_url(
    new moodle_url('/local/feedbackdashboard/course.php', [
        'id' => $courseid,
    ])
);

$PAGE->set_course($course);
$PAGE->set_context($coursecontext);

$PAGE->set_title(
    'Dashboard NPS - ' . format_string($course->fullname)
);

$PAGE->set_heading(
    format_string($course->fullname)
);

$params = [
    'modname' => 'feedback',
    'courseid' => $courseid,
];

$where = [
    'cm.deletioninprogress = 0',
    'c.id = :courseid',
];

if ($search !== '') {
    $params['searchcourse'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['searchfeedback'] = '%' . $DB->sql_like_escape($search) . '%';

    $where[] = '(' . $DB->sql_like('c.fullname', ':searchcourse', false)
        . ' OR ' . $DB->sql_like('f.name', ':searchfeedback', false) . ')';
}

$sql = "SELECT
            cm.id AS cmid,
            f.id AS feedbackid,
            f.name AS feedbackname,
            f.anonymous,
            c.id AS courseid,
            c.fullname AS coursename,
            c.visible AS coursevisible,
            cm.visible AS cmvisible
          FROM {feedback} f
          JOIN {course_modules} cm ON cm.instance = f.id
          JOIN {modules} m ON m.id = cm.module AND m.name = :modname
          JOIN {course} c ON c.id = cm.course
         WHERE " . implode(' AND ', $where) . "
      ORDER BY c.fullname ASC, f.name ASC, cm.id ASC";

$records = $DB->get_records_sql($sql, $params);

$rows = [];
$totalsurveys = 0;
$totalresponses = 0;
$surveyswithnps = 0;
$totalvalidresponses = 0;
$totalpromoters = 0;
$totalpassives = 0;
$totaldetractors = 0;

foreach ($records as $record) {
    $context = context_module::instance((int) $record->cmid, IGNORE_MISSING);

    if (!$context) {
        continue;
    }

    if (
        !has_capability('mod/feedback:viewreports', $context)
        || !has_capability('local/feedbackdashboard:view', $context)
    ) {
        continue;
    }

    $feedback = (object) [
        'id' => (int) $record->feedbackid,
        'anonymous' => (int) $record->anonymous,
    ];

    $summary = nps_service::get_summary($feedback);

    $totalsurveys++;
    $totalresponses += $summary['totalresponses'];
    $totalvalidresponses += $summary['validresponses'];
    $totalpromoters += $summary['promoters'];
    $totalpassives += $summary['passives'];
    $totaldetractors += $summary['detractors'];

    if ($summary['hasnps']) {
        $surveyswithnps++;
    }

    $dashboardurl = new moodle_url('/local/feedbackdashboard/index.php', [
        'id' => $record->cmid,
    ]);
    $analysisurl = new moodle_url('/mod/feedback/analysis.php', [
        'id' => $record->cmid,
    ]);
    $activityurl = new moodle_url('/mod/feedback/view.php', [
        'id' => $record->cmid,
    ]);

    $feedbacklink = html_writer::link(
        $dashboardurl,
        format_string($record->feedbackname),
        ['class' => 'fw-semibold']
    );

    if ($summary['hasnps'] && $summary['validresponses'] > 0) {
        $npsvalue = format_float($summary['nps'], 0);
        $npsclass = $summary['nps'] >= 50
            ? 'feedbackdashboard-admin-badge-good'
            : ($summary['nps'] >= 0
                ? 'feedbackdashboard-admin-badge-neutral'
                : 'feedbackdashboard-admin-badge-bad');

        $npsdisplay = html_writer::span($npsvalue, 'feedbackdashboard-admin-badge ' . $npsclass);
    } else if ($summary['hasnps']) {
        $npsdisplay = html_writer::span('—', 'text-muted');
    } else {
        $npsdisplay = html_writer::span(
            get_string('nonpsquestion', 'local_feedbackdashboard'),
            'text-muted small'
        );
    }

    $lastresponse = $summary['lastresponse']
        ? userdate($summary['lastresponse'], get_string('strftimedatetimeshort', 'langconfig'))
        : get_string('noresponses', 'local_feedbackdashboard');

    $actions = html_writer::start_div('d-flex flex-wrap gap-1');
    $actions .= html_writer::link(
        $dashboardurl,
        get_string('opendashboard', 'local_feedbackdashboard'),
        ['class' => 'btn btn-sm btn-primary']
    );
    $actions .= html_writer::link(
        $analysisurl,
        get_string('openanalysis', 'local_feedbackdashboard'),
        ['class' => 'btn btn-sm btn-outline-secondary']
    );
    $actions .= html_writer::link(
        $activityurl,
        get_string('openactivity', 'local_feedbackdashboard'),
        ['class' => 'btn btn-sm btn-outline-secondary']
    );
    $actions .= html_writer::end_div();

    $rows[] = [
        $feedbacklink,
        (string) $summary['totalresponses'],
        (string) $summary['validresponses'],
        $npsdisplay,
        $summary['hasnps']
            ? $summary['promoters'] . ' (' . format_float($summary['promoterspct'], 1) . '%)'
            : '—',
        $summary['hasnps']
            ? $summary['passives'] . ' (' . format_float($summary['passivespct'], 1) . '%)'
            : '—',
        $summary['hasnps']
            ? $summary['detractors'] . ' (' . format_float($summary['detractorspct'], 1) . '%)'
            : '—',
        s($lastresponse),
        $actions,
    ];
}

$globalnps = $totalvalidresponses > 0
    ? (($totalpromoters - $totaldetractors) / $totalvalidresponses) * 100
    : null;

echo $OUTPUT->header();

echo $OUTPUT->heading(
    'Dashboard NPS - ' . format_string($course->fullname)
);
echo html_writer::tag(
    'p',
    'Visão geral das pesquisas NPS deste curso.',
    ['class' => 'text-muted mb-4']
);

// Search form.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/feedbackdashboard/course.php'))->out(false),
    'class' => 'feedbackdashboard-admin-search mb-4',
]);
echo html_writer::tag('label', get_string('searchfeedbacks', 'local_feedbackdashboard'), [
    'for' => 'feedbackdashboard-admin-search',
    'class' => 'visually-hidden',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'id',
    'value' => $courseid,
]);
echo html_writer::tag('button', get_string('search', 'local_feedbackdashboard'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
if ($search !== '') {
    echo html_writer::link(
        new moodle_url('/local/feedbackdashboard/course.php', [
    'id' => $courseid,
])
        get_string('clear', 'local_feedbackdashboard'),
        ['class' => 'btn btn-secondary']
    );
}
echo html_writer::end_tag('form');

// KPI cards.
echo html_writer::start_div('feedbackdashboard-admin-kpis mb-4');

$kpis = [
    [
        'label' => get_string('totalsurveys', 'local_feedbackdashboard'),
        'value' => $totalsurveys,
    ],
    [
        'label' => get_string('totalresponses', 'local_feedbackdashboard'),
        'value' => $totalresponses,
    ],
    [
        'label' => get_string('surveyswithnps', 'local_feedbackdashboard'),
        'value' => $surveyswithnps,
    ],
    [
        'label' => get_string('globalnps', 'local_feedbackdashboard'),
        'value' => $globalnps === null ? '—' : format_float($globalnps, 0),
    ],
];

foreach ($kpis as $kpi) {
    echo html_writer::start_div('feedbackdashboard-admin-kpi');
    echo html_writer::div(s((string) $kpi['label']), 'feedbackdashboard-admin-kpi-label');
    echo html_writer::div(s((string) $kpi['value']), 'feedbackdashboard-admin-kpi-value');
    echo html_writer::end_div();
}

echo html_writer::end_div();

if (empty($rows)) {
    echo $OUTPUT->notification(get_string('nofeedbacks', 'local_feedbackdashboard'), 'info');
} else {
    $table = new html_table();
    $table->attributes = ['class' => 'generaltable feedbackdashboard-admin-table'];
    $table->head = [
        get_string('course', 'local_feedbackdashboard'),
        get_string('feedback', 'local_feedbackdashboard'),
        get_string('responses', 'local_feedbackdashboard'),
        get_string('validnpsresponses', 'local_feedbackdashboard'),
        get_string('nps', 'local_feedbackdashboard'),
        get_string('promoters', 'local_feedbackdashboard'),
        get_string('passives', 'local_feedbackdashboard'),
        get_string('detractors', 'local_feedbackdashboard'),
        get_string('lastresponse', 'local_feedbackdashboard'),
        get_string('actions', 'local_feedbackdashboard'),
    ];
    $table->data = $rows;

    echo html_writer::start_div('feedbackdashboard-admin-table-wrap');
    echo html_writer::table($table);
    echo html_writer::end_div();
}

echo $OUTPUT->footer();

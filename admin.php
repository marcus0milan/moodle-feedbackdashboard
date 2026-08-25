<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site-wide NPS overview grouped by course.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');

use local_feedbackdashboard\local\nps_service;

/*
 * -------------------------------------------------------------------------
 * Permissions and page setup.
 * -------------------------------------------------------------------------
 */

admin_externalpage_setup('local_feedbackdashboard_admin');

require_capability(
    'local/feedbackdashboard:viewall',
    context_system::instance()
);

/*
 * -------------------------------------------------------------------------
 * Search.
 * -------------------------------------------------------------------------
 */

$search = optional_param('search', '', PARAM_TEXT);
$search = trim($search);

$params = [
    'modname' => 'feedback',
];

$where = [
    'cm.deletioninprogress = 0',
];

if ($search !== '') {
    $params['searchcourse'] = '%' . $DB->sql_like_escape($search) . '%';

    $where[] = $DB->sql_like(
        'c.fullname',
        ':searchcourse',
        false
    );
}

/*
 * -------------------------------------------------------------------------
 * Load Feedback activities.
 *
 * We load the Feedback activities first and group their NPS information
 * by course below.
 * -------------------------------------------------------------------------
 */

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
          JOIN {course_modules} cm
            ON cm.instance = f.id
          JOIN {modules} m
            ON m.id = cm.module
           AND m.name = :modname
          JOIN {course} c
            ON c.id = cm.course
         WHERE " . implode(' AND ', $where) . "
      ORDER BY c.fullname ASC, f.name ASC, cm.id ASC";

$records = $DB->get_records_sql($sql, $params);

/*
 * -------------------------------------------------------------------------
 * Aggregate data by course.
 * -------------------------------------------------------------------------
 */

$courses = [];

foreach ($records as $record) {
    $context = context_module::instance(
        (int) $record->cmid,
        IGNORE_MISSING
    );

    if (!$context) {
        continue;
    }

    /*
     * Keep the same report permissions already used by the plugin.
     */
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

    /*
     * Do not include Feedback activities without a valid NPS question.
     */
    if (!$summary['hasnps']) {
        continue;
    }

    $courseid = (int) $record->courseid;

    /*
     * Create the course accumulator on first occurrence.
     */
    if (!isset($courses[$courseid])) {
        $courses[$courseid] = [
            'id' => $courseid,
            'name' => $record->coursename,
            'surveys' => 0,
            'responses' => 0,
            'validresponses' => 0,
            'promoters' => 0,
            'passives' => 0,
            'detractors' => 0,
            'lastresponse' => 0,
        ];
    }

    /*
     * Aggregate all NPS Feedback activities from this course.
     */
    $courses[$courseid]['surveys']++;
    $courses[$courseid]['responses'] += $summary['totalresponses'];
    $courses[$courseid]['validresponses'] += $summary['validresponses'];
    $courses[$courseid]['promoters'] += $summary['promoters'];
    $courses[$courseid]['passives'] += $summary['passives'];
    $courses[$courseid]['detractors'] += $summary['detractors'];

    if (
        !empty($summary['lastresponse'])
        && $summary['lastresponse'] > $courses[$courseid]['lastresponse']
    ) {
        $courses[$courseid]['lastresponse'] = $summary['lastresponse'];
    }
}

/*
 * -------------------------------------------------------------------------
 * Build table rows and global totals.
 * -------------------------------------------------------------------------
 */

$rows = [];

$totalcourses = 0;
$totalsurveys = 0;
$totalresponses = 0;
$totalvalidresponses = 0;
$totalpromoters = 0;
$totalpassives = 0;
$totaldetractors = 0;

foreach ($courses as $coursedata) {
    /*
     * Combined course NPS.
     *
     * This is not a simple arithmetic average of each Feedback NPS.
     * It uses all valid NPS responses from the course.
     */
    $coursenps = $coursedata['validresponses'] > 0
        ? (
            (
                $coursedata['promoters']
                - $coursedata['detractors']
            )
            / $coursedata['validresponses']
        ) * 100
        : null;

    /*
     * Percentages.
     */
    $promoterspct = $coursedata['validresponses'] > 0
        ? ($coursedata['promoters'] / $coursedata['validresponses']) * 100
        : 0;

    $passivespct = $coursedata['validresponses'] > 0
        ? ($coursedata['passives'] / $coursedata['validresponses']) * 100
        : 0;

    $detractorspct = $coursedata['validresponses'] > 0
        ? ($coursedata['detractors'] / $coursedata['validresponses']) * 100
        : 0;

    /*
     * Course page.
     */
    $courseurl = new moodle_url(
        '/local/feedbackdashboard/course.php',
        [
            'id' => $coursedata['id'],
        ]
    );

    /*
     * Course name opens its NPS dashboard.
     */
    $courselink = html_writer::link(
        $courseurl,
        format_string($coursedata['name']),
        [
            'class' => 'fw-semibold',
        ]
    );

    /*
     * Same conditional formatting used by course.php.
     */
    if ($coursenps !== null) {
        $npsvalue = format_float($coursenps, 0);

        $npsclass = $coursenps >= 50
            ? 'feedbackdashboard-admin-badge-good'
            : (
                $coursenps >= 0
                    ? 'feedbackdashboard-admin-badge-neutral'
                    : 'feedbackdashboard-admin-badge-bad'
            );

        $npsdisplay = html_writer::span(
            $npsvalue,
            'feedbackdashboard-admin-badge ' . $npsclass
        );
    } else {
        $npsdisplay = html_writer::span(
            '—',
            'text-muted'
        );
    }

    /*
     * Last response among all NPS Feedback activities in the course.
     */
    $lastresponse = $coursedata['lastresponse']
        ? userdate(
            $coursedata['lastresponse'],
            get_string(
                'strftimedatetimeshort',
                'langconfig'
            )
        )
        : get_string(
            'noresponses',
            'local_feedbackdashboard'
        );

    /*
 * Actions.
 */
$courseurl = new moodle_url(
    '/local/feedbackdashboard/course.php',
    [
        'id' => $coursedata['id'],
    ]
);

$coursepdfurl = new moodle_url(
    '/local/feedbackdashboard/download_course.php',
    [
        'id' => $coursedata['id'],
    ]
);

$actions = html_writer::start_div(
    'd-flex flex-wrap gap-1'
);

/*
 * Abrir Dashboard do curso.
 */
$actions .= html_writer::link(
    $courseurl,
    get_string(
        'opencourse',
        'local_feedbackdashboard'
    ),
    [
        'class' => 'btn btn-sm btn-primary',
        'style' => 'width:170px; min-height:38px; display:inline-flex; align-items:center; justify-content:center;',
    ]
);

/*
 * Baixar relatório consolidado do curso.
 */
$pdfbuttoncontent =
    $OUTPUT->pix_icon('t/download', '')
    . ' '
    . get_string(
        'downloadcoursepdf',
        'local_feedbackdashboard'
    );

$actions .= html_writer::link(
    $coursepdfurl,
    $pdfbuttoncontent,
    [
        'class' => 'btn btn-sm btn-outline-primary',
        'style' => 'width:170px; min-height:38px; display:inline-flex; align-items:center; justify-content:center; gap:.35rem;',
        'title' => get_string(
            'downloadcoursepdf',
            'local_feedbackdashboard'
        ),
    ]
);

$actions .= html_writer::end_div();

    /*
     * Table row.
     */
    $rows[] = [
        $courselink,
        (string) $coursedata['surveys'],
        (string) $coursedata['responses'],
        (string) $coursedata['validresponses'],
        $npsdisplay,

        $coursedata['promoters']
            . ' ('
            . format_float($promoterspct, 1)
            . '%)',

        $coursedata['passives']
            . ' ('
            . format_float($passivespct, 1)
            . '%)',

        $coursedata['detractors']
            . ' ('
            . format_float($detractorspct, 1)
            . '%)',

        s($lastresponse),

        $actions,
    ];

    /*
     * Global counters.
     */
    $totalcourses++;
    $totalsurveys += $coursedata['surveys'];
    $totalresponses += $coursedata['responses'];
    $totalvalidresponses += $coursedata['validresponses'];
    $totalpromoters += $coursedata['promoters'];
    $totalpassives += $coursedata['passives'];
    $totaldetractors += $coursedata['detractors'];
}

/*
 * -------------------------------------------------------------------------
 * Global NPS across all listed courses.
 * -------------------------------------------------------------------------
 */

$globalnps = $totalvalidresponses > 0
    ? (
        (
            $totalpromoters
            - $totaldetractors
        )
        / $totalvalidresponses
    ) * 100
    : null;

/*
 * -------------------------------------------------------------------------
 * Output.
 * -------------------------------------------------------------------------
 */

echo $OUTPUT->header();

echo $OUTPUT->heading(
    get_string(
        'admindashboardheading',
        'local_feedbackdashboard'
    )
);

echo html_writer::tag(
    'p',
    get_string(
        'admindashboarddescription',
        'local_feedbackdashboard'
    ),
    [
        'class' => 'text-muted mb-4',
    ]
);

/*
 * -------------------------------------------------------------------------
 * Course search.
 * -------------------------------------------------------------------------
 */

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (
        new moodle_url(
            '/local/feedbackdashboard/admin.php'
        )
    )->out(false),
    'class' => 'feedbackdashboard-admin-search mb-4',
]);

echo html_writer::tag(
    'label',
    get_string(
        'searchcourses',
        'local_feedbackdashboard'
    ),
    [
        'for' => 'feedbackdashboard-admin-search',
        'class' => 'visually-hidden',
    ]
);

echo html_writer::empty_tag('input', [
    'type' => 'search',
    'id' => 'feedbackdashboard-admin-search',
    'name' => 'search',
    'value' => $search,
    'class' => 'form-control',
    'placeholder' => get_string(
        'searchcourses',
        'local_feedbackdashboard'
    ),
]);

echo html_writer::tag(
    'button',
    get_string(
        'search',
        'local_feedbackdashboard'
    ),
    [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]
);

if ($search !== '') {
    echo html_writer::link(
        new moodle_url(
            '/local/feedbackdashboard/admin.php'
        ),
        get_string(
            'clear',
            'local_feedbackdashboard'
        ),
        [
            'class' => 'btn btn-secondary',
        ]
    );
}

echo html_writer::end_tag('form');

/*
 * -------------------------------------------------------------------------
 * KPI cards.
 *
 * Uses the same visual classes already used by course.php.
 * -------------------------------------------------------------------------
 */

echo html_writer::start_div(
    'feedbackdashboard-admin-kpis mb-4'
);

$kpis = [
    [
        'label' => get_string(
            'courseswithnps',
            'local_feedbackdashboard'
        ),
        'value' => $totalcourses,
    ],
    [
        'label' => get_string(
            'surveyswithnps',
            'local_feedbackdashboard'
        ),
        'value' => $totalsurveys,
    ],
    [
        'label' => get_string(
            'validnpsresponses',
            'local_feedbackdashboard'
        ),
        'value' => $totalvalidresponses,
    ],
    [
        'label' => get_string(
            'globalnps',
            'local_feedbackdashboard'
        ),
        'value' => $globalnps === null
            ? '—'
            : format_float($globalnps, 0) . '%',
    ],
];

foreach ($kpis as $kpi) {
    echo html_writer::start_div(
        'feedbackdashboard-admin-kpi'
    );

    echo html_writer::div(
        s((string) $kpi['label']),
        'feedbackdashboard-admin-kpi-label'
    );

    echo html_writer::div(
        s((string) $kpi['value']),
        'feedbackdashboard-admin-kpi-value'
    );

    echo html_writer::end_div();
}

echo html_writer::end_div();

/*
 * -------------------------------------------------------------------------
 * Course table.
 * -------------------------------------------------------------------------
 */

if (empty($rows)) {
    echo $OUTPUT->notification(
        get_string(
            'nocourseswithnps',
            'local_feedbackdashboard'
        ),
        'info'
    );
} else {
    $table = new html_table();

    $table->attributes = [
        'class' =>
            'generaltable feedbackdashboard-admin-table',
    ];

    $table->head = [
        get_string(
            'course',
            'local_feedbackdashboard'
        ),
        get_string(
            'surveyswithnps',
            'local_feedbackdashboard'
        ),
        get_string(
            'responses',
            'local_feedbackdashboard'
        ),
        get_string(
            'validnpsresponses',
            'local_feedbackdashboard'
        ),
        get_string(
            'nps',
            'local_feedbackdashboard'
        ),
        get_string(
            'promoters',
            'local_feedbackdashboard'
        ),
        get_string(
            'passives',
            'local_feedbackdashboard'
        ),
        get_string(
            'detractors',
            'local_feedbackdashboard'
        ),
        get_string(
            'lastresponse',
            'local_feedbackdashboard'
        ),
        get_string(
            'actions',
            'local_feedbackdashboard'
        ),
    ];

    $table->data = $rows;

    echo html_writer::start_div(
        'feedbackdashboard-admin-table-wrap'
    );

    echo html_writer::table($table);

    echo html_writer::end_div();
}

echo $OUTPUT->footer();
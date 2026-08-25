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

$selectedcourseids = optional_param_array('courses', [], PARAM_INT);
$selectedcourseids = array_values(
    array_unique(
        array_map('intval', $selectedcourseids)
    )
);

$params = [
    'modname' => 'feedback',
];

$where = [
    'cm.deletioninprogress = 0',
];


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
 * Keep only course IDs that are actually available in this dashboard.
 */
$availablecourseids = array_map(
    'intval',
    array_keys($courses)
);

$selectedcourseids = array_values(
    array_intersect(
        $selectedcourseids,
        $availablecourseids
    )
);

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
     * When courses are explicitly selected, only those courses
     * contribute to the table and global dashboard indicators.
     */
    if (
        !empty($selectedcourseids)
        && !in_array(
            (int) $coursedata['id'],
            $selectedcourseids,
            true
        )
    ) {
        continue;
    }

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

$coursefilterjavascript = <<<'JS'
(function() {
    'use strict';

    const form = document.getElementById(
        'feedbackdashboard-course-filter-form'
    );

    if (!form) {
        return;
    }

    const picker = document.getElementById(
        'feedbackdashboard-course-picker'
    );

    const searchInput = document.getElementById(
        'feedbackdashboard-course-search'
    );

    const tagsContainer = document.getElementById(
        'feedbackdashboard-selected-courses'
    );

    const hiddenInputs = document.getElementById(
        'feedbackdashboard-selected-course-inputs'
    );

    const suggestions = document.getElementById(
        'feedbackdashboard-course-suggestions'
    );

    const emptySuggestion = document.getElementById(
        'feedbackdashboard-no-course-suggestion'
    );

    const clearButton = document.getElementById(
        'feedbackdashboard-clear-selected-courses'
    );

    const selectionLive = document.getElementById(
        'feedbackdashboard-course-selection-live'
    );

    const suggestionButtons = Array.from(
        form.querySelectorAll(
            '.feedbackdashboard-course-suggestion'
        )
    );

    const selected = new Set();

    if (tagsContainer) {
        tagsContainer
            .querySelectorAll(
                '.feedbackdashboard-course-tag'
            )
            .forEach(function(tag) {
                const courseid =
                    String(tag.dataset.courseId || '');

                if (courseid !== '') {
                    selected.add(courseid);
                }
            });
    }

    const normalise = function(value) {
        return (value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLocaleLowerCase();
    };

    const updateSelectionState = function() {
        if (clearButton) {
            clearButton.hidden = selected.size === 0;
        }

        if (selectionLive) {
            if (selected.size === 0) {
                selectionLive.textContent =
                    'Todos os cursos com NPS serão considerados.';
            } else if (selected.size === 1) {
                selectionLive.textContent =
                    '1 curso selecionado.';
            } else {
                selectionLive.textContent =
                    selected.size + ' cursos selecionados.';
            }
        }

        if (searchInput) {
            searchInput.placeholder =
                selected.size === 0
                    ? 'Digite ou escolha um curso...'
                    : 'Adicionar outro curso...';
        }
    };

    const closeSuggestions = function() {
        if (suggestions) {
            suggestions.hidden = true;
        }

        if (searchInput) {
            searchInput.setAttribute(
                'aria-expanded',
                'false'
            );
        }
    };

    const updateSuggestions = function() {
        if (!suggestions || !searchInput) {
            return;
        }

        const term = normalise(searchInput.value);
        let visibleCount = 0;

        suggestionButtons.forEach(function(button) {
            const courseid =
                String(button.dataset.courseId || '');

            const coursename =
                normalise(
                    button.dataset.courseName || ''
                );

            const matchesText =
                term === ''
                || coursename.includes(term);

            const matches =
                matchesText
                && !selected.has(courseid);

            button.hidden = !matches;

            if (matches) {
                visibleCount++;
            }
        });

        if (emptySuggestion) {
            emptySuggestion.hidden =
                visibleCount > 0;
        }

        suggestions.hidden = false;

        searchInput.setAttribute(
            'aria-expanded',
            'true'
        );
    };

    const createHiddenInput = function(courseid) {
        if (!hiddenInputs) {
            return;
        }

        const input = document.createElement('input');

        input.type = 'hidden';
        input.name = 'courses[]';
        input.value = courseid;
        input.dataset.courseId = courseid;

        hiddenInputs.appendChild(input);
    };

    const removeHiddenInput = function(courseid) {
        if (!hiddenInputs) {
            return;
        }

        const inputs = Array.from(
            hiddenInputs.querySelectorAll(
                'input[name="courses[]"]'
            )
        );

        const input = inputs.find(function(current) {
            return String(
                current.dataset.courseId
                || current.value
            ) === courseid;
        });

        if (input) {
            input.remove();
        }
    };

    const createTag = function(
        courseid,
        coursename
    ) {
        if (!tagsContainer) {
            return;
        }

        const tag = document.createElement('span');

        tag.className =
            'feedbackdashboard-course-tag';

        tag.dataset.courseId = courseid;

        const label =
            document.createElement('span');

        label.className =
            'feedbackdashboard-course-tag-label';

        label.textContent = coursename;

        const remove =
            document.createElement('button');

        remove.type = 'button';

        remove.className =
            'feedbackdashboard-course-tag-remove';

        remove.dataset.courseId = courseid;

        remove.title = 'Remover curso';

        remove.setAttribute(
            'aria-label',
            'Remover ' + coursename
        );

        remove.textContent = '×';

        tag.appendChild(label);
        tag.appendChild(remove);

        tagsContainer.appendChild(tag);
    };

    const addCourse = function(
        courseid,
        coursename
    ) {
        courseid = String(courseid || '');

        if (
            courseid === ''
            || selected.has(courseid)
        ) {
            return;
        }

        selected.add(courseid);

        createTag(
            courseid,
            coursename
        );

        createHiddenInput(courseid);

        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }

        updateSelectionState();
        updateSuggestions();
    };

    const removeCourse = function(courseid) {
        courseid = String(courseid || '');

        if (
            courseid === ''
            || !selected.has(courseid)
        ) {
            return;
        }

        selected.delete(courseid);

        if (tagsContainer) {
            const tags = Array.from(
                tagsContainer.querySelectorAll(
                    '.feedbackdashboard-course-tag'
                )
            );

            const tag = tags.find(function(current) {
                return String(
                    current.dataset.courseId || ''
                ) === courseid;
            });

            if (tag) {
                tag.remove();
            }
        }

        removeHiddenInput(courseid);

        updateSelectionState();

        if (searchInput) {
            searchInput.focus();
        }

        updateSuggestions();
    };

    const clearCourses = function() {
        selected.clear();

        if (tagsContainer) {
            tagsContainer.innerHTML = '';
        }

        if (hiddenInputs) {
            hiddenInputs.innerHTML = '';
        }

        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }

        updateSelectionState();
        updateSuggestions();
    };

    suggestionButtons.forEach(function(button) {
        button.addEventListener(
            'click',
            function() {
                addCourse(
                    String(
                        button.dataset.courseId || ''
                    ),
                    String(
                        button.dataset.courseName || ''
                    )
                );
            }
        );
    });

    if (tagsContainer) {
        tagsContainer.addEventListener(
            'click',
            function(event) {
                const removeButton =
                    event.target.closest(
                        '.feedbackdashboard-course-tag-remove'
                    );

                if (!removeButton) {
                    return;
                }

                removeCourse(
                    String(
                        removeButton.dataset.courseId
                        || ''
                    )
                );
            }
        );
    }

    if (clearButton) {
        clearButton.addEventListener(
            'click',
            clearCourses
        );
    }

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            updateSuggestions
        );

        searchInput.addEventListener(
            'focus',
            updateSuggestions
        );

        searchInput.addEventListener(
            'keydown',
            function(event) {

                if (
                    event.key === 'Backspace'
                    && searchInput.value === ''
                    && tagsContainer
                ) {
                    const tags =
                        tagsContainer.querySelectorAll(
                            '.feedbackdashboard-course-tag'
                        );

                    const lastTag =
                        tags.length > 0
                            ? tags[tags.length - 1]
                            : null;

                    if (lastTag) {
                        removeCourse(
                            String(
                                lastTag.dataset.courseId
                                || ''
                            )
                        );
                    }
                }

                if (event.key === 'Escape') {
                    closeSuggestions();
                }

                if (
                    event.key === 'Enter'
                    && suggestions
                    && !suggestions.hidden
                ) {
                    const firstVisible =
                        suggestionButtons.find(
                            function(button) {
                                return !button.hidden;
                            }
                        );

                    if (firstVisible) {
                        event.preventDefault();
                        firstVisible.click();
                    }
                }
            }
        );
    }

    document.addEventListener(
        'click',
        function(event) {
            if (
                picker
                && !picker.contains(event.target)
            ) {
                closeSuggestions();
            }
        }
    );

    updateSelectionState();
})();
JS;

$PAGE->requires->js_init_code(
    $coursefilterjavascript
);


/*
 * -------------------------------------------------------------------------
 * Output.
 * -------------------------------------------------------------------------
 */

echo $OUTPUT->header();

$coursefiltercss = '
.feedbackdashboard-course-filter {
    max-width:100%;
}

.feedbackdashboard-course-picker {
    position:relative;
}

.feedbackdashboard-course-picker-box {
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.4rem;
    min-height:46px;
    padding:.38rem .5rem;
    background:#fff;
    border:1px solid #ced4da;
    border-radius:.45rem;
    transition:border-color .15s ease, box-shadow .15s ease;
}

.feedbackdashboard-course-picker-box:focus-within {
    border-color:var(--bs-primary, #0f6cbf);
    box-shadow:0 0 0 .18rem rgba(15,108,191,.15);
}

.feedbackdashboard-selected-courses {
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.35rem;
}

.feedbackdashboard-course-tag {
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    max-width:100%;
    padding:.28rem .42rem .28rem .58rem;
    border:1px solid #cbd5e1;
    border-radius:.38rem;
    background:#f1f5f9;
    color:#263746;
    font-size:.82rem;
    font-weight:600;
    line-height:1.25;
}

.feedbackdashboard-course-tag-label {
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    max-width:360px;
}

.feedbackdashboard-course-tag-remove {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:20px;
    height:20px;
    padding:0;
    border:0;
    border-radius:50%;
    background:transparent;
    color:#263746;
    font-size:1rem;
    font-weight:700;
    line-height:1;
    cursor:pointer;
}

.feedbackdashboard-course-tag-remove:hover,
.feedbackdashboard-course-tag-remove:focus {
    background:#e2e8f0;
    outline:0;
}

.feedbackdashboard-course-search-input {
    flex:1 1 280px;
    min-width:220px;
    height:32px;
    padding:.2rem .25rem;
    border:0 !important;
    outline:0 !important;
    box-shadow:none !important;
    background:transparent;
}

.feedbackdashboard-course-suggestions {
    position:absolute;
    z-index:1050;
    top:calc(100% + .3rem);
    left:0;
    right:0;
    max-height:300px;
    overflow-y:auto;
    padding:.3rem;
    background:#fff;
    border:1px solid #ced4da;
    border-radius:.45rem;
    box-shadow:0 .45rem 1.1rem rgba(15,23,42,.14);
}

.feedbackdashboard-course-suggestion {
    display:block;
    width:100%;
    padding:.58rem .7rem;
    border:0;
    border-radius:.3rem;
    background:#fff;
    color:#263746;
    text-align:left;
    cursor:pointer;
}

.feedbackdashboard-course-suggestion:hover,
.feedbackdashboard-course-suggestion:focus {
    background:#f1f5f9;
    outline:0;
}

.feedbackdashboard-course-suggestion-name {
    display:block;
    font-size:.88rem;
    font-weight:600;
}

.feedbackdashboard-course-picker-empty {
    padding:.7rem;
    color:#637083;
    font-size:.82rem;
}

.feedbackdashboard-course-picker-help {
    margin-top:.45rem;
    color:#637083;
    font-size:.76rem;
    line-height:1.4;
}

.feedbackdashboard-course-selection-row {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.75rem;
    margin-top:.6rem;
}

.feedbackdashboard-course-selection-live {
    color:#637083;
    font-size:.76rem;
}

@media (max-width:767.98px) {
    .feedbackdashboard-course-selection-row {
        align-items:flex-start;
        flex-direction:column;
    }
}
';

echo html_writer::tag(
    'style',
    $coursefiltercss
);

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
 * Course filter.
 * -------------------------------------------------------------------------
 */

echo html_writer::start_tag('form', [
    'id' => 'feedbackdashboard-course-filter-form',
    'method' => 'get',
    'action' => (
        new moodle_url(
            '/local/feedbackdashboard/admin.php'
        )
    )->out(false),
    'class' => 'feedbackdashboard-course-filter mb-4',
    'autocomplete' => 'off',
]);

echo html_writer::tag(
    'label',
    'Filtrar cursos',
    [
        'for' => 'feedbackdashboard-course-search',
        'class' => 'form-label fw-semibold mb-2',
    ]
);

/*
 * Search box and selected course tags.
 */
echo html_writer::start_div(
    'feedbackdashboard-course-picker',
    [
        'id' => 'feedbackdashboard-course-picker',
    ]
);

echo html_writer::start_div(
    'feedbackdashboard-course-picker-box'
);

echo html_writer::start_div(
    'feedbackdashboard-selected-courses',
    [
        'id' => 'feedbackdashboard-selected-courses',
    ]
);

/*
 * Selected courses displayed as tags.
 */
foreach ($selectedcourseids as $selectedcourseid) {

    if (!isset($courses[$selectedcourseid])) {
        continue;
    }

    $selectedcourse = $courses[$selectedcourseid];

    echo html_writer::start_tag(
        'span',
        [
            'class' =>
                'feedbackdashboard-course-tag',

            'data-course-id' =>
                $selectedcourseid,
        ]
    );

    echo html_writer::span(
        s(format_string($selectedcourse['name'])),
        'feedbackdashboard-course-tag-label'
    );

    echo html_writer::tag(
        'button',
        '×',
        [
            'type' => 'button',

            'class' =>
                'feedbackdashboard-course-tag-remove',

            'data-course-id' =>
                $selectedcourseid,

            'title' =>
                'Remover curso',

            'aria-label' =>
                'Remover '
                . format_string(
                    $selectedcourse['name']
                ),
        ]
    );

    echo html_writer::end_tag('span');
}

echo html_writer::end_div();

/*
 * Course search input.
 */
echo html_writer::empty_tag(
    'input',
    [
        'type' => 'search',

        'id' =>
            'feedbackdashboard-course-search',

        'class' =>
            'feedbackdashboard-course-search-input',

        'placeholder' =>
            empty($selectedcourseids)
                ? 'Digite ou escolha um curso...'
                : 'Adicionar outro curso...',

        'aria-autocomplete' => 'list',
        'aria-expanded' => 'false',

        'aria-controls' =>
            'feedbackdashboard-course-suggestions',
    ]
);

echo html_writer::end_div();

/*
 * Hidden inputs submitted to PHP.
 */
echo html_writer::start_div(
    '',
    [
        'id' =>
            'feedbackdashboard-selected-course-inputs',
    ]
);

foreach ($selectedcourseids as $selectedcourseid) {

    if (!isset($courses[$selectedcourseid])) {
        continue;
    }

    echo html_writer::empty_tag(
        'input',
        [
            'type' => 'hidden',
            'name' => 'courses[]',
            'value' => $selectedcourseid,

            'data-course-id' =>
                $selectedcourseid,
        ]
    );
}

echo html_writer::end_div();

/*
 * Dropdown course options.
 */
echo html_writer::start_div(
    'feedbackdashboard-course-suggestions',
    [
        'id' =>
            'feedbackdashboard-course-suggestions',

        'role' => 'listbox',
        'hidden' => 'hidden',
    ]
);

foreach ($courses as $availablecourse) {

    $coursename =
        format_string(
            $availablecourse['name']
        );

    echo html_writer::tag(
        'button',

        html_writer::span(
            s($coursename),
            'feedbackdashboard-course-suggestion-name'
        ),

        [
            'type' => 'button',

            'class' =>
                'feedbackdashboard-course-suggestion',

            'data-course-id' =>
                $availablecourse['id'],

            'data-course-name' =>
                $coursename,

            'role' => 'option',
        ]
    );
}

echo html_writer::div(
    'Nenhum curso encontrado.',
    'feedbackdashboard-course-picker-empty',
    [
        'id' =>
            'feedbackdashboard-no-course-suggestion',

        'hidden' => 'hidden',
    ]
);

echo html_writer::end_div();
echo html_writer::end_div();

/*
 * Current selection status.
 */
echo html_writer::start_div(
    'feedbackdashboard-course-selection-row'
);

echo html_writer::tag(
    'div',

    empty($selectedcourseids)
        ? 'Todos os cursos com NPS serão considerados.'
        : (
            count($selectedcourseids) === 1
                ? '1 curso selecionado.'
                : count($selectedcourseids)
                    . ' cursos selecionados.'
        ),

    [
        'id' =>
            'feedbackdashboard-course-selection-live',

        'class' =>
            'feedbackdashboard-course-selection-live',

        'role' => 'status',
        'aria-live' => 'polite',
    ]
);

$clearcourseattributes = [
    'type' => 'button',

    'id' =>
        'feedbackdashboard-clear-selected-courses',

    'class' =>
        'btn btn-sm btn-outline-secondary',
];

if (empty($selectedcourseids)) {
    $clearcourseattributes['hidden'] = 'hidden';
}

echo html_writer::tag(
    'button',
    'Limpar seleção',
    $clearcourseattributes
);

echo html_writer::end_div();

echo html_writer::tag(
    'div',
    'Clique na caixa para visualizar os cursos disponíveis '
        . 'ou digite parte do nome. Você pode selecionar '
        . 'um ou vários cursos.',
    [
        'class' =>
            'feedbackdashboard-course-picker-help',
    ]
);

/*
 * Filter actions.
 */
echo html_writer::start_div(
    'd-flex align-items-center flex-wrap gap-2 mt-3'
);

echo html_writer::tag(
    'button',
    'Aplicar filtro',
    [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]
);

if (!empty($selectedcourseids)) {

    echo html_writer::link(
        new moodle_url(
            '/local/feedbackdashboard/admin.php'
        ),

        'Limpar filtro',

        [
            'class' => 'btn btn-secondary',
        ]
    );
}

echo html_writer::end_div();

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
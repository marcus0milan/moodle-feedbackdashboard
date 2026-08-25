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

$selectedactivityids = optional_param_array('activities', [], PARAM_INT);
$selectedactivityids = array_values(
    array_unique(
        array_map('intval', $selectedactivityids)
    )
);

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

$availableactivities = [];

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

if (!$summary['hasnps']) {
    continue;
}

/*
 * Activity available in the course filter.
 */
$activityid = (int) $record->cmid;

$availableactivities[$activityid] = [
    'id' => $activityid,
    'name' => format_string($record->feedbackname),
];

/*
 * When activities are selected, only those activities contribute
 * to the table and course indicators.
 */
if (
    !empty($selectedactivityids)
    && !in_array($activityid, $selectedactivityids, true)
) {
    continue;
}

    $totalsurveys++;
    $totalresponses += $summary['totalresponses'];
    $totalvalidresponses += $summary['validresponses'];
    $totalpromoters += $summary['promoters'];
    $totalpassives += $summary['passives'];
    $totaldetractors += $summary['detractors'];
    $surveyswithnps++;

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

    if ($summary['validresponses'] > 0) {
        $npsvalue = format_float($summary['nps'], 0);
        $npsclass = $summary['nps'] >= 50
            ? 'feedbackdashboard-admin-badge-good'
            : ($summary['nps'] >= 0
                ? 'feedbackdashboard-admin-badge-neutral'
                : 'feedbackdashboard-admin-badge-bad');

        $npsdisplay = html_writer::span($npsvalue, 'feedbackdashboard-admin-badge ' . $npsclass);
    } else {
    $npsdisplay = html_writer::span('—', 'text-muted');
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

$coursenps = $totalvalidresponses > 0
    ? (($totalpromoters - $totaldetractors) / $totalvalidresponses) * 100
    : null;

$activityfilterjavascript = <<<'JS'
(function() {
    'use strict';

    const form = document.getElementById(
        'feedbackdashboard-activity-filter-form'
    );

    if (!form) {
        return;
    }

    const picker = document.getElementById(
        'feedbackdashboard-activity-picker'
    );

    const searchInput = document.getElementById(
        'feedbackdashboard-activity-search'
    );

    const tagsContainer = document.getElementById(
        'feedbackdashboard-selected-activities'
    );

    const hiddenInputs = document.getElementById(
        'feedbackdashboard-selected-activity-inputs'
    );

    const suggestions = document.getElementById(
        'feedbackdashboard-activity-suggestions'
    );

    const emptySuggestion = document.getElementById(
        'feedbackdashboard-no-activity-suggestion'
    );

    const clearButton = document.getElementById(
        'feedbackdashboard-clear-selected-activities'
    );

    const selectionLive = document.getElementById(
        'feedbackdashboard-activity-selection-live'
    );

    const suggestionButtons = Array.from(
        form.querySelectorAll(
            '.feedbackdashboard-activity-suggestion'
        )
    );

    const selected = new Set();

    if (tagsContainer) {
        tagsContainer
            .querySelectorAll(
                '.feedbackdashboard-activity-tag'
            )
            .forEach(function(tag) {
                const activityid =
                    String(tag.dataset.activityId || '');

                if (activityid !== '') {
                    selected.add(activityid);
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
                    'Todas as atividades com NPS serão consideradas.';
            } else if (selected.size === 1) {
                selectionLive.textContent =
                    '1 atividade selecionada.';
            } else {
                selectionLive.textContent =
                    selected.size + ' atividades selecionadas.';
            }
        }

        if (searchInput) {
            searchInput.placeholder =
                selected.size === 0
                    ? 'Digite ou escolha uma atividade...'
                    : 'Adicionar outra atividade...';
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
            const activityid =
                String(button.dataset.activityId || '');

            const activityname =
                normalise(
                    button.dataset.activityName || ''
                );

            const matchesText =
                term === ''
                || activityname.includes(term);

            const matches =
                matchesText
                && !selected.has(activityid);

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

    const createHiddenInput = function(activityid) {
        if (!hiddenInputs) {
            return;
        }

        const input = document.createElement('input');

        input.type = 'hidden';
        input.name = 'activities[]';
        input.value = activityid;
        input.dataset.activityId = activityid;

        hiddenInputs.appendChild(input);
    };

    const removeHiddenInput = function(activityid) {
        if (!hiddenInputs) {
            return;
        }

        const inputs = Array.from(
            hiddenInputs.querySelectorAll(
                'input[name="activities[]"]'
            )
        );

        const input = inputs.find(function(current) {
            return String(
                current.dataset.activityId
                || current.value
            ) === activityid;
        });

        if (input) {
            input.remove();
        }
    };

    const createTag = function(
        activityid,
        activityname
    ) {
        if (!tagsContainer) {
            return;
        }

        const tag = document.createElement('span');

        tag.className =
            'feedbackdashboard-activity-tag';

        tag.dataset.activityId = activityid;

        const label =
            document.createElement('span');

        label.className =
            'feedbackdashboard-activity-tag-label';

        label.textContent = activityname;

        const remove =
            document.createElement('button');

        remove.type = 'button';

        remove.className =
            'feedbackdashboard-activity-tag-remove';

        remove.dataset.activityId = activityid;

        remove.title = 'Remover atividade';

        remove.setAttribute(
            'aria-label',
            'Remover ' + activityname
        );

        remove.textContent = '×';

        tag.appendChild(label);
        tag.appendChild(remove);

        tagsContainer.appendChild(tag);
    };

    const addActivity = function(
        activityid,
        activityname
    ) {
        activityid = String(activityid || '');

        if (
            activityid === ''
            || selected.has(activityid)
        ) {
            return;
        }

        selected.add(activityid);

        createTag(
            activityid,
            activityname
        );

        createHiddenInput(activityid);

        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }

        updateSelectionState();
        updateSuggestions();
    };

    const removeActivity = function(activityid) {
        activityid = String(activityid || '');

        if (
            activityid === ''
            || !selected.has(activityid)
        ) {
            return;
        }

        selected.delete(activityid);

        if (tagsContainer) {
            const tags = Array.from(
                tagsContainer.querySelectorAll(
                    '.feedbackdashboard-activity-tag'
                )
            );

            const tag = tags.find(function(current) {
                return String(
                    current.dataset.activityId || ''
                ) === activityid;
            });

            if (tag) {
                tag.remove();
            }
        }

        removeHiddenInput(activityid);

        updateSelectionState();

        if (searchInput) {
            searchInput.focus();
        }

        updateSuggestions();
    };

    const clearActivities = function() {
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
                addActivity(
                    String(
                        button.dataset.activityId || ''
                    ),
                    String(
                        button.dataset.activityName || ''
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
                        '.feedbackdashboard-activity-tag-remove'
                    );

                if (!removeButton) {
                    return;
                }

                removeActivity(
                    String(
                        removeButton.dataset.activityId
                        || ''
                    )
                );
            }
        );
    }

    if (clearButton) {
        clearButton.addEventListener(
            'click',
            clearActivities
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
                            '.feedbackdashboard-activity-tag'
                        );

                    const lastTag =
                        tags.length > 0
                            ? tags[tags.length - 1]
                            : null;

                    if (lastTag) {
                        removeActivity(
                            String(
                                lastTag.dataset.activityId
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
    $activityfilterjavascript
);

echo $OUTPUT->header();

echo $OUTPUT->heading(
    'Dashboard NPS - ' . format_string($course->fullname)
);
echo html_writer::tag(
    'p',
    'Visão geral das pesquisas NPS deste curso.',
    ['class' => 'text-muted mb-4']
);

// Search form (Pesquisar)
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

echo html_writer::empty_tag('input', [
    'type' => 'search',
    'id' => 'feedbackdashboard-admin-search',
    'name' => 'search',
    'value' => $search,
    'class' => 'form-control',
    'placeholder' => get_string('searchfeedbacks', 'local_feedbackdashboard'),
]);
echo html_writer::tag('button', get_string('search', 'local_feedbackdashboard'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
if ($search !== '') {
    echo html_writer::link(
        new moodle_url('/local/feedbackdashboard/course.php', [
    'id' => $courseid,
]),
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
        'label' => get_string('coursenps', 'local_feedbackdashboard'),
        'value' => $coursenps === null ? '—' : format_float($coursenps, 0) . '%',
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

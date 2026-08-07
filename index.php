<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// any later version.

/**
 * Main page for the Feedback Dashboard plugin.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');

/**
 * Converts an alternative stored by Feedback into plain text.
 *
 * @param string $text Raw option text.
 * @return string
 */
function local_feedbackdashboard_clean_option_text(string $text): string {
    $formattedtext = format_text(
        $text,
        FORMAT_HTML,
        [
            'noclean' => false,
            'para' => false,
        ]
    );

    $plaintext = html_to_text($formattedtext, 0, false);
    $plaintext = preg_replace('/\s+/u', ' ', $plaintext);

    return trim((string) $plaintext);
}

/**
 * Returns the primary colour configured in the current Moodle theme.
 *
 * @return string Valid hexadecimal colour.
 */
function local_feedbackdashboard_get_theme_primary_color(): string {
    global $PAGE;

    // Default Moodle/Boost primary colour.
    $defaultcolor = '#0f6cbf';

    /*
     * Most Boost-based themes store their principal colour in the
     * "brandcolor" setting.
     */
    $themecolor = $PAGE->theme->settings->brandcolor ?? null;

    if (
        is_string($themecolor) &&
        preg_match(
            '/^#[0-9a-fA-F]{6}$/',
            trim($themecolor)
        )
    ) {
        return trim($themecolor);
    }

    return $defaultcolor;
}

/**
 * Loads the values belonging to one question.
 *
 * When participants are selected, only identified responses belonging
 * to those participants are returned.
 *
 * @param stdClass $item Feedback question.
 * @param int $feedbackid Feedback instance ID.
 * @param array $selecteduserids Selected user IDs.
 * @param bool $isanonymous Whether the Feedback is anonymous.
 * @return array
 */
function local_feedbackdashboard_get_question_values(
    stdClass $item,
    int $feedbackid,
    array $selecteduserids,
    bool $isanonymous
): array {
    global $DB;

    $params = [
        'itemid' => $item->id,
        'feedbackid' => $feedbackid,
    ];

    $sql = "
        SELECT
            fv.id,
            fv.value,
            fbc.userid,
            fbc.id AS completedid
        FROM {feedback_value} fv
        JOIN {feedback_completed} fbc
            ON fbc.id = fv.completed
        WHERE fv.item = :itemid
          AND fbc.feedback = :feedbackid
    ";

    /*
     * Never associate an anonymous response with a participant.
     *
     * The user filter is applied only to identified responses.
     */
    if (!$isanonymous && !empty($selecteduserids)) {
        [$usersql, $userparams] = $DB->get_in_or_equal(
            $selecteduserids,
            SQL_PARAMS_NAMED,
            'selecteduser'
        );

        $sql .= "
            AND fbc.userid {$usersql}
            AND fbc.anonymous_response = :identifiedresponse
        ";

        $params += $userparams;
        $params['identifiedresponse'] = FEEDBACK_ANONYMOUS_NO;
    }

    $sql .= ' ORDER BY fbc.timemodified, fv.id';

    return $DB->get_records_sql($sql, $params);
}

/**
 * Creates chart data for a multiple-choice question.
 *
 * Supports:
 * - multichoice;
 * - multichoicerated.
 *
 * @param stdClass $item Feedback question.
 * @param array $values Question response records.
 * @return array|null
 */
function local_feedbackdashboard_build_choice_chart_data(
    stdClass $item,
    array $values
): ?array {
    if (!in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
        return null;
    }

    $itemobject = feedback_get_item_class($item->typ);

    if (!$itemobject) {
        return null;
    }

    $info = $itemobject->get_info($item);

    $labels = [];
    $counts = [];
    $ismultiplecheckbox = false;

    /*
     * Normal multiple-choice question.
     */
    if ($item->typ === 'multichoice') {
        $rawoptions = explode(
            FEEDBACK_MULTICHOICE_LINE_SEP,
            $info->presentation
        );

        $ismultiplecheckbox = ($info->subtype === 'c');

        foreach ($rawoptions as $index => $rawoption) {
            $optionnumber = $index + 1;
            $optiontext = local_feedbackdashboard_clean_option_text(
                $rawoption
            );

            if ($optiontext === '') {
                $optiontext = 'Alternativa ' . $optionnumber;
            }

            $labels[$optionnumber] = $optiontext;
            $counts[$optionnumber] = 0;
        }
    }

    /*
     * Rated multiple-choice question.
     *
     * Moodle stores each option approximately as:
     *
     * weight####option text
     */
    if ($item->typ === 'multichoicerated') {
        $rawoptions = explode(
            FEEDBACK_MULTICHOICERATED_LINE_SEP,
            $info->presentation
        );

        foreach ($rawoptions as $index => $rawoption) {
            $optionnumber = $index + 1;

            $parts = explode(
                FEEDBACK_MULTICHOICERATED_VALUE_SEP,
                $rawoption,
                2
            );

            $weight = trim((string) ($parts[0] ?? ''));
            $rawtext = (string) ($parts[1] ?? $parts[0] ?? '');

            $optiontext = local_feedbackdashboard_clean_option_text(
                $rawtext
            );

            if ($optiontext === '') {
                $optiontext = 'Alternativa ' . $optionnumber;
            }

            /*
             * Avoid displaying duplicated labels such as "(1) 1".
             */
            if (
                $weight !== '' &&
                trim($optiontext) !== $weight
            ) {
                $optiontext = '(' . $weight . ') ' . $optiontext;
            }

            $labels[$optionnumber] = $optiontext;
            $counts[$optionnumber] = 0;
        }
    }

    if (empty($labels)) {
        return null;
    }

    $responseswithvalue = 0;

    foreach ($values as $valuerecord) {
        $storedvalue = trim((string) $valuerecord->value);

        if ($storedvalue === '' || $storedvalue === '0') {
            continue;
        }

        $responseswithvalue++;

        /*
         * Checkbox questions can contain multiple indexes separated by |.
         * Radio and select questions contain only one index.
         */
        if ($ismultiplecheckbox) {
            $selectedoptions = explode(
                FEEDBACK_MULTICHOICE_LINE_SEP,
                $storedvalue
            );
        } else {
            $selectedoptions = [$storedvalue];
        }

        foreach ($selectedoptions as $selectedoption) {
            $selectedindex = (int) trim((string) $selectedoption);

            if (array_key_exists($selectedindex, $counts)) {
                $counts[$selectedindex]++;
            }
        }
    }

    $serieslabels = [];
    $percentages = [];

    foreach ($counts as $optionnumber => $count) {
        $percentage = 0.0;

        if ($responseswithvalue > 0) {
            $percentage = ($count / $responseswithvalue) * 100;
        }

        $percentages[$optionnumber] = $percentage;

        $serieslabels[] = sprintf(
            '%d (%s%%)',
            $count,
            format_float($percentage, 1)
        );
    }

    return [
        'labels' => array_values($labels),
        'counts' => array_values($counts),
        'serieslabels' => $serieslabels,
        'percentages' => array_values($percentages),
        'responseswithvalue' => $responseswithvalue,
        'ismultiplecheckbox' => $ismultiplecheckbox,
    ];
}

/*
 * -------------------------------------------------------------------------
 * Page parameters and permissions.
 * -------------------------------------------------------------------------
 */

$id = required_param('id', PARAM_INT);

$selecteduserids = optional_param_array(
    'users',
    [],
    PARAM_INT
);

$selecteduserids = array_values(
    array_unique(
        array_map('intval', $selecteduserids)
    )
);

[$course, $cm] = get_course_and_cm_from_cmid(
    $id,
    'feedback'
);

require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);

/*
 * The user must have permission for the plugin and for the native
 * Feedback reports.
 */
require_capability(
    'local/feedbackdashboard:view',
    $context
);

require_capability(
    'mod/feedback:viewreports',
    $context
);

$feedback = $DB->get_record(
    'feedback',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);

$isanonymous = (
    (int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES
);

/*
 * -------------------------------------------------------------------------
 * Page configuration.
 * -------------------------------------------------------------------------
 */

$pageurl = new moodle_url(
    '/local/feedbackdashboard/index.php',
    ['id' => $cm->id]
);

$PAGE->set_url($pageurl);
$PAGE->set_course($course);
$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

$PAGE->set_title(
    get_string(
        'dashboardtitle',
        'local_feedbackdashboard'
    ) . ': ' . format_string($feedback->name)
);

$PAGE->set_heading(
    format_string($course->fullname)
);

$themeprimarycolor =
    local_feedbackdashboard_get_theme_primary_color();

/*
 * -------------------------------------------------------------------------
 * Load questions.
 * -------------------------------------------------------------------------
 */

$items = $DB->get_records_select(
    'feedback_item',
    'feedback = :feedbackid AND hasvalue = :hasvalue',
    [
        'feedbackid' => $feedback->id,
        'hasvalue' => 1,
    ],
    'position ASC'
);

$questionscount = count($items);

/*
 * -------------------------------------------------------------------------
 * Load identified participants who submitted the Feedback.
 * -------------------------------------------------------------------------
 */

$responders = [];

if (!$isanonymous) {
    $responderssql = "
        SELECT DISTINCT
            u.id,
            u.firstname,
            u.lastname,
            u.firstnamephonetic,
            u.lastnamephonetic,
            u.middlename,
            u.alternatename,
            u.email
        FROM {feedback_completed} fbc
        JOIN {user} u
            ON u.id = fbc.userid
        WHERE fbc.feedback = :feedbackid
          AND fbc.userid > 0
          AND fbc.anonymous_response = :identifiedresponse
          AND u.deleted = 0
        ORDER BY u.firstname, u.lastname, u.id
    ";

    $responders = $DB->get_records_sql(
        $responderssql,
        [
            'feedbackid' => $feedback->id,
            'identifiedresponse' => FEEDBACK_ANONYMOUS_NO,
        ]
    );

    /*
     * Do not accept IDs manually inserted into the URL.
     * Only actual Feedback responders are accepted.
     */
    $validresponderids = array_map(
        'intval',
        array_keys($responders)
    );

    $selecteduserids = array_values(
        array_intersect(
            $selecteduserids,
            $validresponderids
        )
    );
} else {
    /*
     * An anonymous Feedback can never use the participant filter.
     */
    $selecteduserids = [];
}

/*
 * -------------------------------------------------------------------------
 * Response counters.
 * -------------------------------------------------------------------------
 */

$totalresponsecount = $DB->count_records(
    'feedback_completed',
    ['feedback' => $feedback->id]
);

$filteredresponsecount = $totalresponsecount;

if (!$isanonymous && !empty($selecteduserids)) {
    [$usersql, $userparams] = $DB->get_in_or_equal(
        $selecteduserids,
        SQL_PARAMS_NAMED,
        'countuser'
    );

    $countparams = [
        'feedbackid' => $feedback->id,
        'identifiedresponse' => FEEDBACK_ANONYMOUS_NO,
    ];

    $countparams += $userparams;

    $filteredresponsecount = $DB->count_records_select(
        'feedback_completed',
        "
            feedback = :feedbackid
            AND userid {$usersql}
            AND anonymous_response = :identifiedresponse
        ",
        $countparams
    );
}

/*
 * -------------------------------------------------------------------------
 * Automatic participant filter.
 * -------------------------------------------------------------------------
 */

$filterjavascript = <<<'JS'
(function() {
    'use strict';

    const form = document.getElementById(
        'feedbackdashboard-filter-form'
    );

    if (!form) {
        return;
    }

    const allCheckbox = document.getElementById(
        'feedbackdashboard-filter-all'
    );

    const userCheckboxes = Array.from(
        form.querySelectorAll(
            '.feedbackdashboard-user-checkbox'
        )
    );

    const searchInput = document.getElementById(
        'feedbackdashboard-student-search'
    );

    const studentOptions = Array.from(
        form.querySelectorAll(
            '.feedbackdashboard-student-option'
        )
    );

    let submitTimer = null;

    const scheduleSubmit = () => {
        window.clearTimeout(submitTimer);

        /*
         * The delay allows the teacher to select two or more students
         * before the page reloads.
         */
        submitTimer = window.setTimeout(() => {
            form.submit();
        }, 900);
    };

    if (allCheckbox) {
        allCheckbox.addEventListener('change', () => {
            if (allCheckbox.checked) {
                userCheckboxes.forEach((checkbox) => {
                    checkbox.checked = false;
                });
            } else {
                const hasSelectedUser = userCheckboxes.some(
                    (checkbox) => checkbox.checked
                );

                if (!hasSelectedUser) {
                    allCheckbox.checked = true;
                }
            }

            scheduleSubmit();
        });
    }

    userCheckboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const hasSelectedUser = userCheckboxes.some(
                (currentCheckbox) => currentCheckbox.checked
            );

            if (allCheckbox) {
                allCheckbox.checked = !hasSelectedUser;
            }

            scheduleSubmit();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const searchText = searchInput.value
                .trim()
                .toLocaleLowerCase();

            studentOptions.forEach((option) => {
                const studentName = (
                    option.dataset.studentName || ''
                ).toLocaleLowerCase();

                option.hidden = (
                    searchText !== '' &&
                    !studentName.includes(searchText)
                );
            });
        });
    }
})();
JS;

$PAGE->requires->js_init_code($filterjavascript);

/*
 * -------------------------------------------------------------------------
 * Page output.
 * -------------------------------------------------------------------------
 */

echo $OUTPUT->header();

/*
 * Header and PDF button.
 */
echo html_writer::start_div(
    'd-flex justify-content-between align-items-center '
    . 'flex-wrap gap-3 mb-3'
);

echo html_writer::start_div();

echo $OUTPUT->heading(
    get_string(
        'dashboardtitle',
        'local_feedbackdashboard'
    ),
    2
);

echo $OUTPUT->heading(
    format_string($feedback->name),
    3
);

echo html_writer::end_div();

/*
 * PDF download URL.
 *
 * The currently selected participants are sent to download.php,
 * ensuring that the PDF uses exactly the same Dashboard filter.
 */
$pdfparams = [
    'id' => $cm->id,
];

if (!$isanonymous && !empty($selecteduserids)) {
    $pdfparams['userids'] = implode(',', $selecteduserids);
}

$pdfurl = new moodle_url(
    '/local/feedbackdashboard/download.php',
    $pdfparams
);

$pdfbuttoncontent = $OUTPUT->pix_icon(
    't/download',
    ''
) . ' Baixar relatório em PDF';

echo html_writer::link(
    $pdfurl,
    $pdfbuttoncontent,
    [
        'class' => 'btn btn-outline-primary',
        'title' => 'Baixar relatório em PDF',
    ]
);

echo html_writer::end_div();

/*
 * Feedback privacy status.
 */
if ($isanonymous) {
    echo $OUTPUT->notification(
        'Esta pesquisa é anônima. Os resultados são apresentados '
        . 'de forma geral e não podem ser filtrados pelo nome dos alunos.',
        'warning'
    );
} else {
    echo $OUTPUT->notification(
        'Pesquisa identificada. Utilize o filtro para visualizar todos '
        . 'os alunos ou participantes específicos.',
        'info'
    );
}

/*
 * Summary cards.
 */
echo html_writer::start_div('row g-3 mb-4');

echo html_writer::start_div('col-12 col-md-4');
echo html_writer::start_div('card h-100');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'div',
    'Respostas totais',
    ['class' => 'text-muted mb-1']
);
echo html_writer::tag(
    'div',
    (string) $totalresponsecount,
    ['class' => 'h3 mb-0']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-md-4');
echo html_writer::start_div('card h-100');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'div',
    'Respostas consideradas',
    ['class' => 'text-muted mb-1']
);
echo html_writer::tag(
    'div',
    (string) $filteredresponsecount,
    ['class' => 'h3 mb-0']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-12 col-md-4');
echo html_writer::start_div('card h-100');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'div',
    'Questões',
    ['class' => 'text-muted mb-1']
);
echo html_writer::tag(
    'div',
    (string) $questionscount,
    ['class' => 'h3 mb-0']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

/*
 * Participant filter.
 */
if (!$isanonymous) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');

    echo $OUTPUT->heading(
        'Filtrar participantes',
        3,
        'h4 card-title'
    );

    echo html_writer::tag(
        'p',
        'Selecione um ou mais alunos. Os gráficos serão recalculados '
        . 'automaticamente de acordo com a seleção.',
        ['class' => 'text-muted']
    );

    if (empty($responders)) {
        echo $OUTPUT->notification(
            'Ainda não há alunos identificados que responderam '
            . 'esta pesquisa.',
            'info'
        );
    } else {
        $formaction = new moodle_url(
            '/local/feedbackdashboard/index.php'
        );

        echo html_writer::start_tag(
            'form',
            [
                'id' => 'feedbackdashboard-filter-form',
                'method' => 'get',
                'action' => $formaction->out(false),
                'autocomplete' => 'off',
            ]
        );

        echo html_writer::empty_tag(
            'input',
            [
                'type' => 'hidden',
                'name' => 'id',
                'value' => $cm->id,
            ]
        );

        /*
         * Search inside the participant selector.
         */
        echo html_writer::start_div('mb-3');

        echo html_writer::tag(
            'label',
            'Pesquisar aluno',
            [
                'for' => 'feedbackdashboard-student-search',
                'class' => 'form-label',
            ]
        );

        echo html_writer::empty_tag(
            'input',
            [
                'type' => 'search',
                'id' => 'feedbackdashboard-student-search',
                'class' => 'form-control',
                'placeholder' => 'Digite o nome do aluno...',
            ]
        );

        echo html_writer::end_div();

        /*
         * "All students" option.
         */
        $allchecked = empty($selecteduserids);

        $allattributes = [
            'type' => 'checkbox',
            'id' => 'feedbackdashboard-filter-all',
            'class' => 'form-check-input',
            'value' => '1',
        ];

        if ($allchecked) {
            $allattributes['checked'] = 'checked';
        }

        echo html_writer::start_div(
            'border rounded p-3 mb-3'
        );

        echo html_writer::start_div(
            'form-check border-bottom pb-2 mb-2'
        );

        echo html_writer::empty_tag(
            'input',
            $allattributes
        );

        echo html_writer::tag(
            'label',
            'Todos os alunos',
            [
                'for' => 'feedbackdashboard-filter-all',
                'class' => 'form-check-label fw-bold',
            ]
        );

        echo html_writer::end_div();

        /*
         * Scrollable student list.
         */
        echo html_writer::start_div(
            'overflow-auto',
            [
                'style' => 'max-height: 260px;',
            ]
        );

        foreach ($responders as $responder) {
            $responderid = (int) $responder->id;
            $respondername = fullname($responder);
            $checkboxid = 'feedbackdashboard-user-' . $responderid;

            $checkboxattributes = [
                'type' => 'checkbox',
                'id' => $checkboxid,
                'name' => 'users[]',
                'value' => $responderid,
                'class' => 'form-check-input '
                    . 'feedbackdashboard-user-checkbox',
            ];

            if (
                in_array(
                    $responderid,
                    $selecteduserids,
                    true
                )
            ) {
                $checkboxattributes['checked'] = 'checked';
            }

            echo html_writer::start_div(
                'form-check py-1 '
                . 'feedbackdashboard-student-option',
                [
                    'data-student-name' => core_text::strtolower(
                        $respondername
                    ),
                ]
            );

            echo html_writer::empty_tag(
                'input',
                $checkboxattributes
            );

            echo html_writer::tag(
                'label',
                s($respondername),
                [
                    'for' => $checkboxid,
                    'class' => 'form-check-label',
                ]
            );

            echo html_writer::end_div();
        }

        echo html_writer::end_div();
        echo html_writer::end_div();

        /*
         * Fallback buttons in case JavaScript is unavailable.
         */
        echo html_writer::start_div(
            'd-flex align-items-center flex-wrap gap-2'
        );

        echo html_writer::tag(
            'button',
            'Aplicar filtro',
            [
                'type' => 'submit',
                'class' => 'btn btn-primary',
            ]
        );

        echo html_writer::link(
            $pageurl,
            'Limpar filtro',
            [
                'class' => 'btn btn-secondary',
            ]
        );

        if (empty($selecteduserids)) {
            $selectionmessage = 'Exibindo todos os alunos.';
        } else {
            $selectedcount = count($selecteduserids);

            $selectionmessage = $selectedcount === 1
                ? '1 aluno selecionado.'
                : $selectedcount . ' alunos selecionados.';
        }

        echo html_writer::tag(
            'span',
            $selectionmessage,
            ['class' => 'text-muted ms-2']
        );

        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

/*
 * Empty Feedback states.
 */
if ($questionscount === 0) {
    echo $OUTPUT->notification(
        'Esta pesquisa ainda não possui questões.',
        'info'
    );

    echo $OUTPUT->footer();
    exit;
}

if ($filteredresponsecount === 0) {
    echo $OUTPUT->notification(
        empty($selecteduserids)
            ? 'Esta pesquisa ainda não recebeu respostas.'
            : 'Nenhuma resposta foi encontrada para os alunos selecionados.',
        'info'
    );
}

/*
 * -------------------------------------------------------------------------
 * Multiple-choice charts.
 * -------------------------------------------------------------------------
 */

echo $OUTPUT->heading(
    'Resultados das perguntas de alternativa',
    2
);

$renderedcharts = 0;
$questionnumber = 0;

foreach ($items as $item) {
    $questionnumber++;

    if (
        !in_array(
            $item->typ,
            ['multichoice', 'multichoicerated'],
            true
        )
    ) {
        continue;
    }

    $questionvalues = local_feedbackdashboard_get_question_values(
        $item,
        (int) $feedback->id,
        $selecteduserids,
        $isanonymous
    );

    $chartdata = local_feedbackdashboard_build_choice_chart_data(
        $item,
        $questionvalues
    );

    if ($chartdata === null) {
        continue;
    }

    $renderedcharts++;

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');

    $questiontitle = $questionnumber
        . '. '
        . format_string($item->name);

    echo $OUTPUT->heading(
        $questiontitle,
        3,
        'h4 card-title'
    );

    if (!empty($item->label)) {
        echo html_writer::tag(
            'div',
            format_string($item->label),
            ['class' => 'text-muted mb-2']
        );
    }

    $answerdescription = $chartdata['responseswithvalue'] === 1
        ? '1 resposta considerada nesta pergunta.'
        : $chartdata['responseswithvalue']
            . ' respostas consideradas nesta pergunta.';

    echo html_writer::tag(
        'p',
        $answerdescription,
        ['class' => 'text-muted']
    );

    if ($chartdata['ismultiplecheckbox']) {
        echo html_writer::tag(
            'p',
            'Esta pergunta permite selecionar mais de uma alternativa.',
            ['class' => 'small text-muted']
        );
    }

    /*
     * Moodle native vertical bar chart.
     */
    $chart = new \core\chart_bar();

    $chart->set_horizontal(false);
    $chart->set_labels($chartdata['labels']);

    $chart->set_legend_options([
        'display' => false,
    ]);

    $series = new \core\chart_series(
       'Quantidade de respostas',
       $chartdata['counts']
    );

    $series->set_labels(
       $chartdata['serieslabels']
   );

   // Use the primary colour configured in the current Moodle theme.
   $series->set_color($themeprimarycolor);

    $chart->add_series($series);

    echo $OUTPUT->render($chart);

    /*
     * Simple table below the chart.
     */
    $resultstable = new html_table();

    $resultstable->attributes = [
        'class' => 'generaltable mt-3',
    ];

    $resultstable->head = [
        'Alternativa',
        'Respostas',
        'Percentual',
    ];

    foreach ($chartdata['labels'] as $index => $label) {
        $resultstable->data[] = [
            s($label),
            (string) $chartdata['counts'][$index],
            format_float(
                $chartdata['percentages'][$index],
                1
            ) . '%',
        ];
    }

    echo html_writer::table($resultstable);

    echo html_writer::end_div();
    echo html_writer::end_div();
}

if ($renderedcharts === 0) {
    echo $OUTPUT->notification(
        'Esta pesquisa ainda não possui perguntas de alternativa '
        . 'compatíveis com o gráfico desta versão.',
        'info'
    );
}

echo $OUTPUT->footer();
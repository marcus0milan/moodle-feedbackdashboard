<?php
// This file is part of Moodle - https://moodle.org/

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
 * Converts Feedback content to compact plain text.
 *
 * @param string $text Raw text.
 * @return string
 */
function local_feedbackdashboard_clean_text(string $text): string {
    $formatted = format_text($text, FORMAT_HTML, [
        'noclean' => false,
        'para' => false,
    ]);

    $plain = html_to_text($formatted, 0, false);
    $plain = preg_replace('/\s+/u', ' ', $plain);

    return trim((string) $plain);
}

/**
 * Normalises a hexadecimal colour.
 *
 * @param mixed $color Colour candidate.
 * @param string $fallback Fallback colour.
 * @return string
 */
function local_feedbackdashboard_normalise_hex($color, string $fallback = '#0F6CBF'): string {
    if (!is_string($color)) {
        return $fallback;
    }

    $color = trim($color);

    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        return strtoupper($color);
    }

    if (preg_match('/^#[0-9a-fA-F]{3}$/', $color)) {
        return strtoupper(sprintf(
            '#%s%s%s%s%s%s',
            $color[1], $color[1],
            $color[2], $color[2],
            $color[3], $color[3]
        ));
    }

    return $fallback;
}

/**
 * Converts a hexadecimal colour to RGB.
 *
 * @param string $hex Hexadecimal colour.
 * @return array
 */
function local_feedbackdashboard_hex_to_rgb(string $hex): array {
    $hex = ltrim(local_feedbackdashboard_normalise_hex($hex), '#');

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Mixes two hexadecimal colours.
 *
 * @param string $base Base colour.
 * @param string $target Target colour.
 * @param float $weight Target colour weight from 0 to 1.
 * @return string
 */
function local_feedbackdashboard_mix_color(string $base, string $target, float $weight): string {
    $weight = max(0.0, min(1.0, $weight));
    [$br, $bg, $bb] = local_feedbackdashboard_hex_to_rgb($base);
    [$tr, $tg, $tb] = local_feedbackdashboard_hex_to_rgb($target);

    $r = (int) round($br * (1 - $weight) + $tr * $weight);
    $g = (int) round($bg * (1 - $weight) + $tg * $weight);
    $b = (int) round($bb * (1 - $weight) + $tb * $weight);

    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

/**
 * Returns the main colour configured by the current Moodle theme.
 *
 * @return string
 */
function local_feedbackdashboard_get_theme_primary_color(): string {
    global $CFG, $PAGE;

    $candidates = [];

    if (!empty($PAGE->theme->settings->brandcolor)) {
        $candidates[] = $PAGE->theme->settings->brandcolor;
    }

    $themename = $PAGE->theme->name ?? ($CFG->theme ?? '');

    if ($themename !== '') {
        $themeconfig = get_config('theme_' . $themename);

        if (is_object($themeconfig)) {
            foreach (['brandcolor', 'primarycolor', 'primarycolour', 'maincolor', 'themecolor'] as $setting) {
                if (!empty($themeconfig->{$setting})) {
                    $candidates[] = $themeconfig->{$setting};
                }
            }
        }
    }

    foreach ($candidates as $candidate) {
        $normalised = local_feedbackdashboard_normalise_hex($candidate, '');
        if ($normalised !== '') {
            return $normalised;
        }
    }

    return '#0F6CBF';
}

/**
 * Attempts to extract a NPS score from a choice label.
 *
 * @param string $label Choice label.
 * @return int|null
 */
function local_feedbackdashboard_extract_score_from_label(string $label): ?int {
    $label = local_feedbackdashboard_clean_text($label);

    if (preg_match('/^\s*\(?\s*(10|[0-9])\s*\)?\s*$/u', $label, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/^\s*\(?\s*(10|[0-9])\s*\)?\s*(?:[-–—:]|\s)/u', $label, $matches)) {
        return (int) $matches[1];
    }

    return null;
}

/**
 * Returns the options and NPS weights for a choice question.
 *
 * The stored feedback_value is the 1-based option index. For rated
 * multiple-choice questions, the score is the configured weight.
 * For normal multiple-choice questions, numeric labels such as 0..10
 * are accepted as scores.
 *
 * @param stdClass $item Feedback item.
 * @return array|null
 */
function local_feedbackdashboard_get_choice_config(stdClass $item): ?array {
    if (!in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
        return null;
    }

    $itemobject = feedback_get_item_class($item->typ);
    if (!$itemobject) {
        return null;
    }

    $info = $itemobject->get_info($item);
    $labels = [];
    $scores = [];
    $ismultiple = false;

    if ($item->typ === 'multichoice') {
        $rawoptions = explode(FEEDBACK_MULTICHOICE_LINE_SEP, $info->presentation);
        $ismultiple = ($info->subtype === 'c');

        foreach ($rawoptions as $index => $rawoption) {
            $optionindex = $index + 1;
            $label = local_feedbackdashboard_clean_text((string) $rawoption);

            if ($label === '') {
                $label = 'Alternativa ' . $optionindex;
            }

            $labels[$optionindex] = $label;
            $scores[$optionindex] = local_feedbackdashboard_extract_score_from_label($label);
        }
    } else {
        $rawoptions = explode(FEEDBACK_MULTICHOICERATED_LINE_SEP, $info->presentation);

        foreach ($rawoptions as $index => $rawoption) {
            $optionindex = $index + 1;
            $parts = explode(FEEDBACK_MULTICHOICERATED_VALUE_SEP, $rawoption, 2);
            $weight = trim((string) ($parts[0] ?? ''));
            $rawtext = (string) ($parts[1] ?? $parts[0] ?? '');
            $label = local_feedbackdashboard_clean_text($rawtext);

            if ($label === '') {
                $label = 'Alternativa ' . $optionindex;
            }

            $labels[$optionindex] = $label;

            if (is_numeric(str_replace(',', '.', $weight))) {
                $numericweight = (float) str_replace(',', '.', $weight);
                $rounded = (int) round($numericweight);
                $scores[$optionindex] = abs($numericweight - $rounded) < 0.00001 ? $rounded : null;
            } else {
                $scores[$optionindex] = local_feedbackdashboard_extract_score_from_label($label);
            }
        }
    }

    return [
        'labels' => $labels,
        'scores' => $scores,
        'ismultiple' => $ismultiple,
    ];
}

/**
 * Determines whether a Feedback item represents a standard 0-to-10 NPS question.
 *
 * @param stdClass $item Feedback item.
 * @return bool
 */
function local_feedbackdashboard_is_nps_item(stdClass $item): bool {
    $config = local_feedbackdashboard_get_choice_config($item);

    if ($config === null || $config['ismultiple']) {
        return false;
    }

    $scores = array_values(array_filter(
        $config['scores'],
        static fn($value) => $value !== null
    ));

    $scores = array_values(array_unique(array_map('intval', $scores)));
    sort($scores);

    if (count($scores) < 9) {
        return false;
    }

    if (!in_array(0, $scores, true) || !in_array(10, $scores, true)) {
        return false;
    }

    foreach ($scores as $score) {
        if ($score < 0 || $score > 10) {
            return false;
        }
    }

    return true;
}

/**
 * Finds the first 0-to-10 NPS question in the Feedback.
 *
 * @param array $items Feedback items.
 * @return stdClass|null
 */
function local_feedbackdashboard_find_nps_item(array $items): ?stdClass {
    foreach ($items as $item) {
        if (local_feedbackdashboard_is_nps_item($item)) {
            return $item;
        }
    }

    return null;
}

/**
 * Returns the NPS score represented by a stored response value.
 *
 * @param stdClass $item NPS item.
 * @param string $storedvalue Stored feedback_value value.
 * @return int|null
 */
function local_feedbackdashboard_decode_nps_score(stdClass $item, string $storedvalue): ?int {
    $storedvalue = trim($storedvalue);

    if ($storedvalue === '' || $storedvalue === '0') {
        return null;
    }

    $config = local_feedbackdashboard_get_choice_config($item);
    if ($config === null || $config['ismultiple']) {
        return null;
    }

    $optionindex = (int) $storedvalue;
    $score = $config['scores'][$optionindex] ?? null;

    if ($score === null || $score < 0 || $score > 10) {
        return null;
    }

    return (int) $score;
}

/**
 * Calculates NPS metrics for the selected set of completions.
 *
 * @param stdClass $npsitem NPS question.
 * @param array $completions Completion records.
 * @param array $valuesbycompletion Values indexed by completion ID and item ID.
 * @return array
 */
function local_feedbackdashboard_calculate_nps(
    stdClass $npsitem,
    array $completions,
    array $valuesbycompletion
): array {
    $scores = [];
    $scorecounts = array_fill(0, 11, 0);

    foreach ($completions as $completion) {
        $rawvalue = (string) ($valuesbycompletion[$completion->id][$npsitem->id] ?? '');
        $score = local_feedbackdashboard_decode_nps_score($npsitem, $rawvalue);

        if ($score === null) {
            continue;
        }

        $scores[(int) $completion->id] = $score;
        $scorecounts[$score]++;
    }

    $total = count($scores);
    $promoters = 0;
    $neutrals = 0;
    $detractors = 0;

    foreach ($scores as $score) {
        if ($score >= 9) {
            $promoters++;
        } else if ($score >= 7) {
            $neutrals++;
        } else {
            $detractors++;
        }
    }

    $nps = $total > 0 ? (($promoters - $detractors) / $total) * 100 : 0.0;
    $average = $total > 0 ? array_sum($scores) / $total : 0.0;

    return [
        'scores' => $scores,
        'scorecounts' => $scorecounts,
        'total' => $total,
        'promoters' => $promoters,
        'neutrals' => $neutrals,
        'detractors' => $detractors,
        'promoterspct' => $total > 0 ? ($promoters / $total) * 100 : 0.0,
        'neutralspct' => $total > 0 ? ($neutrals / $total) * 100 : 0.0,
        'detractorspct' => $total > 0 ? ($detractors / $total) * 100 : 0.0,
        'nps' => $nps,
        'average' => $average,
    ];
}

/**
 * Returns all open-text answers from one completion as a readable HTML fragment.
 *
 * @param int $completionid Completion ID.
 * @param array $textitems Text items.
 * @param array $valuesbycompletion Values indexed by completion and item.
 * @return string
 */
function local_feedbackdashboard_build_open_answers_html(
    int $completionid,
    array $textitems,
    array $valuesbycompletion
): string {
    $answers = [];

    foreach ($textitems as $item) {
        $rawvalue = (string) ($valuesbycompletion[$completionid][$item->id] ?? '');
        $answer = local_feedbackdashboard_clean_text($rawvalue);

        if ($answer === '') {
            continue;
        }

        if (count($textitems) === 1) {
            $answers[] = s($answer);
        } else {
            $answers[] = html_writer::tag('strong', s(format_string($item->name)))
                . ': ' . s($answer);
        }
    }

    return empty($answers) ? '—' : implode(html_writer::empty_tag('br'), $answers);
}

/*
 * -------------------------------------------------------------------------
 * Parameters, permissions and core records.
 * -------------------------------------------------------------------------
 */

$id = required_param('id', PARAM_INT);
$selecteduserids = optional_param_array('users', [], PARAM_INT);
$selecteduserids = array_values(array_unique(array_map('intval', $selecteduserids)));

[$course, $cm] = get_course_and_cm_from_cmid($id, 'feedback');
require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('local/feedbackdashboard:view', $context);
require_capability('mod/feedback:viewreports', $context);

$feedback = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);
$isanonymous = ((int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES);

/*
 * -------------------------------------------------------------------------
 * Page configuration.
 * -------------------------------------------------------------------------
 */

$pageurl = new moodle_url('/local/feedbackdashboard/index.php', ['id' => $cm->id]);

$PAGE->set_url($pageurl);
$PAGE->set_course($course);
$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title('Dashboard de NPS: ' . format_string($feedback->name));
$PAGE->set_heading(format_string($course->fullname));

$primary = local_feedbackdashboard_get_theme_primary_color();
$dark = local_feedbackdashboard_mix_color($primary, '#000000', 0.48);
$light = local_feedbackdashboard_mix_color($primary, '#FFFFFF', 0.95);
$border = local_feedbackdashboard_mix_color($primary, '#FFFFFF', 0.78);

// Semantic NPS colours intentionally remain stable regardless of the theme.
$goodcolor = '#2A9D8F';
$neutralcolor = '#E9C46A';
$badcolor = '#E76F51';

/*
 * -------------------------------------------------------------------------
 * Feedback questions and responder filter.
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

$textitems = array_values(array_filter(
    $items,
    static fn($item) => in_array($item->typ, ['textarea', 'textfield'], true)
));

$npsitem = local_feedbackdashboard_find_nps_item($items);
$responders = [];

if (!$isanonymous) {
    $responders = $DB->get_records_sql(
        "SELECT DISTINCT
                u.id,
                u.firstname,
                u.lastname,
                u.firstnamephonetic,
                u.lastnamephonetic,
                u.middlename,
                u.alternatename,
                u.email
           FROM {feedback_completed} fbc
           JOIN {user} u ON u.id = fbc.userid
          WHERE fbc.feedback = :feedbackid
            AND fbc.userid > 0
            AND fbc.anonymous_response = :identifiedresponse
            AND u.deleted = 0
       ORDER BY u.firstname, u.lastname, u.id",
        [
            'feedbackid' => $feedback->id,
            'identifiedresponse' => FEEDBACK_ANONYMOUS_NO,
        ]
    );

    $validresponderids = array_map('intval', array_keys($responders));
    $selecteduserids = array_values(array_intersect($selecteduserids, $validresponderids));
} else {
    $selecteduserids = [];
}

/*
 * -------------------------------------------------------------------------
 * Load the completions represented by the current filter.
 * -------------------------------------------------------------------------
 */

$completionparams = [
    'feedbackid' => $feedback->id,
    'responsemode' => $isanonymous ? FEEDBACK_ANONYMOUS_YES : FEEDBACK_ANONYMOUS_NO,
];

$completionssql = "
    SELECT
        fbc.id,
        fbc.userid,
        fbc.timemodified,
        u.firstname,
        u.lastname,
        u.firstnamephonetic,
        u.lastnamephonetic,
        u.middlename,
        u.alternatename,
        u.email
    FROM {feedback_completed} fbc
    LEFT JOIN {user} u ON u.id = fbc.userid
    WHERE fbc.feedback = :feedbackid
      AND fbc.anonymous_response = :responsemode
";

if (!$isanonymous && !empty($selecteduserids)) {
    [$usersql, $userparams] = $DB->get_in_or_equal(
        $selecteduserids,
        SQL_PARAMS_NAMED,
        'dashboarduser'
    );

    $completionssql .= " AND fbc.userid {$usersql}";
    $completionparams += $userparams;
}

$completionssql .= ' ORDER BY fbc.timemodified ASC, fbc.id ASC';
$completions = $DB->get_records_sql($completionssql, $completionparams);

$totalresponsecount = $DB->count_records('feedback_completed', [
    'feedback' => $feedback->id,
    'anonymous_response' => $isanonymous ? FEEDBACK_ANONYMOUS_YES : FEEDBACK_ANONYMOUS_NO,
]);

$valuesbycompletion = [];

if (!empty($completions) && !empty($items)) {
    [$completionsinsql, $completioninparams] = $DB->get_in_or_equal(
        array_keys($completions),
        SQL_PARAMS_NAMED,
        'dashboardcompletion'
    );
    [$itemsinsql, $iteminparams] = $DB->get_in_or_equal(
        array_keys($items),
        SQL_PARAMS_NAMED,
        'dashboarditem'
    );

    $valuerecords = $DB->get_records_sql(
        "SELECT id, completed, item, value
           FROM {feedback_value}
          WHERE completed {$completionsinsql}
            AND item {$itemsinsql}",
        $completioninparams + $iteminparams
    );

    foreach ($valuerecords as $valuerecord) {
        $valuesbycompletion[(int) $valuerecord->completed][(int) $valuerecord->item] =
            (string) $valuerecord->value;
    }
}

$npsmetrics = $npsitem !== null
    ? local_feedbackdashboard_calculate_nps($npsitem, $completions, $valuesbycompletion)
    : null;

/*
 * -------------------------------------------------------------------------
 * Filter JavaScript.
 * -------------------------------------------------------------------------
 */

$filterjavascript = <<<'JS'
(function() {
    'use strict';

    const form = document.getElementById('feedbackdashboard-filter-form');
    if (!form) {
        return;
    }

    const allCheckbox = document.getElementById('feedbackdashboard-filter-all');
    const userCheckboxes = Array.from(
        form.querySelectorAll('.feedbackdashboard-user-checkbox')
    );
    const searchInput = document.getElementById('feedbackdashboard-student-search');
    const studentOptions = Array.from(
        form.querySelectorAll('.feedbackdashboard-student-option')
    );

    let submitTimer = null;

    const scheduleSubmit = function() {
        window.clearTimeout(submitTimer);
        submitTimer = window.setTimeout(function() {
            form.submit();
        }, 700);
    };

    if (allCheckbox) {
        allCheckbox.addEventListener('change', function() {
            if (allCheckbox.checked) {
                userCheckboxes.forEach(function(checkbox) {
                    checkbox.checked = false;
                });
            } else if (!userCheckboxes.some(function(checkbox) { return checkbox.checked; })) {
                allCheckbox.checked = true;
            }
            scheduleSubmit();
        });
    }

    userCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            if (allCheckbox) {
                allCheckbox.checked = !userCheckboxes.some(function(current) {
                    return current.checked;
                });
            }
            scheduleSubmit();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const term = searchInput.value.trim().toLocaleLowerCase();

            studentOptions.forEach(function(option) {
                const name = (option.dataset.studentName || '').toLocaleLowerCase();
                option.hidden = term !== '' && !name.includes(term);
            });
        });
    }
})();
JS;

$PAGE->requires->js_init_code($filterjavascript);

/*
 * -------------------------------------------------------------------------
 * Output.
 * -------------------------------------------------------------------------
 */

echo $OUTPUT->header();

// Lightweight dashboard styling. Theme colours are injected from Moodle configuration.
$dashboardcss = '
.feedbackdashboard-report {background:' . $light . '; border-top:5px solid ' . $primary . '; padding:1.5rem; border-radius:.35rem;}
.feedbackdashboard-meta {background:#fff; border:1px solid ' . $border . '; padding:.75rem 1rem; margin-bottom:1rem;}
.feedbackdashboard-card {background:#fff; border:1px solid ' . $border . '; border-radius:.25rem; height:100%; position:relative; overflow:hidden;}
.feedbackdashboard-card::before {content:""; display:block; height:4px; background:var(--card-accent,' . $primary . ');}
.feedbackdashboard-card-body {padding:.65rem .75rem .8rem; text-align:center;}
.feedbackdashboard-card-title {font-weight:600; color:#536271; font-size:.88rem;}
.feedbackdashboard-card-value {font-size:1.9rem; line-height:1.15; font-weight:700; color:' . $dark . '; margin:.2rem 0;}
.feedbackdashboard-card-detail {color:#637083; font-size:.78rem;}
.feedbackdashboard-chartbox {background:#fff; border:1px solid ' . $border . '; border-radius:.25rem; padding:1rem; height:100%;}
.feedbackdashboard-nps-row {display:grid; grid-template-columns:90px 1fr 92px; align-items:center; gap:.65rem; margin:.8rem 0;}
.feedbackdashboard-nps-label {font-size:.82rem; font-weight:600; color:#536271;}
.feedbackdashboard-nps-track {height:24px; background:#eef2f6; border-radius:3px; overflow:hidden;}
.feedbackdashboard-nps-fill {height:100%; min-width:0; border-radius:3px;}
.feedbackdashboard-nps-value {font-size:.8rem; font-weight:600; color:' . $dark . '; text-align:right;}
@media (max-width: 767.98px) {.feedbackdashboard-nps-row {grid-template-columns:80px 1fr;}.feedbackdashboard-nps-value {grid-column:2; text-align:left; margin-top:-.4rem;}}
';

echo html_writer::tag('style', $dashboardcss);

/* Header and PDF button. */
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3');
echo html_writer::start_div();
echo $OUTPUT->heading('Dashboard de NPS', 2);
echo html_writer::tag('div', 'Feedback de Pesquisa de Satisfação do aluno', ['class' => 'text-muted']);
echo html_writer::end_div();

$pdfparams = ['id' => $cm->id];
if (!$isanonymous && !empty($selecteduserids)) {
    $pdfparams['userids'] = implode(',', $selecteduserids);
}

$pdfurl = new moodle_url('/local/feedbackdashboard/download.php', $pdfparams);
$pdfbuttoncontent = $OUTPUT->pix_icon('t/download', '') . ' Baixar relatório em PDF';

echo html_writer::link($pdfurl, $pdfbuttoncontent, [
    'class' => 'btn btn-outline-primary',
    'title' => 'Baixar o relatório NPS com o filtro atual',
]);
echo html_writer::end_div();

/* Participant filter. */
if (!$isanonymous) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-body');
    echo $OUTPUT->heading('Filtrar participantes', 3, 'h5 card-title');
    echo html_writer::tag(
        'p',
        'Selecione um ou mais alunos. Os indicadores, gráficos, tabela e PDF serão recalculados com o mesmo filtro.',
        ['class' => 'text-muted mb-3']
    );

    if (empty($responders)) {
        echo $OUTPUT->notification('Ainda não há alunos identificados que responderam esta pesquisa.', 'info');
    } else {
        echo html_writer::start_tag('form', [
            'id' => 'feedbackdashboard-filter-form',
            'method' => 'get',
            'action' => (new moodle_url('/local/feedbackdashboard/index.php'))->out(false),
            'autocomplete' => 'off',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'id',
            'value' => $cm->id,
        ]);

        echo html_writer::start_div('row g-3');
        echo html_writer::start_div('col-12 col-lg-4');
        echo html_writer::tag('label', 'Pesquisar aluno', [
            'for' => 'feedbackdashboard-student-search',
            'class' => 'form-label',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'search',
            'id' => 'feedbackdashboard-student-search',
            'class' => 'form-control',
            'placeholder' => 'Digite o nome do aluno...',
        ]);
        echo html_writer::end_div();

        echo html_writer::start_div('col-12 col-lg-8');
        echo html_writer::start_div('border rounded p-3');
        echo html_writer::start_div('form-check border-bottom pb-2 mb-2');

        $allattributes = [
            'type' => 'checkbox',
            'id' => 'feedbackdashboard-filter-all',
            'class' => 'form-check-input',
            'value' => '1',
        ];
        if (empty($selecteduserids)) {
            $allattributes['checked'] = 'checked';
        }

        echo html_writer::empty_tag('input', $allattributes);
        echo html_writer::tag('label', 'Todos os alunos', [
            'for' => 'feedbackdashboard-filter-all',
            'class' => 'form-check-label fw-bold',
        ]);
        echo html_writer::end_div();

        echo html_writer::start_div('overflow-auto', ['style' => 'max-height:190px;']);
        foreach ($responders as $responder) {
            $responderid = (int) $responder->id;
            $respondername = fullname($responder);
            $checkboxid = 'feedbackdashboard-user-' . $responderid;

            $checkboxattributes = [
                'type' => 'checkbox',
                'id' => $checkboxid,
                'name' => 'users[]',
                'value' => $responderid,
                'class' => 'form-check-input feedbackdashboard-user-checkbox',
            ];

            if (in_array($responderid, $selecteduserids, true)) {
                $checkboxattributes['checked'] = 'checked';
            }

            echo html_writer::start_div('form-check py-1 feedbackdashboard-student-option', [
                'data-student-name' => core_text::strtolower($respondername),
            ]);
            echo html_writer::empty_tag('input', $checkboxattributes);
            echo html_writer::tag('label', s($respondername), [
                'for' => $checkboxid,
                'class' => 'form-check-label',
            ]);
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('d-flex align-items-center flex-wrap gap-2 mt-3');
        echo html_writer::tag('button', 'Aplicar filtro', [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]);
        echo html_writer::link($pageurl, 'Limpar filtro', ['class' => 'btn btn-secondary']);

        $selectionmessage = empty($selecteduserids)
            ? 'Exibindo todos os alunos.'
            : (count($selecteduserids) === 1
                ? '1 aluno selecionado.'
                : count($selecteduserids) . ' alunos selecionados.');

        echo html_writer::tag('span', $selectionmessage, ['class' => 'text-muted ms-2']);
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    echo $OUTPUT->notification(
        'Esta pesquisa é anônima. O NPS é calculado normalmente, porém o filtro por aluno permanece indisponível.',
        'warning'
    );
}

/* Dashboard report surface. */
echo html_writer::start_div('feedbackdashboard-report mb-4');

echo html_writer::tag('h2', 'Feedback de Pesquisa de Satisfação do aluno', [
    'class' => 'mb-1',
    'style' => 'color:' . $dark . ';',
]);
echo html_writer::tag('div', 'Aula: ' . s(format_string($feedback->name)), [
    'class' => 'text-muted fst-italic mb-3',
]);

$metatext = html_writer::tag('strong', 'Curso:') . ' ' . s(format_string($course->fullname))
    . html_writer::empty_tag('br')
    . html_writer::tag('strong', 'Respostas submetidas:') . ' ' . $totalresponsecount
    . html_writer::empty_tag('br')
    . html_writer::tag('strong', 'Respostas consideradas no filtro:') . ' ' . count($completions)
    . html_writer::empty_tag('br')
    . html_writer::tag('strong', 'Questões:') . ' ' . count($items);

if ($npsitem !== null) {
    $metatext .= html_writer::empty_tag('br')
        . html_writer::tag('strong', 'Pergunta NPS:') . ' ' . s(format_string($npsitem->name));
}

echo html_writer::div($metatext, 'feedbackdashboard-meta');

if ($npsitem === null) {
    echo $OUTPUT->notification(
        'Nenhuma pergunta de alternativa com escala 0 a 10 foi detectada. '
        . 'Para calcular NPS, utilize uma pergunta de escolha única com notas de 0 a 10.',
        'warning'
    );
} else {
    $metrics = $npsmetrics;

    // Five NPS summary cards, matching the report structure.
    $cards = [
        [
            'title' => 'NPS(%)',
            'value' => format_float($metrics['nps'], 0) . '%',
            'detail' => 'promotores - detratores',
            'color' => $primary,
        ],
        [
            'title' => 'Promotores(%)',
            'value' => format_float($metrics['promoterspct'], 0) . '%',
            'detail' => $metrics['promoters'] . ' resposta(s)',
            'color' => $goodcolor,
        ],
        [
            'title' => 'Neutros(%)',
            'value' => format_float($metrics['neutralspct'], 0) . '%',
            'detail' => $metrics['neutrals'] . ' resposta(s)',
            'color' => $neutralcolor,
        ],
        [
            'title' => 'Detratores(%)',
            'value' => format_float($metrics['detractorspct'], 0) . '%',
            'detail' => $metrics['detractors'] . ' resposta(s)',
            'color' => $badcolor,
        ],
        [
            'title' => 'Média',
            'value' => format_float($metrics['average'], 1),
            'detail' => $metrics['total'] . ' nota(s) válida(s)',
            'color' => $dark,
        ],
    ];

    echo html_writer::start_div('row row-cols-1 row-cols-sm-2 row-cols-xl-5 g-2 mb-3');
    foreach ($cards as $card) {
        echo html_writer::start_div('col');
        echo html_writer::start_div('feedbackdashboard-card', [
            'style' => '--card-accent:' . $card['color'] . ';',
        ]);
        echo html_writer::start_div('feedbackdashboard-card-body');
        echo html_writer::div(s($card['title']), 'feedbackdashboard-card-title');
        echo html_writer::div(s($card['value']), 'feedbackdashboard-card-value');
        echo html_writer::div(s($card['detail']), 'feedbackdashboard-card-detail');
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    echo html_writer::start_div('row g-3');

    // NPS profile distribution.
    echo html_writer::start_div('col-12 col-xl-6');
    echo html_writer::start_div('feedbackdashboard-chartbox');
    echo html_writer::tag('h3', 'Distribuição do NPS por perfil', ['class' => 'h5 mb-3']);

    $profilemax = max(1, $metrics['promoters'], $metrics['neutrals'], $metrics['detractors']);
    $profiles = [
        ['label' => 'Promotores', 'count' => $metrics['promoters'], 'pct' => $metrics['promoterspct'], 'color' => $goodcolor],
        ['label' => 'Neutros', 'count' => $metrics['neutrals'], 'pct' => $metrics['neutralspct'], 'color' => $neutralcolor],
        ['label' => 'Detratores', 'count' => $metrics['detractors'], 'pct' => $metrics['detractorspct'], 'color' => $badcolor],
    ];

    foreach ($profiles as $profile) {
        $width = $profile['count'] > 0 ? ($profile['count'] / $profilemax) * 100 : 0;
        echo html_writer::start_div('feedbackdashboard-nps-row');
        echo html_writer::div(s($profile['label']), 'feedbackdashboard-nps-label');
        echo html_writer::start_div('feedbackdashboard-nps-track');
        echo html_writer::div('', 'feedbackdashboard-nps-fill', [
            'style' => 'width:' . number_format($width, 2, '.', '') . '%;background:' . $profile['color'] . ';',
        ]);
        echo html_writer::end_div();
        echo html_writer::div(
            $profile['count'] . ' (' . format_float($profile['pct'], 1) . '%)',
            'feedbackdashboard-nps-value'
        );
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

    // Score distribution 0..10 using Moodle's native chart.
    echo html_writer::start_div('col-12 col-xl-6');
    echo html_writer::start_div('feedbackdashboard-chartbox');
    echo html_writer::tag('h3', 'Gráfico de Avaliações por Nota', ['class' => 'h5 mb-2']);

    $chart = new \core\chart_bar();
    $chart->set_horizontal(false);
    $chart->set_labels(array_map('strval', range(0, 10)));
    $chart->set_legend_options(['display' => false]);

    $serieslabels = [];
    foreach ($metrics['scorecounts'] as $count) {
        $serieslabels[] = (string) $count;
    }

    $series = new \core\chart_series('Respostas', array_values($metrics['scorecounts']));
    $series->set_labels($serieslabels);
    $series->set_color($primary);
    $chart->add_series($series);

    echo $OUTPUT->render($chart);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div();

    echo html_writer::tag(
        'div',
        'Legenda NPS: notas 9-10 são promotores, 7-8 são neutros e 0-6 são detratores.',
        ['class' => 'text-muted small text-end mt-2']
    );
}

echo html_writer::end_div();

/* Responses table on the web dashboard, corresponding to PDF page 2. */
echo $OUTPUT->heading('Respostas e comentários', 2);

if (empty($completions)) {
    echo $OUTPUT->notification('Não há respostas para os filtros atuais.', 'info');
} else {
    $responsetable = new html_table();
    $responsetable->attributes = ['class' => 'generaltable'];
    $responsetable->head = ['Participante', 'Nota NPS', 'Respostas abertas'];

    $rows = [];
    $anonymousindex = 0;

    foreach ($completions as $completion) {
        $anonymousindex++;
        $name = $isanonymous ? 'Resposta ' . $anonymousindex : fullname($completion);
        $score = '—';

        if ($npsitem !== null) {
            $rawscore = (string) ($valuesbycompletion[$completion->id][$npsitem->id] ?? '');
            $decodedscore = local_feedbackdashboard_decode_nps_score($npsitem, $rawscore);
            if ($decodedscore !== null) {
                $score = (string) $decodedscore;
            }
        }

        $comments = local_feedbackdashboard_build_open_answers_html(
            (int) $completion->id,
            $textitems,
            $valuesbycompletion
        );

        $rows[] = [
            'name' => $name,
            'score' => $score,
            'comments' => $comments,
            'sortscore' => is_numeric($score) ? (int) $score : -1,
        ];
    }

    usort($rows, static function(array $a, array $b): int {
        if ($a['sortscore'] === $b['sortscore']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $b['sortscore'] <=> $a['sortscore'];
    });

    foreach ($rows as $row) {
        $responsetable->data[] = [
            s($row['name']),
            s($row['score']),
            $row['comments'],
        ];
    }

    echo html_writer::table($responsetable);
}

echo $OUTPUT->footer();
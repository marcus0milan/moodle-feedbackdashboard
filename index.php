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
require_once($CFG->libdir . '/grouplib.php');

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
$selectedgroupid = optional_param('groupid', 0, PARAM_INT);
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
 * Groups available to the current user.
 *
 * When the activity does not enforce groups, users with report access may
 * filter by any course group. When group access is restricted by Moodle,
 * only groups the current user is allowed to access are exposed.
 * -------------------------------------------------------------------------
 */

$groupmode = groups_get_activity_groupmode($cm);
$accessallgroups = ($groupmode == NOGROUPS)
    || has_capability('moodle/site:accessallgroups', $context);

$availablegroups = [];

if (!$isanonymous) {
    if ($accessallgroups) {
        $availablegroups = groups_get_all_groups(
            $course->id,
            0,
            $cm->groupingid,
            'g.id, g.name'
        );
    } else {
        $availablegroups = groups_get_activity_allowed_groups($cm);
    }

    if (!is_array($availablegroups)) {
        $availablegroups = [];
    }

    $availablegroupids = array_map('intval', array_keys($availablegroups));

    // Never accept a group outside the set the current user is allowed to see.
    if ($selectedgroupid > 0 && !in_array($selectedgroupid, $availablegroupids, true)) {
        $selectedgroupid = 0;
    }
} else {
    $selectedgroupid = 0;
}

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
    $responderparams = [
        'feedbackid' => $feedback->id,
        'identifiedresponse' => FEEDBACK_ANONYMOUS_NO,
    ];

    $respondergroupjoin = '';
    $respondergroupwhere = '';

    /*
     * A selected group always restricts the responder list to members
     * of that group.
     */
    if ($selectedgroupid > 0) {
        $respondergroupjoin = ' JOIN {groups_members} gm ON gm.userid = fbc.userid';
        $respondergroupwhere = ' AND gm.groupid = :selectedgroupid';
        $responderparams['selectedgroupid'] = $selectedgroupid;

    /*
     * In restricted group mode, "Todos os grupos" means all groups the
     * current user is actually allowed to access, never every course group.
     */
    } else if (!$accessallgroups) {
        $allowedgroupids = array_map('intval', array_keys($availablegroups));

        if (empty($allowedgroupids)) {
            $respondergroupwhere = ' AND 1 = 0';
        } else {
            [$allowedgroupsql, $allowedgroupparams] = $DB->get_in_or_equal(
                $allowedgroupids,
                SQL_PARAMS_NAMED,
                'dashboardgroup'
            );

            $respondergroupjoin = ' JOIN {groups_members} gm ON gm.userid = fbc.userid';
            $respondergroupwhere = " AND gm.groupid {$allowedgroupsql}";
            $responderparams += $allowedgroupparams;
        }
    }

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
           {$respondergroupjoin}
          WHERE fbc.feedback = :feedbackid
            AND fbc.userid > 0
            AND fbc.anonymous_response = :identifiedresponse
            AND u.deleted = 0
            {$respondergroupwhere}
       ORDER BY u.firstname, u.lastname, u.id",
        $responderparams
    );

    $validresponderids = array_map('intval', array_keys($responders));
    $selecteduserids = array_values(array_intersect($selecteduserids, $validresponderids));
} else {
    $selecteduserids = [];
}

/*
 * Determines whether the completion query must be restricted by users.
 *
 * This is true for a selected group and also when Moodle group permissions
 * restrict the current user to a subset of groups.
 */
$mustfilterbyusers = !$isanonymous
    && (
        !empty($selecteduserids)
        || $selectedgroupid > 0
        || !$accessallgroups
    );

$effectiveuserids = [];

if ($mustfilterbyusers) {
    $effectiveuserids = !empty($selecteduserids)
        ? $selecteduserids
        : array_map('intval', array_keys($responders));

    // Force an empty result rather than accidentally falling back to all users.
    if (empty($effectiveuserids)) {
        $effectiveuserids = [0];
    }
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

if ($mustfilterbyusers) {
    [$usersql, $userparams] = $DB->get_in_or_equal(
        $effectiveuserids,
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

    const groupSelect = document.getElementById('feedbackdashboard-group-filter');
    const picker = document.getElementById('feedbackdashboard-user-picker');
    const searchInput = document.getElementById('feedbackdashboard-student-search');
    const tagsContainer = document.getElementById('feedbackdashboard-selected-users');
    const hiddenInputs = document.getElementById('feedbackdashboard-selected-user-inputs');
    const suggestions = document.getElementById('feedbackdashboard-user-suggestions');
    const emptySuggestion = document.getElementById('feedbackdashboard-no-user-suggestion');
    const clearUsersButton = document.getElementById('feedbackdashboard-clear-selected-users');
    const selectionLive = document.getElementById('feedbackdashboard-selection-live');

    const suggestionButtons = Array.from(
        form.querySelectorAll('.feedbackdashboard-user-suggestion')
    );

    const selected = new Set();

    if (tagsContainer) {
        tagsContainer
            .querySelectorAll('.feedbackdashboard-user-tag')
            .forEach(function(tag) {
                const userid = String(tag.dataset.userId || '');

                if (userid !== '') {
                    selected.add(userid);
                }
            });
    }

    const normalise = function(value) {
        return (value || '').trim().toLocaleLowerCase();
    };

    const updateSelectionState = function() {
        if (clearUsersButton) {
            clearUsersButton.hidden = selected.size === 0;
        }

        if (selectionLive) {
            if (selected.size === 0) {
                selectionLive.textContent = selectionLive.dataset.emptyText || '';
            } else if (selected.size === 1) {
                selectionLive.textContent = '1 aluno específico selecionado.';
            } else {
                selectionLive.textContent = selected.size + ' alunos específicos selecionados.';
            }
        }

        if (searchInput) {
            searchInput.placeholder = selected.size === 0
                ? 'Digite ou escolha um aluno...'
                : 'Adicionar outro aluno...';
        }
    };

    const closeSuggestions = function() {
        if (suggestions) {
            suggestions.hidden = true;
        }

        if (searchInput) {
            searchInput.setAttribute('aria-expanded', 'false');
        }
    };

    const updateSuggestions = function() {
        if (!suggestions || !searchInput) {
            return;
        }

        const term = normalise(searchInput.value);
        let visibleCount = 0;

        suggestionButtons.forEach(function(button) {
            const userid = String(button.dataset.userId || '');
            const username = normalise(button.dataset.userName || '');
            const useremail = normalise(button.dataset.userEmail || '');

            const matchesText =
                term === ''
                || username.includes(term)
                || useremail.includes(term);

            const matches =
                matchesText
                && !selected.has(userid);

            button.hidden = !matches;

            if (matches) {
                visibleCount++;
            }
        });

        if (emptySuggestion) {
            emptySuggestion.hidden = visibleCount > 0;
        }

        suggestions.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    };

    const createHiddenInput = function(userid) {
        if (!hiddenInputs) {
            return;
        }

        const input = document.createElement('input');

        input.type = 'hidden';
        input.name = 'users[]';
        input.value = userid;
        input.dataset.userId = userid;

        hiddenInputs.appendChild(input);
    };

    const removeHiddenInput = function(userid) {
        if (!hiddenInputs) {
            return;
        }

        const inputs = Array.from(
            hiddenInputs.querySelectorAll('input[name="users[]"]')
        );

        const input = inputs.find(function(current) {
            return String(
                current.dataset.userId || current.value
            ) === userid;
        });

        if (input) {
            input.remove();
        }
    };

    const createTag = function(userid, username) {
        if (!tagsContainer) {
            return;
        }

        const tag = document.createElement('span');
        tag.className = 'feedbackdashboard-user-tag';
        tag.dataset.userId = userid;

        const label = document.createElement('span');
        label.className = 'feedbackdashboard-user-tag-label';
        label.textContent = username;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'feedbackdashboard-user-tag-remove';
        remove.dataset.userId = userid;
        remove.title = 'Remover aluno';
        remove.setAttribute(
            'aria-label',
            'Remover ' + username
        );
        remove.textContent = '×';

        tag.appendChild(label);
        tag.appendChild(remove);

        tagsContainer.appendChild(tag);
    };

    const addUser = function(userid, username) {
        userid = String(userid || '');

        if (
            userid === ''
            || selected.has(userid)
        ) {
            return;
        }

        selected.add(userid);

        createTag(
            userid,
            username
        );

        createHiddenInput(userid);

        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }

        updateSelectionState();
        updateSuggestions();
    };

    const removeUser = function(userid) {
        userid = String(userid || '');

        if (
            userid === ''
            || !selected.has(userid)
        ) {
            return;
        }

        selected.delete(userid);

        if (tagsContainer) {
            const tags = Array.from(
                tagsContainer.querySelectorAll(
                    '.feedbackdashboard-user-tag'
                )
            );

            const tag = tags.find(function(current) {
                return String(
                    current.dataset.userId || ''
                ) === userid;
            });

            if (tag) {
                tag.remove();
            }
        }

        removeHiddenInput(userid);
        updateSelectionState();

        if (searchInput) {
            searchInput.focus();
        }

        updateSuggestions();
    };

    const clearSelectedUsers = function() {
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

    /*
     * The responder list is generated on the server according to the selected
     * Moodle group. Changing the group refreshes the page and clears any
     * individual student selection from the previous group.
     */
    if (groupSelect) {
        groupSelect.addEventListener(
            'change',
            function() {
                if (hiddenInputs) {
                    hiddenInputs.innerHTML = '';
                }

                form.submit();
            }
        );
    }

    suggestionButtons.forEach(function(button) {
        button.addEventListener(
            'click',
            function() {
                addUser(
                    String(button.dataset.userId || ''),
                    String(button.dataset.userName || '')
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
                        '.feedbackdashboard-user-tag-remove'
                    );

                if (!removeButton) {
                    return;
                }

                removeUser(
                    String(removeButton.dataset.userId || '')
                );
            }
        );
    }

    if (clearUsersButton) {
        clearUsersButton.addEventListener(
            'click',
            clearSelectedUsers
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
                            '.feedbackdashboard-user-tag'
                        );

                    const lastTag =
                        tags.length > 0
                            ? tags[tags.length - 1]
                            : null;

                    if (lastTag) {
                        removeUser(
                            String(lastTag.dataset.userId || '')
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

$PAGE->requires->js_init_code($filterjavascript);

/*
 * -------------------------------------------------------------------------
 * Output.
 * -------------------------------------------------------------------------
 */

echo $OUTPUT->header();

// Lightweight dashboard styling. Theme colours are injected from Moodle configuration.
$dashboardcss = '
.feedbackdashboard-report {
    background:' . $light . ';
    border-top:5px solid ' . $primary . ';
    padding:1.5rem;
    border-radius:.35rem;
}

.feedbackdashboard-meta {
    background:#fff;
    border:1px solid ' . $border . ';
    padding:.75rem 1rem;
    margin-bottom:1rem;
}

.feedbackdashboard-card {
    background:#fff;
    border:1px solid ' . $border . ';
    border-radius:.25rem;
    height:100%;
    position:relative;
    overflow:hidden;
}

.feedbackdashboard-card::before {
    content:"";
    display:block;
    height:4px;
    background:var(--card-accent,' . $primary . ');
}

.feedbackdashboard-card-body {
    padding:.65rem .75rem .8rem;
    text-align:center;
}

.feedbackdashboard-card-title {
    font-weight:600;
    color:#536271;
    font-size:.88rem;
}

.feedbackdashboard-card-value {
    font-size:1.9rem;
    line-height:1.15;
    font-weight:700;
    color:' . $dark . ';
    margin:.2rem 0;
}

.feedbackdashboard-card-detail {
    color:#637083;
    font-size:.78rem;
}

.feedbackdashboard-chartbox {
    background:#fff;
    border:1px solid ' . $border . ';
    border-radius:.25rem;
    padding:1rem;
    height:100%;
    min-width:0;
    box-sizing:border-box;
}

.feedbackdashboard-chart-col {
    min-width:0;
}

.feedbackdashboard-chartbox .chart-area,
.feedbackdashboard-chartbox .chart-image {
    width:100% !important;
    max-width:100% !important;
    min-width:0 !important;
    box-sizing:border-box;
}

.feedbackdashboard-chartbox .chart-image {
    position:relative;
    height:350px;
}

.feedbackdashboard-chartbox .chart-image canvas {
    display:block !important;
    width:100% !important;
    max-width:100% !important;
    height:100% !important;
    min-width:0 !important;
}

.feedbackdashboard-filter-card {
    border:1px solid ' . $border . ';
    border-radius:.55rem;
    overflow:visible;
}

.feedbackdashboard-filter-section {
    background:#fff;
    border:1px solid ' . $border . ';
    border-radius:.5rem;
    padding:1rem;
    height:100%;
}

.feedbackdashboard-filter-section-soft {
    background:' . $light . ';
}

.feedbackdashboard-filter-section-heading {
    display:flex;
    align-items:flex-start;
    gap:.65rem;
    margin-bottom:1rem;
}

.feedbackdashboard-filter-step {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    flex:0 0 26px;
    width:26px;
    height:26px;
    border-radius:50%;
    background:' . $primary . ';
    color:#fff;
    font-size:.78rem;
    font-weight:700;
}

.feedbackdashboard-filter-section-title {
    margin:0;
    color:' . $dark . ';
    font-size:.95rem;
    font-weight:700;
    line-height:1.25;
}

.feedbackdashboard-filter-section-subtitle {
    margin:.15rem 0 0;
    color:#637083;
    font-size:.78rem;
    line-height:1.4;
}

.feedbackdashboard-filter-label {
    font-weight:600;
    color:' . $dark . ';
    margin-bottom:.35rem;
}

.feedbackdashboard-filter-help {
    margin-top:.4rem;
    color:#637083;
    font-size:.76rem;
    line-height:1.4;
}

.feedbackdashboard-filter-scope {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:1rem;
    padding:.65rem .75rem;
    margin-bottom:.85rem;
    border:1px solid ' . $border . ';
    border-radius:.4rem;
    background:' . $light . ';
}

.feedbackdashboard-filter-scope-label {
    display:block;
    margin-bottom:.1rem;
    color:#637083;
    font-size:.72rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.02em;
}

.feedbackdashboard-filter-scope-value {
    color:' . $dark . ';
    font-size:.84rem;
    font-weight:700;
}

.feedbackdashboard-filter-scope-count {
    flex:0 0 auto;
    padding:.2rem .5rem;
    border:1px solid ' . $border . ';
    border-radius:999px;
    background:#fff;
    color:' . $dark . ';
    font-size:.73rem;
    font-weight:600;
}

.feedbackdashboard-user-picker {
    position:relative;
}

.feedbackdashboard-user-picker-box {
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.4rem;
    min-height:46px;
    padding:.38rem .5rem;
    background:#fff;
    border:1px solid ' . $border . ';
    border-radius:.45rem;
    transition:border-color .15s ease, box-shadow .15s ease;
}

.feedbackdashboard-user-picker-box:focus-within {
    border-color:' . $primary . ';
    box-shadow:0 0 0 .18rem '
        . local_feedbackdashboard_mix_color(
            $primary,
            '#FFFFFF',
            0.78
        ) . ';
}

.feedbackdashboard-selected-users {
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.35rem;
}

.feedbackdashboard-user-tag {
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    max-width:100%;
    padding:.28rem .42rem .28rem .58rem;
    border:1px solid '
        . local_feedbackdashboard_mix_color(
            $primary,
            '#FFFFFF',
            0.58
        ) . ';
    border-radius:.38rem;
    background:'
        . local_feedbackdashboard_mix_color(
            $primary,
            '#FFFFFF',
            0.90
        ) . ';
    color:' . $dark . ';
    font-size:.82rem;
    font-weight:600;
    line-height:1.25;
}

.feedbackdashboard-user-tag-label {
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    max-width:260px;
}

.feedbackdashboard-user-tag-remove {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:20px;
    height:20px;
    padding:0;
    border:0;
    border-radius:50%;
    background:transparent;
    color:' . $dark . ';
    font-size:1rem;
    font-weight:700;
    line-height:1;
    cursor:pointer;
}

.feedbackdashboard-user-tag-remove:hover,
.feedbackdashboard-user-tag-remove:focus {
    background:'
        . local_feedbackdashboard_mix_color(
            $primary,
            '#FFFFFF',
            0.78
        ) . ';
    outline:0;
}

.feedbackdashboard-user-search-input {
    flex:1 1 220px;
    min-width:180px;
    height:32px;
    padding:.2rem .25rem;
    border:0 !important;
    outline:0 !important;
    box-shadow:none !important;
    background:transparent;
}

.feedbackdashboard-user-suggestions {
    position:absolute;
    z-index:1050;
    top:calc(100% + .3rem);
    left:0;
    right:0;
    max-height:250px;
    overflow-y:auto;
    padding:.3rem;
    background:#fff;
    border:1px solid ' . $border . ';
    border-radius:.45rem;
    box-shadow:0 .45rem 1.1rem rgba(15,23,42,.14);
}

.feedbackdashboard-user-suggestion {
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

.feedbackdashboard-user-suggestion:hover,
.feedbackdashboard-user-suggestion:focus {
    background:' . $light . ';
    color:' . $dark . ';
    outline:0;
}

.feedbackdashboard-user-suggestion-name {
    display:block;
    font-size:.88rem;
    font-weight:600;
}

.feedbackdashboard-user-suggestion-email {
    display:block;
    margin-top:.08rem;
    color:#637083;
    font-size:.74rem;
    font-weight:400;
}

.feedbackdashboard-user-picker-empty {
    padding:.7rem;
    color:#637083;
    font-size:.82rem;
}

.feedbackdashboard-user-picker-help {
    margin-top:.45rem;
    color:#637083;
    font-size:.76rem;
    line-height:1.4;
}

.feedbackdashboard-filter-selection-row {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.75rem;
    margin-top:.6rem;
}

.feedbackdashboard-filter-selection-live {
    color:#637083;
    font-size:.76rem;
}

.feedbackdashboard-filter-group-status {
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    padding:.28rem .55rem;
    border:1px solid ' . $border . ';
    border-radius:999px;
    background:' . $light . ';
    color:' . $dark . ';
    font-size:.78rem;
    font-weight:600;
}

.feedbackdashboard-filter-empty {
    padding:1rem;
    border:1px dashed ' . $border . ';
    border-radius:.45rem;
    background:#fff;
    color:#637083;
    font-size:.82rem;
}

/* Dashboard charts. */
.feedbackdashboard-nps-row {
    display:grid;
    grid-template-columns:90px 1fr 92px;
    align-items:center;
    gap:.65rem;
    margin:.8rem 0;
}

.feedbackdashboard-nps-label {
    font-size:.82rem;
    font-weight:600;
    color:#536271;
}

.feedbackdashboard-nps-track {
    height:24px;
    background:#eef2f6;
    border-radius:3px;
    overflow:hidden;
}

.feedbackdashboard-nps-fill {
    height:100%;
    min-width:0;
    border-radius:3px;
}

.feedbackdashboard-nps-value {
    font-size:.8rem;
    font-weight:600;
    color:' . $dark . ';
    text-align:right;
}

@media (max-width: 767.98px) {
    .feedbackdashboard-nps-row {
        grid-template-columns:80px 1fr;
    }

    .feedbackdashboard-nps-value {
        grid-column:2;
        text-align:left;
        margin-top:-.4rem;
    }

    .feedbackdashboard-filter-scope,
    .feedbackdashboard-filter-selection-row {
        align-items:flex-start;
        flex-direction:column;
    }

    .feedbackdashboard-filter-scope-count {
        align-self:flex-start;
    }
}
';

echo html_writer::tag('style', $dashboardcss);

/* Header and PDF button. */
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3');
echo html_writer::start_div();
echo $OUTPUT->heading('Dashboard de NPS', 2);
echo html_writer::tag('div', 'Feedback de Pesquisa de Satisfação do aluno', ['class' => 'text-muted']);
echo html_writer::end_div();

$pdfparams = ['id' => $cm->id];

if (!$isanonymous && $mustfilterbyusers) {
    $pdfuserids = !empty($selecteduserids)
        ? $selecteduserids
        : array_map('intval', array_keys($responders));

    // download.php treats userids=0 as a valid empty filter, preventing
    // a zero-response group from accidentally exporting every response.
    $pdfparams['userids'] = empty($pdfuserids)
        ? '0'
        : implode(',', $pdfuserids);
}

$pdfurl = new moodle_url('/local/feedbackdashboard/download.php', $pdfparams);
$pdfbuttoncontent = $OUTPUT->pix_icon('t/download', '') . ' Baixar relatório em PDF';

echo html_writer::link($pdfurl, $pdfbuttoncontent, [
    'class' => 'btn btn-outline-primary',
    'title' => 'Baixar o relatório NPS com o filtro atual',
]);
echo html_writer::end_div();

/* Participant and group filter. */
if (!$isanonymous) {
    echo html_writer::start_div('card mb-4 feedbackdashboard-filter-card');
    echo html_writer::start_div('card-body');

    echo $OUTPUT->heading(
        'Filtrar participantes',
        3,
        'h5 card-title mb-1'
    );

    echo html_writer::tag(
        'p',
        'Escolha o grupo e, se necessário, selecione alunos específicos. '
            . 'Os indicadores, gráficos, tabela e PDF serão recalculados com o mesmo filtro.',
        ['class' => 'text-muted mb-3']
    );

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

    echo html_writer::start_div('row g-3 align-items-stretch');

    /*
     * -------------------------------------------------------------
     * Step 1: group scope.
     * -------------------------------------------------------------
     */
    echo html_writer::start_div('col-12 col-lg-4');
    echo html_writer::start_div(
        'feedbackdashboard-filter-section feedbackdashboard-filter-section-soft'
    );

    echo html_writer::start_div('feedbackdashboard-filter-section-heading');

    echo html_writer::span(
        '1',
        'feedbackdashboard-filter-step',
        ['aria-hidden' => 'true']
    );

    echo html_writer::start_div();

    echo html_writer::tag(
        'h4',
        'Escolha o grupo',
        ['class' => 'feedbackdashboard-filter-section-title']
    );

    echo html_writer::tag(
        'p',
        'Defina primeiro de qual grupo virão os participantes.',
        ['class' => 'feedbackdashboard-filter-section-subtitle']
    );

    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::tag(
        'label',
        'Grupo',
        [
            'for' => 'feedbackdashboard-group-filter',
            'class' => 'form-label feedbackdashboard-filter-label',
        ]
    );

    $groupoptions = [
        0 => 'Todos os grupos',
    ];

    foreach ($availablegroups as $group) {
        $groupoptions[(int) $group->id] = format_string(
            $group->name,
            true,
            ['context' => $context]
        );
    }

    $groupselectattributes = [
        'id' => 'feedbackdashboard-group-filter',
        'class' => 'form-select',
        'aria-describedby' => 'feedbackdashboard-group-help',
    ];

    if (empty($availablegroups)) {
        $groupselectattributes['disabled'] = 'disabled';
    }

    echo html_writer::select(
        $groupoptions,
        'groupid',
        $selectedgroupid,
        false,
        $groupselectattributes
    );

    if (empty($availablegroups)) {
        $grouphelp =
            'Nenhum grupo está disponível para esta atividade.';
    } else {
        $grouphelp =
            'Ao trocar o grupo, a lista de alunos é atualizada automaticamente. '
            . 'Se nenhum aluno específico for escolhido, o grupo inteiro será considerado.';
    }

    echo html_writer::tag(
        'div',
        $grouphelp,
        [
            'id' => 'feedbackdashboard-group-help',
            'class' => 'feedbackdashboard-filter-help',
        ]
    );

    echo html_writer::end_div();
    echo html_writer::end_div();

    /*
     * -------------------------------------------------------------
     * Step 2: participant selection.
     * -------------------------------------------------------------
     */
    echo html_writer::start_div('col-12 col-lg-8');
    echo html_writer::start_div('feedbackdashboard-filter-section');

    echo html_writer::start_div('feedbackdashboard-filter-section-heading');

    echo html_writer::span(
        '2',
        'feedbackdashboard-filter-step',
        ['aria-hidden' => 'true']
    );

    echo html_writer::start_div();

    echo html_writer::tag(
        'h4',
        'Escolha os participantes',
        ['class' => 'feedbackdashboard-filter-section-title']
    );

    echo html_writer::tag(
        'p',
        'Use todos os respondentes do grupo ou selecione somente os alunos que deseja analisar.',
        ['class' => 'feedbackdashboard-filter-section-subtitle']
    );

    echo html_writer::end_div();
    echo html_writer::end_div();

    /*
     * Current scope summary.
     */
    if (
        $selectedgroupid > 0
        && isset($availablegroups[$selectedgroupid])
    ) {
        $currentscopename = format_string(
            $availablegroups[$selectedgroupid]->name,
            true,
            ['context' => $context]
        );
    } else {
        $currentscopename = 'Todos os grupos';
    }

    if (!empty($selecteduserids)) {
        $currentscopevalue =
            count($selecteduserids) === 1
                ? '1 aluno específico'
                : count($selecteduserids) . ' alunos específicos';
    } else if ($selectedgroupid > 0) {
        $currentscopevalue = 'Grupo inteiro';
    } else {
        $currentscopevalue = 'Todos os respondentes';
    }

    echo html_writer::start_div('feedbackdashboard-filter-scope');

    echo html_writer::start_div();

    echo html_writer::span(
        'Escopo atual',
        'feedbackdashboard-filter-scope-label'
    );

    echo html_writer::div(
        s($currentscopename . ' · ' . $currentscopevalue),
        'feedbackdashboard-filter-scope-value'
    );

    echo html_writer::end_div();

    echo html_writer::span(
        count($responders) . ' respondente(s) disponível(is)',
        'feedbackdashboard-filter-scope-count'
    );

    echo html_writer::end_div();

    if (empty($responders)) {
        $emptymessage = $selectedgroupid > 0
            ? 'Nenhum participante deste grupo respondeu esta pesquisa.'
            : 'Ainda não há alunos identificados que responderam esta pesquisa.';

        echo html_writer::div(
            s($emptymessage),
            'feedbackdashboard-filter-empty'
        );
    } else {
        echo html_writer::tag(
            'label',
            'Selecionar alunos específicos',
            [
                'for' => 'feedbackdashboard-student-search',
                'class' => 'form-label feedbackdashboard-filter-label',
            ]
        );

        echo html_writer::start_div(
            'feedbackdashboard-user-picker',
            [
                'id' => 'feedbackdashboard-user-picker',
            ]
        );

        echo html_writer::start_div(
            'feedbackdashboard-user-picker-box'
        );

        /*
         * Selected users are rendered as tags.
         */
        echo html_writer::start_div(
            'feedbackdashboard-selected-users',
            [
                'id' => 'feedbackdashboard-selected-users',
            ]
        );

        foreach ($selecteduserids as $selecteduserid) {
            if (!isset($responders[$selecteduserid])) {
                continue;
            }

            $selectedresponder =
                $responders[$selecteduserid];

            $selectedname =
                fullname($selectedresponder);

            echo html_writer::start_tag(
                'span',
                [
                    'class' => 'feedbackdashboard-user-tag',
                    'data-user-id' => $selecteduserid,
                ]
            );

            echo html_writer::span(
                s($selectedname),
                'feedbackdashboard-user-tag-label'
            );

            echo html_writer::tag(
                'button',
                '×',
                [
                    'type' => 'button',
                    'class' => 'feedbackdashboard-user-tag-remove',
                    'data-user-id' => $selecteduserid,
                    'title' => 'Remover aluno',
                    'aria-label' => 'Remover ' . $selectedname,
                ]
            );

            echo html_writer::end_tag('span');
        }

        echo html_writer::end_div();

        /*
         * This is the only student search field.
         */
        echo html_writer::empty_tag(
            'input',
            [
                'type' => 'search',
                'id' => 'feedbackdashboard-student-search',
                'class' => 'feedbackdashboard-user-search-input',
                'placeholder' => empty($selecteduserids)
                    ? 'Digite ou escolha um aluno...'
                    : 'Adicionar outro aluno...',
                'aria-autocomplete' => 'list',
                'aria-expanded' => 'false',
                'aria-controls' => 'feedbackdashboard-user-suggestions',
                'aria-describedby' => 'feedbackdashboard-student-help',
            ]
        );

        echo html_writer::end_div();

        /*
         * Preserve the existing users[] backend contract.
         */
        echo html_writer::start_div(
            '',
            [
                'id' => 'feedbackdashboard-selected-user-inputs',
            ]
        );

        foreach ($selecteduserids as $selecteduserid) {
            echo html_writer::empty_tag(
                'input',
                [
                    'type' => 'hidden',
                    'name' => 'users[]',
                    'value' => $selecteduserid,
                    'data-user-id' => $selecteduserid,
                ]
            );
        }

        echo html_writer::end_div();

        /*
         * Autocomplete options.
         */
        echo html_writer::start_div(
            'feedbackdashboard-user-suggestions',
            [
                'id' => 'feedbackdashboard-user-suggestions',
                'role' => 'listbox',
                'hidden' => 'hidden',
            ]
        );

        foreach ($responders as $responder) {
            $responderid =
                (int) $responder->id;

            $respondername =
                fullname($responder);

            $responderemail =
                (string) ($responder->email ?? '');

            $suggestioncontent =
                html_writer::span(
                    s($respondername),
                    'feedbackdashboard-user-suggestion-name'
                );

            if ($responderemail !== '') {
                $suggestioncontent .=
                    html_writer::span(
                        s($responderemail),
                        'feedbackdashboard-user-suggestion-email'
                    );
            }

            echo html_writer::tag(
                'button',
                $suggestioncontent,
                [
                    'type' => 'button',
                    'class' => 'feedbackdashboard-user-suggestion',
                    'data-user-id' => $responderid,
                    'data-user-name' => $respondername,
                    'data-user-email' => $responderemail,
                    'role' => 'option',
                ]
            );
        }

        echo html_writer::div(
            'Nenhum aluno encontrado.',
            'feedbackdashboard-user-picker-empty',
            [
                'id' => 'feedbackdashboard-no-user-suggestion',
                'hidden' => 'hidden',
            ]
        );

        echo html_writer::end_div();
        echo html_writer::end_div();

        $emptyselectiontext = $selectedgroupid > 0
            ? 'Nenhum aluno específico selecionado. O grupo inteiro será considerado.'
            : 'Nenhum aluno específico selecionado. Todos os respondentes serão considerados.';

        echo html_writer::start_div(
            'feedbackdashboard-filter-selection-row'
        );

        echo html_writer::tag(
            'div',
            empty($selecteduserids)
                ? $emptyselectiontext
                : (
                    count($selecteduserids) === 1
                        ? '1 aluno específico selecionado.'
                        : count($selecteduserids) . ' alunos específicos selecionados.'
                ),
            [
                'id' => 'feedbackdashboard-selection-live',
                'class' => 'feedbackdashboard-filter-selection-live',
                'role' => 'status',
                'aria-live' => 'polite',
                'data-empty-text' => $emptyselectiontext,
            ]
        );

        $clearstudentstext = $selectedgroupid > 0
            ? 'Usar grupo inteiro'
            : 'Usar todos os respondentes';

        $clearstudentsattributes = [
            'type' => 'button',
            'id' => 'feedbackdashboard-clear-selected-users',
            'class' => 'btn btn-sm btn-outline-secondary',
        ];

        if (empty($selecteduserids)) {
            $clearstudentsattributes['hidden'] = 'hidden';
        }

        echo html_writer::tag(
            'button',
            $clearstudentstext,
            $clearstudentsattributes
        );

        echo html_writer::end_div();

        echo html_writer::tag(
            'div',
            'Clique na caixa para ver os respondentes disponíveis ou digite parte do nome/e-mail. '
                . 'Você pode adicionar um ou vários alunos.',
            [
                'id' => 'feedbackdashboard-student-help',
                'class' => 'feedbackdashboard-user-picker-help',
            ]
        );
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div();

    /*
     * -------------------------------------------------------------
     * Actions and applied-filter summary.
     * -------------------------------------------------------------
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

    echo html_writer::link(
        $pageurl,
        'Limpar filtro',
        ['class' => 'btn btn-secondary']
    );

    $statusparts = [];

    if (
        $selectedgroupid > 0
        && isset($availablegroups[$selectedgroupid])
    ) {
        $statusgroupname = format_string(
            $availablegroups[$selectedgroupid]->name,
            true,
            ['context' => $context]
        );

        $statusparts[] = 'Grupo: ' . $statusgroupname;
    } else {
        $statusparts[] = 'Todos os grupos';
    }

    if (empty($selecteduserids)) {
        if ($selectedgroupid > 0) {
            $statusparts[] =
                'grupo inteiro (' . count($responders) . ' respondente(s))';
        } else {
            $statusparts[] =
                count($responders) . ' respondente(s)';
        }
    } else if (count($selecteduserids) === 1) {
        $statusparts[] = '1 aluno selecionado';
    } else {
        $statusparts[] =
            count($selecteduserids)
            . ' alunos selecionados';
    }

    echo html_writer::tag(
        'span',
        s(implode(' · ', $statusparts)),
        [
            'class' =>
                'feedbackdashboard-filter-group-status ms-1',
        ]
    );

    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    echo $OUTPUT->notification(
        'Esta pesquisa é anônima. O NPS é calculado normalmente, '
            . 'porém os filtros por aluno e grupo permanecem indisponíveis.',
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
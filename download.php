<?php
// This file is part of Moodle - https://moodle.org/

/**
 * PDF export for the Feedback Dashboard plugin.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');
require_once($CFG->libdir . '/pdflib.php');

/**
 * Converts Feedback content to compact plain text.
 *
 * @param string $text Raw text.
 * @return string
 */
function local_feedbackdashboard_pdf_clean_text(string $text): string {
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
function local_feedbackdashboard_pdf_normalise_hex($color, string $fallback = '#0F6CBF'): string {
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
 * Gets the primary colour from the current Moodle theme.
 *
 * @return string
 */
function local_feedbackdashboard_pdf_get_theme_primary_color(): string {
    global $CFG, $PAGE;

    $fallback = '#0F6CBF';
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
        $normalised = local_feedbackdashboard_pdf_normalise_hex($candidate, '');
        if ($normalised !== '') {
            return $normalised;
        }
    }

    return $fallback;
}

/**
 * Converts hexadecimal colour to RGB.
 *
 * @param string $hex Hexadecimal colour.
 * @return array
 */
function local_feedbackdashboard_pdf_hex_to_rgb(string $hex): array {
    $hex = ltrim(local_feedbackdashboard_pdf_normalise_hex($hex), '#');

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Mixes a colour with another colour.
 *
 * @param string $base Base colour.
 * @param string $target Target colour.
 * @param float $weight Target colour weight from 0 to 1.
 * @return string
 */
function local_feedbackdashboard_pdf_mix_color(
    string $base,
    string $target,
    float $weight
): string {
    $weight = max(0.0, min(1.0, $weight));
    [$br, $bg, $bb] = local_feedbackdashboard_pdf_hex_to_rgb($base);
    [$tr, $tg, $tb] = local_feedbackdashboard_pdf_hex_to_rgb($target);

    $r = (int) round($br * (1 - $weight) + $tr * $weight);
    $g = (int) round($bg * (1 - $weight) + $tg * $weight);
    $b = (int) round($bb * (1 - $weight) + $tb * $weight);

    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

/**
 * Applies a hex colour to TCPDF fill.
 *
 * @param pdf $pdf PDF object.
 * @param string $hex Colour.
 * @return void
 */
function local_feedbackdashboard_pdf_set_fill(pdf $pdf, string $hex): void {
    [$r, $g, $b] = local_feedbackdashboard_pdf_hex_to_rgb($hex);
    $pdf->SetFillColor($r, $g, $b);
}

/**
 * Applies a hex colour to TCPDF text.
 *
 * @param pdf $pdf PDF object.
 * @param string $hex Colour.
 * @return void
 */
function local_feedbackdashboard_pdf_set_text(pdf $pdf, string $hex): void {
    [$r, $g, $b] = local_feedbackdashboard_pdf_hex_to_rgb($hex);
    $pdf->SetTextColor($r, $g, $b);
}

/**
 * Applies a hex colour to TCPDF lines.
 *
 * @param pdf $pdf PDF object.
 * @param string $hex Colour.
 * @return void
 */
function local_feedbackdashboard_pdf_set_draw(pdf $pdf, string $hex): void {
    [$r, $g, $b] = local_feedbackdashboard_pdf_hex_to_rgb($hex);
    $pdf->SetDrawColor($r, $g, $b);
}

/**
 * Returns multiple-choice configuration.
 *
 * @param stdClass $item Feedback item.
 * @return array|null
 */
function local_feedbackdashboard_pdf_get_choice_config(stdClass $item): ?array {
    if (!in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
        return null;
    }

    $itemobject = feedback_get_item_class($item->typ);
    if (!$itemobject) {
        return null;
    }

    $info = $itemobject->get_info($item);
    $labels = [];
    $ismultiple = false;

    if ($item->typ === 'multichoice') {
        $options = explode(FEEDBACK_MULTICHOICE_LINE_SEP, $info->presentation);
        $ismultiple = ($info->subtype === 'c');

        foreach ($options as $index => $option) {
            $number = $index + 1;
            $label = local_feedbackdashboard_pdf_clean_text((string) $option);
            $labels[$number] = $label !== '' ? $label : 'Alternativa ' . $number;
        }
    } else {
        $options = explode(FEEDBACK_MULTICHOICERATED_LINE_SEP, $info->presentation);

        foreach ($options as $index => $option) {
            $number = $index + 1;
            $parts = explode(FEEDBACK_MULTICHOICERATED_VALUE_SEP, $option, 2);
            $weight = trim((string) ($parts[0] ?? ''));
            $rawtext = (string) ($parts[1] ?? $parts[0] ?? '');
            $label = local_feedbackdashboard_pdf_clean_text($rawtext);

            if ($label === '') {
                $label = 'Alternativa ' . $number;
            }

            if ($weight !== '' && trim($label) !== $weight) {
                $label = '(' . $weight . ') ' . $label;
            }

            $labels[$number] = $label;
        }
    }

    return [
        'labels' => $labels,
        'ismultiple' => $ismultiple,
    ];
}

/**
 * Decodes a stored Feedback value for display.
 *
 * @param stdClass $item Feedback item.
 * @param string $value Stored value.
 * @return string
 */
function local_feedbackdashboard_pdf_decode_value(stdClass $item, string $value): string {
    $value = trim($value);

    if ($value === '') {
        return '-';
    }

    $choiceconfig = local_feedbackdashboard_pdf_get_choice_config($item);

    if ($choiceconfig !== null) {
        $indexes = $choiceconfig['ismultiple']
            ? explode(FEEDBACK_MULTICHOICE_LINE_SEP, $value)
            : [$value];

        $answers = [];

        foreach ($indexes as $index) {
            $index = (int) trim((string) $index);
            if (isset($choiceconfig['labels'][$index])) {
                $answers[] = $choiceconfig['labels'][$index];
            }
        }

        return !empty($answers) ? implode(', ', $answers) : '-';
    }

    return local_feedbackdashboard_pdf_clean_text($value);
}

/**
 * Builds chart counts for one multiple-choice item.
 *
 * @param stdClass $item Feedback item.
 * @param array $completions Completed responses.
 * @param array $valuesbycompletion Values indexed by completion and item.
 * @return array|null
 */
function local_feedbackdashboard_pdf_build_chart_data(
    stdClass $item,
    array $completions,
    array $valuesbycompletion
): ?array {
    $choiceconfig = local_feedbackdashboard_pdf_get_choice_config($item);
    if ($choiceconfig === null || empty($choiceconfig['labels'])) {
        return null;
    }

    $counts = array_fill_keys(array_keys($choiceconfig['labels']), 0);
    $answered = 0;

    foreach ($completions as $completion) {
        $value = trim((string) ($valuesbycompletion[$completion->id][$item->id] ?? ''));
        if ($value === '' || $value === '0') {
            continue;
        }

        $answered++;
        $indexes = $choiceconfig['ismultiple']
            ? explode(FEEDBACK_MULTICHOICE_LINE_SEP, $value)
            : [$value];

        foreach ($indexes as $index) {
            $index = (int) trim((string) $index);
            if (array_key_exists($index, $counts)) {
                $counts[$index]++;
            }
        }
    }

    return [
        'labels' => array_values($choiceconfig['labels']),
        'counts' => array_values($counts),
        'answered' => $answered,
    ];
}

/**
 * Draws the common page background/header/footer.
 *
 * @param pdf $pdf PDF object.
 * @param string $primary Primary colour.
 * @param string $dark Dark theme colour.
 * @param string $light Light theme colour.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_page_base(
    pdf $pdf,
    string $primary,
    string $dark,
    string $light
): void {
    $pagewidth = $pdf->getPageWidth();
    $pageheight = $pdf->getPageHeight();

    local_feedbackdashboard_pdf_set_fill($pdf, $light);
    $pdf->Rect(0, 0, $pagewidth, $pageheight, 'F');

    local_feedbackdashboard_pdf_set_fill($pdf, $dark);
    $pdf->Rect(0, 0, $pagewidth, 5.5, 'F');

    local_feedbackdashboard_pdf_set_fill($pdf, $primary);
    $pdf->Rect(0, 5.5, $pagewidth, 1.8, 'F');

    local_feedbackdashboard_pdf_set_draw($pdf, '#D7DEE7');
    $pdf->Line(12, $pageheight - 10, $pagewidth - 12, $pageheight - 10);

    $pdf->SetFont('helvetica', '', 7.5);
    local_feedbackdashboard_pdf_set_text($pdf, '#667482');
    $pdf->SetXY($pagewidth - 55, $pageheight - 8.3);
    $pdf->Cell(43, 4, 'Página ' . $pdf->getPage(), 0, 0, 'R');
}

/**
 * Draws one summary card.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X position.
 * @param float $y Y position.
 * @param float $w Width.
 * @param float $h Height.
 * @param string $title Card title.
 * @param string $value Card value.
 * @param string $primary Primary theme colour.
 * @param string $border Border colour.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_card(
    pdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    string $title,
    string $value,
    string $primary,
    string $border
): void {
    local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
    local_feedbackdashboard_pdf_set_draw($pdf, $border);
    $pdf->RoundedRect($x, $y, $w, $h, 1.5, '1111', 'DF');

    local_feedbackdashboard_pdf_set_fill($pdf, $primary);
    $pdf->Rect($x, $y, $w, 2.2, 'F');

    $pdf->SetFont('helvetica', '', 8);
    local_feedbackdashboard_pdf_set_text($pdf, '#657382');
    $pdf->SetXY($x + 4, $y + 6);
    $pdf->Cell($w - 8, 4, $title, 0, 0, 'C');

    $pdf->SetFont('helvetica', 'B', 18);
    local_feedbackdashboard_pdf_set_text($pdf, $primary);
    $pdf->SetXY($x + 4, $y + 11.5);
    $pdf->Cell($w - 8, 8, $value, 0, 0, 'C');
}

/**
 * Shortens a chart label.
 *
 * @param string $text Label.
 * @param int $maxlength Max length.
 * @return string
 */
function local_feedbackdashboard_pdf_short_label(string $text, int $maxlength = 20): string {
    if (core_text::strlen($text) <= $maxlength) {
        return $text;
    }

    return core_text::substr($text, 0, $maxlength - 1) . '...';
}

/**
 * Draws a simple vertical bar chart directly with TCPDF.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X position.
 * @param float $y Y position.
 * @param float $w Width.
 * @param float $h Height.
 * @param string $title Question title.
 * @param array $labels Bar labels.
 * @param array $counts Bar counts.
 * @param string $primary Primary colour.
 * @param string $border Border colour.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_bar_chart(
    pdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    string $title,
    array $labels,
    array $counts,
    string $primary,
    string $border
): void {
    local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
    local_feedbackdashboard_pdf_set_draw($pdf, $border);
    $pdf->RoundedRect($x, $y, $w, $h, 1.5, '1111', 'DF');

    $pdf->SetFont('helvetica', 'B', 9.2);
    local_feedbackdashboard_pdf_set_text($pdf, '#263746');
    $pdf->SetXY($x + 5, $y + 4);
    $pdf->MultiCell($w - 10, 8, $title, 0, 'L', false, 1);

    $plotx = $x + 10;
    $ploty = $y + 17;
    $plotw = $w - 18;
    $ploth = $h - 32;

    if ($plotw <= 0 || $ploth <= 0 || empty($labels)) {
        return;
    }

    $maxcount = max(1, (int) max($counts ?: [0]));

    local_feedbackdashboard_pdf_set_draw($pdf, '#DCE3EA');
    for ($line = 0; $line <= 4; $line++) {
        $liney = $ploty + ($ploth * $line / 4);
        $pdf->Line($plotx, $liney, $plotx + $plotw, $liney);
    }

    $barcount = count($labels);
    $slotwidth = $plotw / max(1, $barcount);
    $barwidth = max(2.2, min(10.0, $slotwidth * 0.58));
    $baseline = $ploty + $ploth;

    foreach ($labels as $index => $label) {
        $count = (int) ($counts[$index] ?? 0);
        $barheight = ($count / $maxcount) * ($ploth - 7);
        $barx = $plotx + ($index * $slotwidth) + (($slotwidth - $barwidth) / 2);
        $bary = $baseline - $barheight;

        if ($count > 0) {
            local_feedbackdashboard_pdf_set_fill($pdf, $primary);
            $pdf->RoundedRect($barx, $bary, $barwidth, $barheight, 0.8, '1111', 'F');
        }

        $pdf->SetFont('helvetica', 'B', 7);
        local_feedbackdashboard_pdf_set_text($pdf, '#334455');
        $pdf->SetXY($barx - 3, max($ploty, $bary - 5));
        $pdf->Cell($barwidth + 6, 4, (string) $count, 0, 0, 'C');

        $pdf->SetFont('helvetica', '', $barcount > 8 ? 5.8 : 6.7);
        local_feedbackdashboard_pdf_set_text($pdf, '#596978');
        $pdf->SetXY($plotx + ($index * $slotwidth), $baseline + 1.2);
        $pdf->MultiCell(
            $slotwidth,
            3,
            local_feedbackdashboard_pdf_short_label((string) $label, $barcount > 8 ? 10 : 18),
            0,
            'C',
            false,
            0
        );
    }
}

/**
 * Draws the response page title and table header.
 *
 * @param pdf $pdf PDF object.
 * @param stdClass $course Course.
 * @param stdClass $feedback Feedback.
 * @param stdClass|null $scoreitem Main choice item.
 * @param stdClass|null $commentitem Main text item.
 * @param string $primary Primary colour.
 * @param string $dark Dark colour.
 * @param string $light Light colour.
 * @param bool $continued Whether this is a continuation page.
 * @return array Table geometry.
 */
function local_feedbackdashboard_pdf_draw_response_page_header(
    pdf $pdf,
    stdClass $course,
    stdClass $feedback,
    ?stdClass $scoreitem,
    ?stdClass $commentitem,
    string $primary,
    string $dark,
    string $light,
    bool $continued = false
): array {
    $pdf->AddPage();
    local_feedbackdashboard_pdf_draw_page_base($pdf, $primary, $dark, $light);

    $pdf->SetFont('helvetica', 'B', 18);
    local_feedbackdashboard_pdf_set_text($pdf, $dark);
    $pdf->SetXY(12, 16);
    $pdf->Cell(200, 8, 'Respostas e comentários' . ($continued ? ' - continuação' : ''), 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 8.5);
    local_feedbackdashboard_pdf_set_text($pdf, '#586878');
    $pdf->SetX(12);
    $pdf->Cell(270, 5, 'Curso: ' . format_string($course->fullname), 0, 1, 'L');
    $pdf->SetX(12);
    $pdf->Cell(270, 5, 'Pesquisa: ' . format_string($feedback->name), 0, 1, 'L');

    $x = 12.0;
    $y = 36.0;
    $namew = 58.0;
    $scorew = 48.0;
    $commentw = $pdf->getPageWidth() - 24 - $namew - $scorew;
    $headerh = 11.0;

    local_feedbackdashboard_pdf_set_fill($pdf, $primary);
    local_feedbackdashboard_pdf_set_draw($pdf, $primary);
    local_feedbackdashboard_pdf_set_text($pdf, '#FFFFFF');
    $pdf->SetFont('helvetica', 'B', 7.6);

    $scoretitle = $scoreitem ? format_string($scoreitem->name) : 'Resposta';
    $commenttitle = $commentitem ? format_string($commentitem->name) : 'Comentário';

    $pdf->SetXY($x, $y);
    $pdf->MultiCell($namew, $headerh, 'Participante', 1, 'C', true, 0, '', '', true, 0, false, true, $headerh, 'M');
    $pdf->MultiCell($scorew, $headerh, $scoretitle, 1, 'C', true, 0, '', '', true, 0, false, true, $headerh, 'M');
    $pdf->MultiCell($commentw, $headerh, $commenttitle, 1, 'C', true, 1, '', '', true, 0, false, true, $headerh, 'M');

    return [
        'x' => $x,
        'y' => $y + $headerh,
        'namew' => $namew,
        'scorew' => $scorew,
        'commentw' => $commentw,
    ];
}

/*
 * -------------------------------------------------------------------------
 * Parameters, permissions and data.
 * -------------------------------------------------------------------------
 */

$id = required_param('id', PARAM_INT);
$useridsparam = optional_param('userids', '', PARAM_SEQUENCE);
$selecteduserids = $useridsparam === ''
    ? []
    : array_values(array_unique(array_map('intval', explode(',', $useridsparam))));

[$course, $cm] = get_course_and_cm_from_cmid($id, 'feedback');
require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('local/feedbackdashboard:view', $context);
require_capability('mod/feedback:viewreports', $context);

$feedback = $DB->get_record('feedback', ['id' => $cm->instance], '*', MUST_EXIST);
$isanonymous = ((int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES);

if ($isanonymous) {
    $selecteduserids = [];
}

$PAGE->set_url(new moodle_url('/local/feedbackdashboard/download.php', ['id' => $cm->id]));
$PAGE->set_course($course);
$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

$items = $DB->get_records_select(
    'feedback_item',
    'feedback = :feedbackid AND hasvalue = :hasvalue',
    [
        'feedbackid' => $feedback->id,
        'hasvalue' => 1,
    ],
    'position ASC'
);

$params = [
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
        'pdfuser'
    );
    $completionssql .= " AND fbc.userid {$usersql}";
    $params += $userparams;
}

$completionssql .= ' ORDER BY fbc.timemodified ASC, fbc.id ASC';
$completions = $DB->get_records_sql($completionssql, $params);

$valuesbycompletion = [];

if (!empty($completions) && !empty($items)) {
    [$completionsinsql, $completionparams] = $DB->get_in_or_equal(
        array_keys($completions),
        SQL_PARAMS_NAMED,
        'pdfcompletion'
    );
    [$itemsinsql, $itemparams] = $DB->get_in_or_equal(
        array_keys($items),
        SQL_PARAMS_NAMED,
        'pdfitem'
    );

    $valuerecords = $DB->get_records_sql(
        "SELECT id, completed, item, value
           FROM {feedback_value}
          WHERE completed {$completionsinsql}
            AND item {$itemsinsql}",
        $completionparams + $itemparams
    );

    foreach ($valuerecords as $valuerecord) {
        $valuesbycompletion[(int) $valuerecord->completed][(int) $valuerecord->item] = (string) $valuerecord->value;
    }
}

$chartdatasets = [];
$scoreitem = null;
$commentitem = null;

foreach ($items as $item) {
    if ($scoreitem === null && in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
        $scoreitem = $item;
    }

    if ($commentitem === null && in_array($item->typ, ['textarea', 'textfield'], true)) {
        $commentitem = $item;
    }

    $chartdata = local_feedbackdashboard_pdf_build_chart_data($item, $completions, $valuesbycompletion);
    if ($chartdata !== null) {
        $chartdatasets[] = [
            'item' => $item,
            'data' => $chartdata,
        ];
    }
}

/*
 * -------------------------------------------------------------------------
 * Theme colours.
 * -------------------------------------------------------------------------
 */

$primary = local_feedbackdashboard_pdf_get_theme_primary_color();
$dark = local_feedbackdashboard_pdf_mix_color($primary, '#000000', 0.48);
$light = local_feedbackdashboard_pdf_mix_color($primary, '#FFFFFF', 0.95);
$border = local_feedbackdashboard_pdf_mix_color($primary, '#FFFFFF', 0.78);

/*
 * -------------------------------------------------------------------------
 * PDF document.
 * -------------------------------------------------------------------------
 */

$pdf = new pdf('L', 'mm', 'A4', true, 'UTF-8');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(false, 0);
$pdf->SetMargins(0, 0, 0);
$pdf->SetCreator('Moodle - Feedback Dashboard');
$pdf->SetAuthor(fullname($USER));
$pdf->SetTitle(format_string($feedback->name));
$pdf->SetSubject('Relatório de Feedback');

/* Page 1 - Dashboard. */
$pdf->AddPage();
local_feedbackdashboard_pdf_draw_page_base($pdf, $primary, $dark, $light);

$pdf->SetFont('helvetica', 'B', 20);
local_feedbackdashboard_pdf_set_text($pdf, $dark);
$pdf->SetXY(12, 15);
$pdf->Cell(185, 9, 'Dashboard de Feedback', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
local_feedbackdashboard_pdf_set_text($pdf, '#536271');
$pdf->SetX(12);
$pdf->Cell(190, 5, 'Curso: ' . format_string($course->fullname), 0, 1, 'L');
$pdf->SetX(12);
$pdf->Cell(190, 5, 'Pesquisa: ' . format_string($feedback->name), 0, 1, 'L');

$generatedat = userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(205, 17);
$pdf->Cell(80, 5, 'Gerado em: ' . $generatedat, 0, 1, 'R');

if ($isanonymous) {
    $filtertext = 'Pesquisa anônima - resultados agregados';
} else if (empty($selecteduserids)) {
    $filtertext = 'Filtro: todos os participantes';
} else {
    $filtertext = 'Filtro: ' . count($selecteduserids) . ' participante(s) selecionado(s)';
}

$pdf->SetXY(205, 23);
$pdf->Cell(80, 5, $filtertext, 0, 1, 'R');

$cardy = 38;
$cardgap = 6;
$cardw = (273 - ($cardgap * 2)) / 3;
local_feedbackdashboard_pdf_draw_card(
    $pdf,
    12,
    $cardy,
    $cardw,
    23,
    'Respostas consideradas',
    (string) count($completions),
    $primary,
    $border
);
local_feedbackdashboard_pdf_draw_card(
    $pdf,
    12 + $cardw + $cardgap,
    $cardy,
    $cardw,
    23,
    'Questões',
    (string) count($items),
    $primary,
    $border
);
local_feedbackdashboard_pdf_draw_card(
    $pdf,
    12 + (($cardw + $cardgap) * 2),
    $cardy,
    $cardw,
    23,
    'Modo',
    $isanonymous ? 'Anônimo' : 'Identificado',
    $primary,
    $border
);

$visiblecharts = array_slice($chartdatasets, 0, 4);
$chartcount = count($visiblecharts);

if ($chartcount === 0) {
    local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
    local_feedbackdashboard_pdf_set_draw($pdf, $border);
    $pdf->RoundedRect(12, 70, 273, 105, 1.5, '1111', 'DF');
    $pdf->SetFont('helvetica', '', 10);
    local_feedbackdashboard_pdf_set_text($pdf, '#586878');
    $pdf->SetXY(22, 110);
    $pdf->MultiCell(
        253,
        8,
        'Não há perguntas de alternativa compatíveis com gráfico nesta pesquisa.',
        0,
        'C'
    );
} else if ($chartcount <= 2) {
    $chartgap = 7;
    $chartw = $chartcount === 1 ? 273 : (273 - $chartgap) / 2;

    foreach ($visiblecharts as $index => $dataset) {
        $x = 12 + ($index * ($chartw + $chartgap));
        local_feedbackdashboard_pdf_draw_bar_chart(
            $pdf,
            $x,
            70,
            $chartw,
            105,
            format_string($dataset['item']->name),
            $dataset['data']['labels'],
            $dataset['data']['counts'],
            $primary,
            $border
        );
    }
} else {
    $chartgap = 7;
    $rowgap = 6;
    $chartw = (273 - $chartgap) / 2;
    $charth = (105 - $rowgap) / 2;

    foreach ($visiblecharts as $index => $dataset) {
        $column = $index % 2;
        $row = intdiv($index, 2);
        $x = 12 + ($column * ($chartw + $chartgap));
        $y = 70 + ($row * ($charth + $rowgap));

        local_feedbackdashboard_pdf_draw_bar_chart(
            $pdf,
            $x,
            $y,
            $chartw,
            $charth,
            format_string($dataset['item']->name),
            $dataset['data']['labels'],
            $dataset['data']['counts'],
            $primary,
            $border
        );
    }
}

if (count($chartdatasets) > 4) {
    $pdf->SetFont('helvetica', 'I', 7.5);
    local_feedbackdashboard_pdf_set_text($pdf, '#687785');
    $pdf->SetXY(12, 179);
    $pdf->Cell(273, 4, 'Observação: nesta versão do PDF são exibidos até 4 gráficos na primeira página.', 0, 0, 'R');
}

/* Page 2 - Responses and comments. */
$table = local_feedbackdashboard_pdf_draw_response_page_header(
    $pdf,
    $course,
    $feedback,
    $scoreitem,
    $commentitem,
    $primary,
    $dark,
    $light,
    false
);

if (empty($completions)) {
    $pdf->SetFont('helvetica', '', 10);
    local_feedbackdashboard_pdf_set_text($pdf, '#586878');
    $pdf->SetXY(12, 60);
    $pdf->Cell(273, 8, 'Não há respostas para os filtros atuais.', 0, 1, 'C');
} else {
    $rowindex = 0;
    $bottomlimit = $pdf->getPageHeight() - 13;

    foreach ($completions as $completion) {
        $rowindex++;
        $name = $isanonymous ? 'Resposta ' . $rowindex : fullname($completion);

        $scorevalue = '-';
        if ($scoreitem !== null) {
            $rawscore = (string) ($valuesbycompletion[$completion->id][$scoreitem->id] ?? '');
            $scorevalue = local_feedbackdashboard_pdf_decode_value($scoreitem, $rawscore);
        }

        $commentvalue = '-';
        if ($commentitem !== null) {
            $rawcomment = (string) ($valuesbycompletion[$completion->id][$commentitem->id] ?? '');
            $commentvalue = local_feedbackdashboard_pdf_decode_value($commentitem, $rawcomment);
        }

        $pdf->SetFont('helvetica', '', 7.3);
        $nameheight = $pdf->getStringHeight($table['namew'] - 5, $name);
        $scoreheight = $pdf->getStringHeight($table['scorew'] - 5, $scorevalue);
        $commentheight = $pdf->getStringHeight($table['commentw'] - 5, $commentvalue);
        $rowheight = max(8.5, $nameheight + 3, $scoreheight + 3, $commentheight + 3);

        if ($table['y'] + $rowheight > $bottomlimit) {
            $table = local_feedbackdashboard_pdf_draw_response_page_header(
                $pdf,
                $course,
                $feedback,
                $scoreitem,
                $commentitem,
                $primary,
                $dark,
                $light,
                true
            );
        }

        $rowfill = ($rowindex % 2 === 0) ? '#F8FAFC' : '#FFFFFF';
        local_feedbackdashboard_pdf_set_fill($pdf, $rowfill);
        local_feedbackdashboard_pdf_set_draw($pdf, '#D8E0E8');
        local_feedbackdashboard_pdf_set_text($pdf, '#263746');
        $pdf->SetFont('helvetica', '', 7.3);

        $pdf->SetXY($table['x'], $table['y']);
        $pdf->MultiCell(
            $table['namew'],
            $rowheight,
            $name,
            1,
            'L',
            true,
            0,
            '',
            '',
            true,
            0,
            false,
            true,
            $rowheight,
            'M'
        );

        $pdf->MultiCell(
            $table['scorew'],
            $rowheight,
            $scorevalue,
            1,
            'C',
            true,
            0,
            '',
            '',
            true,
            0,
            false,
            true,
            $rowheight,
            'M'
        );

        $pdf->MultiCell(
            $table['commentw'],
            $rowheight,
            $commentvalue,
            1,
            'L',
            true,
            1,
            '',
            '',
            true,
            0,
            false,
            true,
            $rowheight,
            'M'
        );

        $table['y'] += $rowheight;
    }
}

\core\session\manager::write_close();

$filename = clean_filename('dashboard_' . $feedback->name . '.pdf');
$pdf->Output($filename, 'D');
exit;
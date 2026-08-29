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
 * Finds the first logo image inside local/feedbackdashboard/imgs.
 *
 * If a file called logo.png exists, it is preferred.
 *
 * @return string|null
 */
function local_feedbackdashboard_pdf_find_logo(): ?string {
    $directory = __DIR__ . '/imgs';

    if (!is_dir($directory)) {
        return null;
    }

    $preferred = [
        $directory . '/logo.png',
        $directory . '/logo.jpg',
        $directory . '/logo.jpeg',
    ];

    foreach ($preferred as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    $files = [];

    foreach (['*.png', '*.jpg', '*.jpeg', '*.PNG', '*.JPG', '*.JPEG'] as $pattern) {
        $matches = glob($directory . '/' . $pattern);
        if (is_array($matches)) {
            $files = array_merge($files, $matches);
        }
    }

    $files = array_values(array_unique(array_filter($files, 'is_readable')));
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return $files[0] ?? null;
}

/**
 * Finds the main institutional logo configured in Moodle.
 *
 * The logo is read directly from Moodle File API and copied
 * to a temporary local file so TCPDF can render it reliably.
 *
 * @return string|null
 */
function local_feedbackdashboard_pdf_find_moodle_logo(): ?string {
    static $resolved = false;
    static $cachedpath = null;

    if ($resolved) {
        return $cachedpath;
    }

    $resolved = true;

    $context = context_system::instance();
    $fs = get_file_storage();

    /*
     * First try the main Moodle logo.
     * If it does not exist, try the compact logo.
     */
    foreach (['logo', 'logocompact'] as $filearea) {
        $configured = trim(
            (string) get_config(
                'core_admin',
                $filearea
            )
        );

        if ($configured === '') {
            continue;
        }

        $configured = str_replace(
            '\\',
            '/',
            $configured
        );

        $filename = basename($configured);
        $directory = dirname($configured);

        if (
            $directory === '.'
            || $directory === '/'
        ) {
            $filepath = '/';
        } else {
            $filepath =
                '/'
                . trim($directory, '/')
                . '/';
        }

        $storedfile = $fs->get_file(
            $context->id,
            'core_admin',
            $filearea,
            0,
            $filepath,
            $filename
        );

        if (
            !$storedfile
            || $storedfile->is_directory()
        ) {
            continue;
        }

        $tempdir = make_temp_directory(
            'local_feedbackdashboard'
        );

        $extension = strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );

        $tempfilename =
            'moodle_'
            . $filearea
            . '_'
            . $storedfile->get_contenthash();

        if ($extension !== '') {
            $tempfilename .= '.' . $extension;
        }

        $temppath =
            $tempdir
            . DIRECTORY_SEPARATOR
            . $tempfilename;

        if (!is_readable($temppath)) {
            if (!$storedfile->copy_content_to($temppath)) {
                continue;
            }
        }

        $cachedpath = $temppath;

        return $cachedpath;
    }

    return null;
}

/**
 * Draws the report logo and the Moodle institutional logo.
 *
 * @param pdf $pdf PDF object.
 * @param string|null $logopath Report/plugin logo path.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_logo(
    pdf $pdf,
    ?string $logopath
): void {

    /*
     * Right edge used by the logos.
     */
    $rightedge =
        $pdf->getPageWidth() - 12.0;

    $y = 12.0;
    $gap = 5.0;

    /*
     * -------------------------------------------------------------
     * Existing report/plugin logo.
     *
     * It remains in exactly the same position as before.
     * -------------------------------------------------------------
     */

    if (
        $logopath !== null
        && is_readable($logopath)
    ) {
        $imagesize =
            @getimagesize($logopath);

        if (
            is_array($imagesize)
            && !empty($imagesize[0])
            && !empty($imagesize[1])
        ) {
            $maxwidth = 56.0;
            $maxheight = 12.5;

            $scale = min(
                $maxwidth / $imagesize[0],
                $maxheight / $imagesize[1]
            );

            $width = max(
                1.0,
                $imagesize[0] * $scale
            );

            $height = max(
                1.0,
                $imagesize[1] * $scale
            );

            $x =
                $rightedge - $width;

            $pdf->Image(
                $logopath,
                $x,
                $y,
                $width,
                $height,
                '',
                '',
                '',
                false,
                300
            );

            /*
             * The Moodle logo will be placed
             * to the LEFT of this logo.
             */
            $rightedge =
                $x - $gap;
        }
    }

    /*
     * -------------------------------------------------------------
     * Moodle institutional logo.
     * -------------------------------------------------------------
     */

    $moodlelogopath =
        local_feedbackdashboard_pdf_find_moodle_logo();

    if (
        $moodlelogopath === null
        || !is_readable($moodlelogopath)
    ) {
        return;
    }

    $imagesize =
        @getimagesize($moodlelogopath);

    if (
        !is_array($imagesize)
        || empty($imagesize[0])
        || empty($imagesize[1])
    ) {
        return;
    }

    /*
     * Slightly smaller than the report logo
     * so the two logos remain balanced.
     */
    $maxwidth = 46.0;
    $maxheight = 12.5;

    $scale = min(
        $maxwidth / $imagesize[0],
        $maxheight / $imagesize[1]
    );

    $width = max(
        1.0,
        $imagesize[0] * $scale
    );

    $height = max(
        1.0,
        $imagesize[1] * $scale
    );

    $x =
        $rightedge - $width;

    $pdf->Image(
        $moodlelogopath,
        $x,
        $y,
        $width,
        $height,
        '',
        '',
        '',
        false,
        300
    );
}

/**
 * Extracts an integer NPS score from a choice label.
 *
 * @param string $label Choice label.
 * @return int|null
 */
function local_feedbackdashboard_pdf_extract_score_from_label(string $label): ?int {
    $label = local_feedbackdashboard_pdf_clean_text($label);

    if (preg_match('/^\s*\(?\s*(10|[0-9])\s*\)?\s*$/u', $label, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/^\s*\(?\s*(10|[0-9])\s*\)?\s*(?:[-–—:]|\s)/u', $label, $matches)) {
        return (int) $matches[1];
    }

    return null;
}

/**
 * Returns choice configuration, including NPS-compatible scores.
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
    $scores = [];
    $ismultiple = false;

    if ($item->typ === 'multichoice') {
        $options = explode(FEEDBACK_MULTICHOICE_LINE_SEP, $info->presentation);
        $ismultiple = ($info->subtype === 'c');

        foreach ($options as $index => $option) {
            $number = $index + 1;
            $label = local_feedbackdashboard_pdf_clean_text((string) $option);

            if ($label === '') {
                $label = 'Alternativa ' . $number;
            }

            $labels[$number] = $label;
            $scores[$number] = local_feedbackdashboard_pdf_extract_score_from_label($label);
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

            $labels[$number] = $label;

            if (is_numeric(str_replace(',', '.', $weight))) {
                $numericweight = (float) str_replace(',', '.', $weight);
                $rounded = (int) round($numericweight);
                $scores[$number] = abs($numericweight - $rounded) < 0.00001 ? $rounded : null;
            } else {
                $scores[$number] = local_feedbackdashboard_pdf_extract_score_from_label($label);
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
 * Determines whether an item represents a 0-to-10 NPS question.
 *
 * @param stdClass $item Feedback item.
 * @return bool
 */
function local_feedbackdashboard_pdf_is_nps_item(stdClass $item): bool {
    $config = local_feedbackdashboard_pdf_get_choice_config($item);

    if ($config === null || $config['ismultiple']) {
        return false;
    }

    $scores = array_values(array_filter(
        $config['scores'],
        static fn($value) => $value !== null
    ));

    $scores = array_values(array_unique(array_map('intval', $scores)));
    sort($scores);

    // Standard NPS requires the complete single-choice scale from 0 to 10.
    return $scores === range(0, 10);
}

/**
 * Finds the first NPS question.
 *
 * @param array $items Feedback items.
 * @return stdClass|null
 */
function local_feedbackdashboard_pdf_find_nps_item(array $items): ?stdClass {
    foreach ($items as $item) {
        if (local_feedbackdashboard_pdf_is_nps_item($item)) {
            return $item;
        }
    }

    return null;
}

/**
 * Decodes the stored value of an NPS question into the actual 0-to-10 score.
 *
 * @param stdClass $item NPS item.
 * @param string $storedvalue Stored Feedback value.
 * @return int|null
 */
function local_feedbackdashboard_pdf_decode_nps_score(stdClass $item, string $storedvalue): ?int {
    $storedvalue = trim($storedvalue);

    if ($storedvalue === '' || $storedvalue === '0') {
        return null;
    }

    $config = local_feedbackdashboard_pdf_get_choice_config($item);

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
 * Calculates NPS metrics.
 *
 * @param stdClass $npsitem NPS question.
 * @param array $completions Completion records.
 * @param array $valuesbycompletion Values indexed by completion ID and item ID.
 * @return array
 */
function local_feedbackdashboard_pdf_calculate_nps(
    stdClass $npsitem,
    array $completions,
    array $valuesbycompletion
): array {
    $scores = [];
    $scorecounts = array_fill(0, 11, 0);

    foreach ($completions as $completion) {
        $rawvalue = (string) ($valuesbycompletion[$completion->id][$npsitem->id] ?? '');
        $score = local_feedbackdashboard_pdf_decode_nps_score($npsitem, $rawvalue);

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
 * Builds all open text answers for one completion.
 *
 * @param int $completionid Completion ID.
 * @param array $textitems Text items.
 * @param array $valuesbycompletion Values indexed by completion and item.
 * @return string
 */
function local_feedbackdashboard_pdf_build_open_answers(
    int $completionid,
    array $textitems,
    array $valuesbycompletion
): string {
    $answers = [];

    foreach ($textitems as $item) {
        $rawvalue = (string) ($valuesbycompletion[$completionid][$item->id] ?? '');
        $answer = local_feedbackdashboard_pdf_clean_text($rawvalue);

        if ($answer === '') {
            continue;
        }

        if (count($textitems) === 1) {
            $answers[] = $answer;
        } else {
            $answers[] = format_string($item->name) . ': ' . $answer;
        }
    }

    return empty($answers) ? '-' : implode(' | ', $answers);
}

/**
 * Returns the visual style for one NPS score in the response table.
 *
 * @param int|null $score Decoded NPS score.
 * @param string $fallbackfill Default row background.
 * @param string $fallbacktext Default score text colour.
 * @param string $fallbackborder Default table border colour.
 * @return array
 */
function local_feedbackdashboard_pdf_get_score_style(
    ?int $score,
    string $fallbackfill,
    string $fallbacktext,
    string $fallbackborder
): array {
    if ($score === null) {
        return [
            'fill' => $fallbackfill,
            'text' => $fallbacktext,
            'border' => $fallbackborder,
        ];
    }

    if ($score >= 9) {
        return [
            'fill' => '#E3F4E7',
            'text' => '#176B31',
            'border' => '#79B88A',
        ];
    }

    if ($score >= 7) {
        return [
            'fill' => '#FFF4CC',
            'text' => '#846200',
            'border' => '#D5B33E',
        ];
    }

    return [
        'fill' => '#FDE5E5',
        'text' => '#9C1F1F',
        'border' => '#D87979',
    ];
}

/**
 * Builds and sorts the response rows for the PDF.
 *
 * Valid NPS scores are listed first from 10 down to 0. Responses without
 * a valid score are kept at the end. Equal scores retain chronological order.
 *
 * @param array $completions Feedback completion records.
 * @param stdClass|null $npsitem NPS item, when detected.
 * @param array $textitems Open-text Feedback items.
 * @param array $valuesbycompletion Values indexed by completion and item.
 * @param bool $isanonymous Whether the Feedback is anonymous.
 * @return array
 */
function local_feedbackdashboard_pdf_build_response_rows(
    array $completions,
    ?stdClass $npsitem,
    array $textitems,
    array $valuesbycompletion,
    bool $isanonymous
): array {
    $rows = [];

    foreach ($completions as $completion) {
        $score = null;

        if ($npsitem !== null) {
            $rawscore = (string) ($valuesbycompletion[$completion->id][$npsitem->id] ?? '');
            $score = local_feedbackdashboard_pdf_decode_nps_score($npsitem, $rawscore);
        }

        $rows[] = [
            'completionid' => (int) $completion->id,
            'timemodified' => (int) $completion->timemodified,
            'name' => $isanonymous ? '' : fullname($completion),
            'score' => $score,
            'scorevalue' => $score === null ? '-' : (string) $score,
            'comment' => local_feedbackdashboard_pdf_build_open_answers(
                (int) $completion->id,
                $textitems,
                $valuesbycompletion
            ),
        ];
    }

    usort($rows, static function(array $left, array $right): int {
        $leftvalid = $left['score'] !== null;
        $rightvalid = $right['score'] !== null;

        if ($leftvalid !== $rightvalid) {
            return $leftvalid ? -1 : 1;
        }

        if ($leftvalid && $rightvalid && $left['score'] !== $right['score']) {
            return $right['score'] <=> $left['score'];
        }

        if ($left['timemodified'] !== $right['timemodified']) {
            return $left['timemodified'] <=> $right['timemodified'];
        }

        return $left['completionid'] <=> $right['completionid'];
    });

    return $rows;
}

/**
 * Draws the compact colour legend used above the response table.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X position.
 * @param float $y Y position.
 * @param string $dark Main text colour.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_score_legend(
    pdf $pdf,
    float $x,
    float $y,
    string $dark
): void {
    $pdf->SetFont('helvetica', 'B', 6.8);
    local_feedbackdashboard_pdf_set_text($pdf, '#5B6875');
    $pdf->SetXY($x, $y + 0.6);
    $pdf->Cell(42, 5, 'Ordem: maior nota para menor', 0, 0, 'L');

    $items = [
        [
            'label' => '9-10 Promotores',
            'fill' => '#E3F4E7',
            'text' => '#176B31',
            'border' => '#79B88A',
            'width' => 36.0,
        ],
        [
            'label' => '7-8 Neutros',
            'fill' => '#FFF4CC',
            'text' => '#846200',
            'border' => '#D5B33E',
            'width' => 29.0,
        ],
        [
            'label' => '0-6 Detratores',
            'fill' => '#FDE5E5',
            'text' => '#9C1F1F',
            'border' => '#D87979',
            'width' => 34.0,
        ],
    ];

    $chipx = $x + 45.0;

    foreach ($items as $item) {
        local_feedbackdashboard_pdf_set_fill($pdf, $item['fill']);
        local_feedbackdashboard_pdf_set_draw($pdf, $item['border']);
        $pdf->SetLineWidth(0.22);
        $pdf->RoundedRect($chipx, $y, $item['width'], 6.2, 1.0, '1111', 'DF');

        $pdf->SetFont('helvetica', 'B', 6.2);
        local_feedbackdashboard_pdf_set_text($pdf, $item['text']);
        $pdf->SetXY($chipx + 1.0, $y + 0.55);
        $pdf->Cell($item['width'] - 2.0, 4.8, $item['label'], 0, 0, 'C');

        $chipx += $item['width'] + 3.0;
    }

    local_feedbackdashboard_pdf_set_text($pdf, $dark);
}

/**
 * Draws the common page background/header/footer.
 *
 * @param pdf $pdf PDF object.
 * @param string $primary Primary colour.
 * @param string $dark Dark theme colour.
 * @param string $light Light theme colour.
 * @param string|null $logopath Logo path.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_page_base(
    pdf $pdf,
    string $primary,
    string $dark,
    string $light,
    ?string $logopath
): void {
    $pagewidth = $pdf->getPageWidth();
    $pageheight = $pdf->getPageHeight();

    local_feedbackdashboard_pdf_set_fill($pdf, $light);
    $pdf->Rect(0, 0, $pagewidth, $pageheight, 'F');

    local_feedbackdashboard_pdf_set_fill($pdf, $dark);
    $pdf->Rect(0, 0, $pagewidth, 4.8, 'F');

    local_feedbackdashboard_pdf_set_fill($pdf, $primary);
    $pdf->Rect(0, 4.8, $pagewidth, 1.6, 'F');

    local_feedbackdashboard_pdf_draw_logo($pdf, $logopath);

    local_feedbackdashboard_pdf_set_draw($pdf, '#CBD5E1');
    $pdf->SetLineWidth(0.25);
    $pdf->Line(12, $pageheight - 10, $pagewidth - 12, $pageheight - 10);

    $pdf->SetFont('helvetica', '', 7.5);
    local_feedbackdashboard_pdf_set_text($pdf, '#667482');
    $pdf->SetXY($pagewidth - 55, $pageheight - 8.3);
    $pdf->Cell(43, 4, 'Página ' . $pdf->getPage(), 0, 0, 'R');
}

/**
 * Draws one NPS summary card.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X position.
 * @param float $y Y position.
 * @param float $w Width.
 * @param float $h Height.
 * @param string $title Card title.
 * @param string $value Card value.
 * @param string $detail Detail text.
 * @param string $accent Accent colour.
 * @param string $dark Dark text colour.
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
    string $detail,
    string $accent,
    string $dark,
    string $border
): void {
    local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
    local_feedbackdashboard_pdf_set_draw($pdf, $border);
    $pdf->SetLineWidth(0.25);
    $pdf->RoundedRect($x, $y, $w, $h, 1.2, '1111', 'DF');

    local_feedbackdashboard_pdf_set_fill($pdf, $accent);
    $pdf->Rect($x, $y, $w, 1.7, 'F');

    $pdf->SetFont('helvetica', '', 7.2);
    local_feedbackdashboard_pdf_set_text($pdf, '#536271');
    $pdf->SetXY($x + 2, $y + 4.2);
    $pdf->Cell($w - 4, 4, $title, 0, 0, 'C');

    $pdf->SetFont('helvetica', 'B', 15.5);
    local_feedbackdashboard_pdf_set_text($pdf, $dark);
    $pdf->SetXY($x + 2, $y + 9.0);
    $pdf->Cell($w - 4, 6.5, $value, 0, 0, 'C');

    $pdf->SetFont('helvetica', '', 6.4);
    local_feedbackdashboard_pdf_set_text($pdf, '#637083');
    $pdf->SetXY($x + 2, $y + 16.8);
    $pdf->Cell($w - 4, 3.5, $detail, 0, 0, 'C');
}

/**
 * Draws NPS distribution by profile, similar to the web Dashboard.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X position.
 * @param float $y Y position.
 * @param float $w Width.
 * @param float $h Height.
 * @param array $metrics NPS metrics.
 * @param string $dark Dark colour.
 * @param string $border Border colour.
 * @param string $good Promoter colour.
 * @param string $neutral Neutral colour.
 * @param string $bad Detractor colour.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_nps_profile(
    pdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    array $metrics,
    string $dark,
    string $border,
    string $good,
    string $neutral,
    string $bad
): void {
    local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
    local_feedbackdashboard_pdf_set_draw($pdf, $border);
    $pdf->SetLineWidth(0.25);
    $pdf->RoundedRect($x, $y, $w, $h, 1.2, '1111', 'DF');

    $pdf->SetFont('helvetica', 'B', 9.2);
    local_feedbackdashboard_pdf_set_text($pdf, $dark);
    $pdf->SetXY($x + 4, $y + 4);
    $pdf->Cell($w - 8, 5, 'Distribuição do NPS por perfil', 0, 0, 'L');

    $profiles = [
        ['label' => 'Promotores', 'count' => $metrics['promoters'], 'pct' => $metrics['promoterspct'], 'color' => $good],
        ['label' => 'Neutros', 'count' => $metrics['neutrals'], 'pct' => $metrics['neutralspct'], 'color' => $neutral],
        ['label' => 'Detratores', 'count' => $metrics['detractors'], 'pct' => $metrics['detractorspct'], 'color' => $bad],
    ];

    $labelw = 28.0;
    $valuew = 27.0;
    $trackx = $x + 5 + $labelw;
    $trackw = $w - 10 - $labelw - $valuew;
    $trackh = 7.0;
    $starty = $y + 17.0;
    $rowgap = 13.0;

    foreach ($profiles as $index => $profile) {
        $rowy = $starty + ($index * $rowgap);

        $pdf->SetFont('helvetica', '', 7.0);
        local_feedbackdashboard_pdf_set_text($pdf, '#536271');
        $pdf->SetXY($x + 5, $rowy + 0.7);
        $pdf->Cell($labelw - 2, 5, $profile['label'], 0, 0, 'L');

        local_feedbackdashboard_pdf_set_fill($pdf, '#EEF2F6');
        $pdf->RoundedRect($trackx, $rowy, $trackw, $trackh, 0.7, '1111', 'F');

        $fillwidth = $metrics['total'] > 0
            ? $trackw * max(0.0, min(100.0, (float) $profile['pct'])) / 100
            : 0;

        if ($fillwidth > 0) {
            local_feedbackdashboard_pdf_set_fill($pdf, $profile['color']);
            $pdf->RoundedRect($trackx, $rowy, $fillwidth, $trackh, 0.7, '1111', 'F');
        }

        $value = $profile['count'] . ' (' . number_format((float) $profile['pct'], 1, ',', '.') . '%)';
        $pdf->SetFont('helvetica', 'B', 6.7);
        local_feedbackdashboard_pdf_set_text($pdf, $dark);
        $pdf->SetXY($trackx + $trackw + 2, $rowy + 0.7);
        $pdf->Cell($valuew - 2, 5, $value, 0, 0, 'R');
    }
}

/**
 * Draws the 0-to-10 NPS score distribution, similar to the web Dashboard.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X position.
 * @param float $y Y position.
 * @param float $w Width.
 * @param float $h Height.
 * @param array $scorecounts Counts indexed from 0 to 10.
 * @param string $primary Primary colour.
 * @param string $dark Dark colour.
 * @param string $border Border colour.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_score_chart(
    pdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    array $scorecounts,
    string $primary,
    string $dark,
    string $border
): void {
    local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
    local_feedbackdashboard_pdf_set_draw($pdf, $border);
    $pdf->SetLineWidth(0.25);
    $pdf->RoundedRect($x, $y, $w, $h, 1.2, '1111', 'DF');

    $pdf->SetFont('helvetica', 'B', 9.2);
    local_feedbackdashboard_pdf_set_text($pdf, $dark);
    $pdf->SetXY($x + 4, $y + 4);
    $pdf->Cell($w - 8, 5, 'Gráfico de Avaliações por Nota', 0, 0, 'L');

    $plotx = $x + 7;
    $ploty = $y + 15;
    $plotw = $w - 13;
    $ploth = $h - 29;
    $baseline = $ploty + $ploth;

    $maxcount = max(1, (int) max($scorecounts ?: [0]));

    local_feedbackdashboard_pdf_set_draw($pdf, '#DCE3EA');
    $pdf->SetLineWidth(0.18);

    for ($line = 0; $line <= 4; $line++) {
        $liney = $ploty + ($ploth * $line / 4);
        $pdf->Line($plotx, $liney, $plotx + $plotw, $liney);
    }

    $slotwidth = $plotw / 11;
    $barwidth = max(2.2, min(8.0, $slotwidth * 0.55));

    foreach (range(0, 10) as $score) {
        $count = (int) ($scorecounts[$score] ?? 0);
        $barheight = $count > 0 ? ($count / $maxcount) * ($ploth - 8) : 0;
        $barx = $plotx + ($score * $slotwidth) + (($slotwidth - $barwidth) / 2);
        $bary = $baseline - $barheight;

        if ($count > 0) {
            local_feedbackdashboard_pdf_set_fill($pdf, $primary);
            $pdf->RoundedRect($barx, $bary, $barwidth, $barheight, 0.6, '1111', 'F');

            $pdf->SetFont('helvetica', 'B', 6.7);
            local_feedbackdashboard_pdf_set_text($pdf, $dark);
            $pdf->SetXY($barx - 3, max($ploty, $bary - 4.7));
            $pdf->Cell($barwidth + 6, 4, (string) $count, 0, 0, 'C');
        }

        $pdf->SetFont('helvetica', '', 6.2);
        local_feedbackdashboard_pdf_set_text($pdf, '#536271');
        $pdf->SetXY($plotx + ($score * $slotwidth), $baseline + 1.3);
        $pdf->Cell($slotwidth, 4, (string) $score, 0, 0, 'C');
    }

    $pdf->SetFont('helvetica', '', 6.5);
    local_feedbackdashboard_pdf_set_text($pdf, '#637083');
    $pdf->SetXY($plotx, $y + $h - 7.5);
    $pdf->Cell($plotw, 4, 'Nota da aula', 0, 0, 'C');
}


/**
 * Draws a single table cell using an explicit TCPDF rectangle.
 *
 * This avoids relying on MultiCell borders/fills, which can render
 * inconsistently depending on the PDF viewer.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X position.
 * @param float $y Y position.
 * @param float $w Width.
 * @param float $h Height.
 * @param string $text Cell text.
 * @param string $fillcolor Background colour.
 * @param string $bordercolor Border colour.
 * @param string $textcolor Text colour.
 * @param string $align Text alignment.
 * @param bool $bold Whether to use bold text.
 * @param float $fontsize Font size.
 * @return void
 */
function local_feedbackdashboard_pdf_draw_table_cell(
    pdf $pdf,
    float $x,
    float $y,
    float $w,
    float $h,
    string $text,
    string $fillcolor,
    string $bordercolor,
    string $textcolor,
    string $align = 'L',
    bool $bold = false,
    float $fontsize = 7.5
): void {
    local_feedbackdashboard_pdf_set_fill($pdf, $fillcolor);
    local_feedbackdashboard_pdf_set_draw($pdf, $bordercolor);

    // Explicit, visible border.
    $pdf->SetLineWidth(0.45);
    $pdf->Rect($x, $y, $w, $h, 'DF');

    local_feedbackdashboard_pdf_set_text($pdf, $textcolor);
    $pdf->SetFont('helvetica', $bold ? 'B' : '', $fontsize);

    // Small inner padding so text does not touch the border.
    $paddingx = 1.8;

    $pdf->SetXY($x + $paddingx, $y);
    $pdf->MultiCell(
        $w - ($paddingx * 2),
        $h,
        $text,
        0,
        $align,
        false,
        0,
        '',
        '',
        true,
        0,
        false,
        true,
        $h,
        'M'
    );
}

/**
 * Draws the second PDF page and its institutional table header.
 *
 * The table colours are derived automatically from the current
 * Moodle theme primary colour.
 *
 * @param pdf $pdf PDF object.
 * @param stdClass $course Course.
 * @param stdClass $feedback Feedback.
 * @param string $primary Moodle theme primary colour.
 * @param string $dark Dark theme colour.
 * @param string $light Light theme colour.
 * @param string|null $logopath Logo path.
 * @param bool $continued Whether this is a continuation page.
 * @return array Table geometry and colours.
 */
function local_feedbackdashboard_pdf_draw_response_page_header(
    pdf $pdf,
    stdClass $course,
    stdClass $feedback,
    string $primary,
    string $dark,
    string $light,
    ?string $logopath,
    bool $continued = false
): array {

    $feedbackfullname = format_string($feedback->name);

    $nameparts = explode(' - ', $feedbackfullname, 2);

    $displayname = isset($nameparts[1]) && trim($nameparts[1]) !== ''
        ? trim($nameparts[1])
        : $feedbackfullname;

    $pdf->AddPage();

    local_feedbackdashboard_pdf_draw_page_base(
        $pdf,
        $primary,
        $dark,
        $light,
        $logopath
    );

    /*
     * -------------------------------------------------------------
     * Page title.
     * -------------------------------------------------------------
     */
    $pdf->SetFont('helvetica', 'B', 17);
    local_feedbackdashboard_pdf_set_text($pdf, $dark);
    $pdf->SetXY(12, 16);

    $pdf->Cell(
        205,
        8,
        'Respostas e comentários' . ($continued ? ' - continuação' : ''),
        0,
        1,
        'L'
    );

    /*
     * Course and Feedback identification.
     */
    $pdf->SetFont('helvetica', '', 8.2);
    local_feedbackdashboard_pdf_set_text($pdf, '#586878');

    $pdf->SetX(12);
    $pdf->Cell(
        210,
        4.7,
        'Curso: ' . format_string($course->fullname),
        0,
        1,
        'L'
    );

    $pdf->SetX(12);
    $pdf->Cell(
        210,
        4.7,
        'Pesquisa: ' . $displayname,
        0,
        1,
        'L'
    );

    // Visual legend and ordering information for the score column.
    local_feedbackdashboard_pdf_draw_score_legend(
        $pdf,
        12.0,
        35.0,
        $dark
    );

    /*
     * -------------------------------------------------------------
     * TABLE GEOMETRY
     *
     * NOME | NOTA | RESPOSTA
     * -------------------------------------------------------------
     */
    $x = 12.0;
    $y = 44.0;

    $namew = 66.0;
    $scorew = 30.0;
    $responsew = $pdf->getPageWidth() - 24 - $namew - $scorew;

    $headerh = 12.0;

    /*
     * -------------------------------------------------------------
     * Colours derived from the Moodle/AVA theme.
     * -------------------------------------------------------------
     */

    // Very light version of the AVA colour.
    $headerfill = local_feedbackdashboard_pdf_mix_color(
        $primary,
        '#FFFFFF',
        0.91
    );

    // Border derived from the AVA colour.
    $tableborder = local_feedbackdashboard_pdf_mix_color(
        $primary,
        '#FFFFFF',
        0.55
    );

    // Alternate-row background derived from the AVA colour.
    $alternaterow = local_feedbackdashboard_pdf_mix_color(
        $primary,
        '#FFFFFF',
        0.965
    );

    /*
     * -------------------------------------------------------------
     * Table header.
     *
     * The labels use the actual primary AVA colour.
     * -------------------------------------------------------------
     */
    local_feedbackdashboard_pdf_draw_table_cell(
        $pdf,
        $x,
        $y,
        $namew,
        $headerh,
        'NOME',
        $headerfill,
        $tableborder,
        $primary,
        'L',
        true,
        8.5
    );

    local_feedbackdashboard_pdf_draw_table_cell(
        $pdf,
        $x + $namew,
        $y,
        $scorew,
        $headerh,
        'NOTA',
        $headerfill,
        $tableborder,
        $primary,
        'C',
        true,
        8.5
    );

    local_feedbackdashboard_pdf_draw_table_cell(
        $pdf,
        $x + $namew + $scorew,
        $y,
        $responsew,
        $headerh,
        'RESPOSTA',
        $headerfill,
        $tableborder,
        $primary,
        'L',
        true,
        8.5
    );

    /*
     * Strong AVA-coloured line under the table header.
     */
    local_feedbackdashboard_pdf_set_draw($pdf, $primary);
    $pdf->SetLineWidth(0.8);

    $pdf->Line(
        $x,
        $y + $headerh,
        $x + $namew + $scorew + $responsew,
        $y + $headerh
    );

    $pdf->SetLineWidth(0.25);

    return [
        'x' => $x,
        'y' => $y + $headerh,
        'namew' => $namew,
        'scorew' => $scorew,
        'commentw' => $responsew,
        'bordercolor' => $tableborder,
        'alternaterow' => $alternaterow,
        'primary' => $primary,
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

$feedback = $DB->get_record(
    'feedback',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);

$feedbackfullname = format_string($feedback->name);

$nameparts = explode(' - ', $feedbackfullname, 2);

$displayname = isset($nameparts[1]) && trim($nameparts[1]) !== ''
    ? trim($nameparts[1])
    : $feedbackfullname;

$isanonymous = ((int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES);

if ($isanonymous) {
    $selecteduserids = [];
}

$PAGE->set_url(new moodle_url(
    '/local/feedbackdashboard/download.php',
    ['id' => $cm->id]
));

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

$textitems = array_values(array_filter(
    $items,
    static fn($item) => in_array($item->typ, ['textarea', 'textfield'], true)
));

$npsitem = local_feedbackdashboard_pdf_find_nps_item($items);

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

$totalresponsecount = $DB->count_records('feedback_completed', [
    'feedback' => $feedback->id,
    'anonymous_response' => $isanonymous ? FEEDBACK_ANONYMOUS_YES : FEEDBACK_ANONYMOUS_NO,
]);

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
        $valuesbycompletion[(int) $valuerecord->completed][(int) $valuerecord->item] =
            (string) $valuerecord->value;
    }
}

$npsmetrics = $npsitem !== null
    ? local_feedbackdashboard_pdf_calculate_nps($npsitem, $completions, $valuesbycompletion)
    : null;

$responserows = local_feedbackdashboard_pdf_build_response_rows(
    $completions,
    $npsitem,
    $textitems,
    $valuesbycompletion,
    $isanonymous
);

/*
 * -------------------------------------------------------------------------
 * Theme colours and logo.
 * -------------------------------------------------------------------------
 */

$primary = local_feedbackdashboard_pdf_get_theme_primary_color();
$dark = local_feedbackdashboard_pdf_mix_color($primary, '#000000', 0.48);
$light = local_feedbackdashboard_pdf_mix_color($primary, '#FFFFFF', 0.95);
$border = local_feedbackdashboard_pdf_mix_color($primary, '#FFFFFF', 0.78);

$goodcolor = '#2A9D8F';
$neutralcolor = '#E9C46A';
$badcolor = '#E76F51';

$logopath = local_feedbackdashboard_pdf_find_logo();

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
$pdf->SetSubject('Relatório NPS de Feedback');

/*
 * -------------------------------------------------------------------------
 * Page 1 - NPS Dashboard.
 * -------------------------------------------------------------------------
 */

$pdf->AddPage();

local_feedbackdashboard_pdf_draw_page_base(
    $pdf,
    $primary,
    $dark,
    $light,
    $logopath
);

// Title.
$pdf->SetFont('helvetica', 'B', 18);
local_feedbackdashboard_pdf_set_text($pdf, $dark);
$pdf->SetXY(12, 14);
$pdf->Cell(205, 8, 'Feedback de Pesquisa de Satisfação do aluno', 0, 1, 'L');

$pdf->SetFont('helvetica', 'I', 8);
local_feedbackdashboard_pdf_set_text($pdf, '#637083');
$pdf->SetX(12);
$pdf->Cell(205, 4.5, 'Aula: ' . $displayname, 0, 1, 'L');

// Metadata box, matching the web dashboard.
$metax = 12.0;
$metay = 31.0;
$metaw = 273.0;
$metah = 25.0;

local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
local_feedbackdashboard_pdf_set_draw($pdf, $border);
$pdf->SetLineWidth(0.25);
$pdf->Rect($metax, $metay, $metaw, $metah, 'DF');

$pdf->SetFont('helvetica', 'B', 7.3);
local_feedbackdashboard_pdf_set_text($pdf, $dark);

$pdf->SetXY($metax + 4, $metay + 3);
$pdf->Cell(100, 4, 'Curso: ' . format_string($course->fullname), 0, 1, 'L');

$pdf->SetX($metax + 4);
$pdf->Cell(100, 4, 'Respostas submetidas: ' . $totalresponsecount, 0, 1, 'L');

$pdf->SetX($metax + 4);
$pdf->Cell(100, 4, 'Respostas consideradas no filtro: ' . count($completions), 0, 1, 'L');

$pdf->SetX($metax + 4);
$pdf->Cell(100, 4, 'Questões: ' . count($items), 0, 1, 'L');

if ($npsitem !== null) {
    $pdf->SetX($metax + 4);
    $pdf->Cell(
        170,
        4,
        'Pergunta NPS: ' . format_string($npsitem->name),
        0,
        1,
        'L'
    );
}

$generatedat = userdate(time(), get_string('strftimedatetimeshort', 'langconfig'));

if ($isanonymous) {
    $filtertext = 'Pesquisa anônima - resultados agregados';
} else if (empty($selecteduserids)) {
    $filtertext = 'Filtro: todos os participantes';
} else {
    $filtertext = 'Filtro: ' . count($selecteduserids) . ' participante(s)';
}

$pdf->SetFont('helvetica', '', 6.9);
local_feedbackdashboard_pdf_set_text($pdf, '#637083');
$pdf->SetXY($metax + 178, $metay + 5);
$pdf->Cell(90, 4, 'Gerado em: ' . $generatedat, 0, 1, 'R');
$pdf->SetX($metax + 178);
$pdf->Cell(90, 4, $filtertext, 0, 1, 'R');

// NPS cards.
$cardy = 61.0;
$cardgap = 4.0;
$cardw = (273 - ($cardgap * 4)) / 5;
$cardh = 23.0;

if ($npsmetrics !== null) {
    $cards = [
        [
            'title' => 'NPS(%)',
            'value' => number_format((float) $npsmetrics['nps'], 0, ',', '.') . '%',
            'detail' => 'promotores - detratores',
            'color' => $primary,
        ],
        [
            'title' => 'Promotores(%)',
            'value' => number_format((float) $npsmetrics['promoterspct'], 0, ',', '.') . '%',
            'detail' => $npsmetrics['promoters'] . ' resposta(s)',
            'color' => $goodcolor,
        ],
        [
            'title' => 'Neutros(%)',
            'value' => number_format((float) $npsmetrics['neutralspct'], 0, ',', '.') . '%',
            'detail' => $npsmetrics['neutrals'] . ' resposta(s)',
            'color' => $neutralcolor,
        ],
        [
            'title' => 'Detratores(%)',
            'value' => number_format((float) $npsmetrics['detractorspct'], 0, ',', '.') . '%',
            'detail' => $npsmetrics['detractors'] . ' resposta(s)',
            'color' => $badcolor,
        ],
        [
            'title' => 'Média',
            'value' => number_format((float) $npsmetrics['average'], 1, ',', '.'),
            'detail' => $npsmetrics['total'] . ' nota(s) válida(s)',
            'color' => $dark,
        ],
    ];

    foreach ($cards as $index => $card) {
        local_feedbackdashboard_pdf_draw_card(
            $pdf,
            12 + ($index * ($cardw + $cardgap)),
            $cardy,
            $cardw,
            $cardh,
            $card['title'],
            $card['value'],
            $card['detail'],
            $card['color'],
            $dark,
            $border
        );
    }

    // Two charts, matching the web layout.
    $chartgap = 6.0;
    $chartw = (273 - $chartgap) / 2;
    $charty = 89.5;
    $charth = 88.0;

    local_feedbackdashboard_pdf_draw_nps_profile(
        $pdf,
        12,
        $charty,
        $chartw,
        $charth,
        $npsmetrics,
        $dark,
        $border,
        $goodcolor,
        $neutralcolor,
        $badcolor
    );

    local_feedbackdashboard_pdf_draw_score_chart(
        $pdf,
        12 + $chartw + $chartgap,
        $charty,
        $chartw,
        $charth,
        $npsmetrics['scorecounts'],
        $primary,
        $dark,
        $border
    );

    $pdf->SetFont('helvetica', '', 6.5);
    local_feedbackdashboard_pdf_set_text($pdf, '#637083');
    $pdf->SetXY(137, 181.0);
    $pdf->Cell(
        148,
        4,
        'Legenda NPS: notas 9-10 são promotores, 7-8 são neutros e 0-6 são detratores.',
        0,
        0,
        'R'
    );
} else {
    // Graceful fallback when there is no standard 0-to-10 NPS question.
    local_feedbackdashboard_pdf_set_fill($pdf, '#FFFFFF');
    local_feedbackdashboard_pdf_set_draw($pdf, $border);
    $pdf->RoundedRect(12, 61, 273, 115, 1.2, '1111', 'DF');

    $pdf->SetFont('helvetica', 'B', 12);
    local_feedbackdashboard_pdf_set_text($pdf, $dark);
    $pdf->SetXY(20, 95);
    $pdf->Cell(257, 7, 'Nenhuma pergunta NPS de 0 a 10 foi detectada.', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 9);
    local_feedbackdashboard_pdf_set_text($pdf, '#637083');
    $pdf->SetXY(25, 106);
    $pdf->MultiCell(
        247,
        6,
        'Para gerar os indicadores de NPS, utilize uma pergunta de escolha única com notas de 0 a 10.',
        0,
        'C'
    );
}

/*
 * -------------------------------------------------------------------------
 * Page 2 - Responses and comments.
 * -------------------------------------------------------------------------
 */

$table = local_feedbackdashboard_pdf_draw_response_page_header(
    $pdf,
    $course,
    $feedback,
    $primary,
    $dark,
    $light,
    $logopath,
    false
);

if (empty($responserows)) {
    $pdf->SetFont('helvetica', '', 10);
    local_feedbackdashboard_pdf_set_text($pdf, '#586878');
    $pdf->SetXY(12, 64);
    $pdf->Cell(273, 8, 'Não há respostas para os filtros atuais.', 0, 1, 'C');
} else {
    $rowindex = 0;
    $bottomlimit = $pdf->getPageHeight() - 13;

    foreach ($responserows as $responserow) {
        $rowindex++;

        $name = $isanonymous
            ? 'Resposta ' . $rowindex
            : $responserow['name'];

        $score = $responserow['score'];
        $scorevalue = $responserow['scorevalue'];
        $commentvalue = $responserow['comment'];

        $pdf->SetFont('helvetica', '', 7.3);

        $nameheight = $pdf->getStringHeight($table['namew'] - 5, $name);
        $scoreheight = $pdf->getStringHeight($table['scorew'] - 5, $scorevalue);
        $commentheight = $pdf->getStringHeight($table['commentw'] - 5, $commentvalue);

        $rowheight = max(
            9.0,
            $nameheight + 3.5,
            $scoreheight + 3.5,
            $commentheight + 3.5
        );

        if ($table['y'] + $rowheight > $bottomlimit) {
            $table = local_feedbackdashboard_pdf_draw_response_page_header(
                $pdf,
                $course,
                $feedback,
                $primary,
                $dark,
                $light,
                $logopath,
                true
            );
        }

        /*
         * -------------------------------------------------------------
         * Table row visual style.
         * -------------------------------------------------------------
         */
        $rowfill = ($rowindex % 2 === 0)
            ? $table['alternaterow']
            : '#FFFFFF';

        $tableborder = $table['bordercolor'];
        $scorestyle = local_feedbackdashboard_pdf_get_score_style(
            $score,
            $rowfill,
            $table['primary'],
            $tableborder
        );

        /*
         * NOME
         */
        local_feedbackdashboard_pdf_draw_table_cell(
            $pdf,
            $table['x'],
            $table['y'],
            $table['namew'],
            $rowheight,
            $name,
            $rowfill,
            $tableborder,
            '#263746',
            'L',
            false,
            7.6
        );

        /*
         * NOTA
         *
         * 9-10: verde | 7-8: amarelo | 0-6: vermelho.
         */
        local_feedbackdashboard_pdf_draw_table_cell(
            $pdf,
            $table['x'] + $table['namew'],
            $table['y'],
            $table['scorew'],
            $rowheight,
            $scorevalue,
            $scorestyle['fill'],
            $scorestyle['border'],
            $scorestyle['text'],
            'C',
            true,
            9.2
        );

        /*
         * RESPOSTA
         */
        local_feedbackdashboard_pdf_draw_table_cell(
            $pdf,
            $table['x'] + $table['namew'] + $table['scorew'],
            $table['y'],
            $table['commentw'],
            $rowheight,
            $commentvalue,
            $rowfill,
            $tableborder,
            '#263746',
            'L',
            false,
            7.6
        );

        $table['y'] += $rowheight;
    }
}

\core\session\manager::write_close();

$filename = clean_filename('dashboard_nps_' . $feedback->name . '.pdf');

$pdf->Output($filename, 'D');
exit;
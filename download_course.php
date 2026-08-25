<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Course-wide PDF export for the Feedback Dashboard plugin.
 *
 * Generates a consolidated NPS report for all Feedback activities in a
 * course that contain a valid 0-to-10 NPS question.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');
require_once($CFG->libdir . '/pdflib.php');

use local_feedbackdashboard\local\nps_service;

/**
 * Normalises a hexadecimal colour.
 *
 * @param mixed $color Colour candidate.
 * @param string $fallback Fallback colour.
 * @return string
 */
function local_feedbackdashboard_coursepdf_normalise_hex(
    $color,
    string $fallback = '#0F6CBF'
): string {
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
            $color[1],
            $color[1],
            $color[2],
            $color[2],
            $color[3],
            $color[3]
        ));
    }

    return $fallback;
}

/**
 * Gets the primary colour from the current Moodle theme.
 *
 * This follows the same strategy used by download.php.
 *
 * @return string
 */
function local_feedbackdashboard_coursepdf_get_theme_primary_color(): string {
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
            foreach (
                ['brandcolor', 'primarycolor', 'primarycolour', 'maincolor', 'themecolor']
                as $setting
            ) {
                if (!empty($themeconfig->{$setting})) {
                    $candidates[] = $themeconfig->{$setting};
                }
            }
        }
    }

    foreach ($candidates as $candidate) {
        $normalised = local_feedbackdashboard_coursepdf_normalise_hex(
            $candidate,
            ''
        );

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
function local_feedbackdashboard_coursepdf_hex_to_rgb(string $hex): array {
    $hex = ltrim(
        local_feedbackdashboard_coursepdf_normalise_hex($hex),
        '#'
    );

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Mixes one colour with another.
 *
 * @param string $base Base colour.
 * @param string $target Target colour.
 * @param float $weight Target colour weight from 0 to 1.
 * @return string
 */
function local_feedbackdashboard_coursepdf_mix_color(
    string $base,
    string $target,
    float $weight
): string {
    $weight = max(0.0, min(1.0, $weight));

    [$br, $bg, $bb] =
        local_feedbackdashboard_coursepdf_hex_to_rgb($base);

    [$tr, $tg, $tb] =
        local_feedbackdashboard_coursepdf_hex_to_rgb($target);

    $r = (int) round($br * (1 - $weight) + $tr * $weight);
    $g = (int) round($bg * (1 - $weight) + $tg * $weight);
    $b = (int) round($bb * (1 - $weight) + $tb * $weight);

    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

/**
 * Applies a hexadecimal fill colour to TCPDF.
 *
 * @param pdf $pdf PDF object.
 * @param string $hex Colour.
 * @return void
 */
function local_feedbackdashboard_coursepdf_set_fill(
    pdf $pdf,
    string $hex
): void {
    [$r, $g, $b] =
        local_feedbackdashboard_coursepdf_hex_to_rgb($hex);

    $pdf->SetFillColor($r, $g, $b);
}

/**
 * Applies a hexadecimal text colour to TCPDF.
 *
 * @param pdf $pdf PDF object.
 * @param string $hex Colour.
 * @return void
 */
function local_feedbackdashboard_coursepdf_set_text(
    pdf $pdf,
    string $hex
): void {
    [$r, $g, $b] =
        local_feedbackdashboard_coursepdf_hex_to_rgb($hex);

    $pdf->SetTextColor($r, $g, $b);
}

/**
 * Applies a hexadecimal line colour to TCPDF.
 *
 * @param pdf $pdf PDF object.
 * @param string $hex Colour.
 * @return void
 */
function local_feedbackdashboard_coursepdf_set_draw(
    pdf $pdf,
    string $hex
): void {
    [$r, $g, $b] =
        local_feedbackdashboard_coursepdf_hex_to_rgb($hex);

    $pdf->SetDrawColor($r, $g, $b);
}

/**
 * Finds the first logo image inside local/feedbackdashboard/imgs.
 *
 * @return string|null
 */
function local_feedbackdashboard_coursepdf_find_logo(): ?string {
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

    foreach (
        ['*.png', '*.jpg', '*.jpeg', '*.PNG', '*.JPG', '*.JPEG']
        as $pattern
    ) {
        $matches = glob($directory . '/' . $pattern);

        if (is_array($matches)) {
            $files = array_merge($files, $matches);
        }
    }

    $files = array_values(
        array_unique(
            array_filter($files, 'is_readable')
        )
    );

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return $files[0] ?? null;
}

/**
 * Draws the company logo in the upper-right corner.
 *
 * Uses the same dimensions as the existing Feedback PDF.
 *
 * @param pdf $pdf PDF object.
 * @param string|null $logopath Logo path.
 * @return void
 */
function local_feedbackdashboard_coursepdf_draw_logo(
    pdf $pdf,
    ?string $logopath
): void {
    if ($logopath === null || !is_readable($logopath)) {
        return;
    }

    $imagesize = @getimagesize($logopath);

    if (
        !is_array($imagesize)
        || empty($imagesize[0])
        || empty($imagesize[1])
    ) {
        return;
    }

    $maxwidth = 56.0;
    $maxheight = 12.5;

    $scale = min(
        $maxwidth / $imagesize[0],
        $maxheight / $imagesize[1]
    );

    $width = max(1.0, $imagesize[0] * $scale);
    $height = max(1.0, $imagesize[1] * $scale);

    $x = $pdf->getPageWidth() - 12 - $width;
    $y = 12.0;

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
}

/**
 * Draws the common institutional page background, header and footer.
 *
 * @param pdf $pdf PDF object.
 * @param string $primary Primary theme colour.
 * @param string $dark Dark derived theme colour.
 * @param string $light Light derived theme colour.
 * @param string|null $logopath Logo path.
 * @return void
 */
function local_feedbackdashboard_coursepdf_draw_page_base(
    pdf $pdf,
    string $primary,
    string $dark,
    string $light,
    ?string $logopath
): void {
    $pagewidth = $pdf->getPageWidth();
    $pageheight = $pdf->getPageHeight();

    local_feedbackdashboard_coursepdf_set_fill($pdf, $light);
    $pdf->Rect(0, 0, $pagewidth, $pageheight, 'F');

    local_feedbackdashboard_coursepdf_set_fill($pdf, $dark);
    $pdf->Rect(0, 0, $pagewidth, 4.8, 'F');

    local_feedbackdashboard_coursepdf_set_fill($pdf, $primary);
    $pdf->Rect(0, 4.8, $pagewidth, 1.6, 'F');

    local_feedbackdashboard_coursepdf_draw_logo(
        $pdf,
        $logopath
    );

    local_feedbackdashboard_coursepdf_set_draw(
        $pdf,
        '#CBD5E1'
    );

    $pdf->SetLineWidth(0.25);

    $pdf->Line(
        12,
        $pageheight - 10,
        $pagewidth - 12,
        $pageheight - 10
    );

    $pdf->SetFont('helvetica', '', 7.5);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#667482'
    );

    $pdf->SetXY(
        $pagewidth - 55,
        $pageheight - 8.3
    );

    $pdf->Cell(
        43,
        4,
        'Página ' . $pdf->getPage(),
        0,
        0,
        'R'
    );
}

/**
 * Draws one KPI summary card.
 *
 * Reuses the visual language of download.php.
 *
 * @param pdf $pdf PDF object.
 * @param float $x X.
 * @param float $y Y.
 * @param float $w Width.
 * @param float $h Height.
 * @param string $title Card title.
 * @param string $value Main value.
 * @param string $detail Detail.
 * @param string $accent Accent colour.
 * @param string $dark Dark text colour.
 * @param string $border Border colour.
 * @return void
 */
function local_feedbackdashboard_coursepdf_draw_card(
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
    local_feedbackdashboard_coursepdf_set_fill(
        $pdf,
        '#FFFFFF'
    );

    local_feedbackdashboard_coursepdf_set_draw(
        $pdf,
        $border
    );

    $pdf->SetLineWidth(0.25);

    $pdf->RoundedRect(
        $x,
        $y,
        $w,
        $h,
        1.2,
        '1111',
        'DF'
    );

    local_feedbackdashboard_coursepdf_set_fill(
        $pdf,
        $accent
    );

    $pdf->Rect(
        $x,
        $y,
        $w,
        1.7,
        'F'
    );

    $pdf->SetFont('helvetica', '', 7.2);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#536271'
    );

    $pdf->SetXY(
        $x + 2,
        $y + 4.2
    );

    $pdf->Cell(
        $w - 4,
        4,
        $title,
        0,
        0,
        'C'
    );

    $pdf->SetFont('helvetica', 'B', 15.5);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        $dark
    );

    $pdf->SetXY(
        $x + 2,
        $y + 9.0
    );

    $pdf->Cell(
        $w - 4,
        6.5,
        $value,
        0,
        0,
        'C'
    );

    $pdf->SetFont('helvetica', '', 6.4);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#637083'
    );

    $pdf->SetXY(
        $x + 2,
        $y + 16.8
    );

    $pdf->Cell(
        $w - 4,
        3.5,
        $detail,
        0,
        0,
        'C'
    );
}

/**
 * Shortens a Feedback name so the chart remains readable.
 *
 * @param string $name Original name.
 * @param int $maxlength Maximum length.
 * @return string
 */
function local_feedbackdashboard_coursepdf_chart_label(
    string $name,
    int $maxlength = 54
): string {
    $name = trim(
        preg_replace('/\s+/u', ' ', $name)
    );

    if (core_text::strlen($name) <= $maxlength) {
        return $name;
    }

    return rtrim(
        core_text::substr(
            $name,
            0,
            max(1, $maxlength - 3)
        )
    ) . '...';
}

/**
 * Draws one page of the NPS-by-Feedback horizontal bar chart.
 *
 * The scale is fixed from -100 to +100 so Feedback activities remain
 * directly comparable.
 *
 * @param pdf $pdf PDF object.
 * @param array $rows Feedback rows.
 * @param string $coursename Course name.
 * @param string $primary Primary theme colour.
 * @param string $dark Dark theme colour.
 * @param string $light Light theme colour.
 * @param string $border Border colour.
 * @param string|null $logopath Logo path.
 * @param int $pageindex Current chart page.
 * @param int $pagecount Total chart pages.
 * @return void
 */
function local_feedbackdashboard_coursepdf_draw_nps_chart_page(
    pdf $pdf,
    array $rows,
    string $coursename,
    string $primary,
    string $dark,
    string $light,
    string $border,
    ?string $logopath,
    int $pageindex,
    int $pagecount
): void {
    $pdf->AddPage();

    local_feedbackdashboard_coursepdf_draw_page_base(
        $pdf,
        $primary,
        $dark,
        $light,
        $logopath
    );

    $pdf->SetFont('helvetica', 'B', 17);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        $dark
    );

    $pdf->SetXY(12, 15);

    $pdf->Cell(
        210,
        8,
        'NPS por aula / Feedback',
        0,
        1,
        'L'
    );

    $pdf->SetFont('helvetica', '', 8);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#637083'
    );

    $pdf->SetX(12);

    $subtitle = 'Curso: ' . $coursename;

    if ($pagecount > 1) {
        $subtitle .= ' - Parte '
            . $pageindex
            . ' de '
            . $pagecount;
    }

    $pdf->Cell(
        220,
        4.5,
        $subtitle,
        0,
        1,
        'L'
    );

    $boxx = 12.0;
    $boxy = 34.0;
    $boxw = 273.0;
    $boxh = 151.0;

    local_feedbackdashboard_coursepdf_set_fill(
        $pdf,
        '#FFFFFF'
    );

    local_feedbackdashboard_coursepdf_set_draw(
        $pdf,
        $border
    );

    $pdf->SetLineWidth(0.25);

    $pdf->RoundedRect(
        $boxx,
        $boxy,
        $boxw,
        $boxh,
        1.2,
        '1111',
        'DF'
    );

    $labelx = 17.0;
    $labelw = 72.0;
    $chartx = 96.0;
    $chartw = 166.0;
    $valuex = 265.0;
    $valuew = 16.0;

    $zerox = $chartx + ($chartw / 2);

    $pdf->SetFont('helvetica', 'B', 6.5);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#637083'
    );

    $pdf->SetXY($chartx - 6, 37.5);
    $pdf->Cell(12, 4, '-100', 0, 0, 'C');

    $pdf->SetXY($zerox - 6, 37.5);
    $pdf->Cell(12, 4, '0', 0, 0, 'C');

    $pdf->SetXY($chartx + $chartw - 6, 37.5);
    $pdf->Cell(12, 4, '100', 0, 0, 'C');

    $rowstart = 44.0;
    $rowstep = 10.9;
    $trackh = 4.6;

    foreach ($rows as $index => $row) {
        $rowy = $rowstart + ($index * $rowstep);

        $label = local_feedbackdashboard_coursepdf_chart_label(
            format_string($row['name']),
            54
        );

        $pdf->SetFont('helvetica', '', 6.7);

        local_feedbackdashboard_coursepdf_set_text(
            $pdf,
            '#455565'
        );

        $pdf->SetXY(
            $labelx,
            $rowy - 0.3
        );

        $pdf->MultiCell(
            $labelw,
            7.2,
            $label,
            0,
            'L',
            false,
            0,
            '',
            '',
            true,
            0,
            false,
            true,
            7.2,
            'M'
        );

        local_feedbackdashboard_coursepdf_set_fill(
            $pdf,
            local_feedbackdashboard_coursepdf_mix_color(
                $primary,
                '#FFFFFF',
                0.93
            )
        );

        $pdf->RoundedRect(
            $chartx,
            $rowy + 1.0,
            $chartw,
            $trackh,
            0.5,
            '1111',
            'F'
        );

        local_feedbackdashboard_coursepdf_set_draw(
            $pdf,
            local_feedbackdashboard_coursepdf_mix_color(
                $primary,
                '#FFFFFF',
                0.60
            )
        );

        $pdf->SetLineWidth(0.22);

        $pdf->Line(
            $zerox,
            $rowy + 0.1,
            $zerox,
            $rowy + 6.5
        );

        if ($row['nps'] !== null) {
            $nps = max(
                -100.0,
                min(100.0, (float) $row['nps'])
            );

            $barwidth =
                (abs($nps) / 100)
                * ($chartw / 2);
            
                
            $barx = $nps >= 0
                ? $zerox
                : $zerox - $barwidth;

                /*
                 * Negative NPS = Red.
                 * NPS equals or greater than 0 = AVA primary colour.
                 */
            $barcolor = $nps < 0
               ? '#E76F51'
               : $primary;
               
               
            local_feedbackdashboard_coursepdf_set_fill(
                $pdf,
                $barcolor
            );

            if ($barwidth > 0) {
                $pdf->RoundedRect(
                    $barx,
                    $rowy + 1.0,
                    $barwidth,
                    $trackh,
                    0.5,
                    '1111',
                    'F'
                );
            }

            $value = number_format(
                $nps,
                0,
                ',',
                '.'
            ) . '%';
        } else {
            $value = '-';
        }

        $pdf->SetFont('helvetica', 'B', 6.8);

        local_feedbackdashboard_coursepdf_set_text(
            $pdf,
            $dark
        );

        $pdf->SetXY(
            $valuex,
            $rowy + 0.6
        );

        $pdf->Cell(
            $valuew,
            4.8,
            $value,
            0,
            0,
            'R'
        );
    }

    $pdf->SetFont('helvetica', '', 6.6);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#637083'
    );

    $pdf->SetXY(
        $chartx,
        178.0
    );

    $pdf->Cell(
        $chartw,
        4,
        'Indicador NPS (%)',
        0,
        0,
        'C'
    );
}

/**
 * Draws one page of the respondents-by-Feedback horizontal lollipop chart.
 *
 * This chart represents absolute integer counts, not percentages.
 *
 * @param pdf $pdf PDF object.
 * @param array $rows Feedback rows.
 * @param string $coursename Course name.
 * @param int $maxresponses Maximum number of responses in the whole course.
 * @param string $primary Primary theme colour.
 * @param string $dark Dark theme colour.
 * @param string $light Light theme colour.
 * @param string $border Border colour.
 * @param string|null $logopath Logo path.
 * @param int $pageindex Current chart page.
 * @param int $pagecount Total chart pages.
 * @return void
 */
function local_feedbackdashboard_coursepdf_draw_response_chart_page(
    pdf $pdf,
    array $rows,
    string $coursename,
    int $maxresponses,
    string $primary,
    string $dark,
    string $light,
    string $border,
    ?string $logopath,
    int $pageindex,
    int $pagecount
): void {
    $pdf->AddPage();

    local_feedbackdashboard_coursepdf_draw_page_base(
        $pdf,
        $primary,
        $dark,
        $light,
        $logopath
    );

    /*
     * -------------------------------------------------------------
     * Title.
     * -------------------------------------------------------------
     */

    $pdf->SetFont('helvetica', 'B', 17);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        $dark
    );

    $pdf->SetXY(12, 15);

    $pdf->Cell(
        220,
        8,
        'Número de respondentes por aula / Feedback',
        0,
        1,
        'L'
    );

    $pdf->SetFont('helvetica', '', 8);

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#637083'
    );

    $pdf->SetX(12);

    $subtitle = 'Curso: ' . $coursename;

    if ($pagecount > 1) {
        $subtitle .=
            ' - Parte '
            . $pageindex
            . ' de '
            . $pagecount;
    }

    $pdf->Cell(
        220,
        4.5,
        $subtitle,
        0,
        1,
        'L'
    );

    /*
     * -------------------------------------------------------------
     * Chart container.
     * -------------------------------------------------------------
     */

    $boxx = 12.0;
    $boxy = 34.0;
    $boxw = 273.0;
    $boxh = 151.0;

    local_feedbackdashboard_coursepdf_set_fill(
        $pdf,
        '#FFFFFF'
    );

    local_feedbackdashboard_coursepdf_set_draw(
        $pdf,
        $border
    );

    $pdf->SetLineWidth(0.25);

    $pdf->RoundedRect(
        $boxx,
        $boxy,
        $boxw,
        $boxh,
        1.2,
        '1111',
        'DF'
    );

    /*
     * -------------------------------------------------------------
     * Geometry.
     *
     * A larger area is reserved for Feedback names.
     * -------------------------------------------------------------
     */

    $labelx = 17.0;
    $labelw = 84.0;

    $chartx = 108.0;
    $chartw = 145.0;

    $valuex = 260.0;
    $valuew = 20.0;

    $rowstart = 51.0;
    $rowstep = 10.5;

    /*
     * -------------------------------------------------------------
     * Integer scale.
     * -------------------------------------------------------------
     */

    $maxresponses = max(
        1,
        $maxresponses
    );

    /*
     * Create a clean upper limit.
     */
    if ($maxresponses <= 10) {
        $step = 2;
    } else if ($maxresponses <= 25) {
        $step = 5;
    } else if ($maxresponses <= 50) {
        $step = 10;
    } else if ($maxresponses <= 100) {
        $step = 20;
    } else if ($maxresponses <= 250) {
        $step = 50;
    } else if ($maxresponses <= 500) {
        $step = 100;
    } else {
        $step = (int) ceil(
            $maxresponses / 5 / 100
        ) * 100;
    }

    $axismax = (int) (
        ceil($maxresponses / $step)
        * $step
    );

    $axismax = max(
        $step,
        $axismax
    );

    /*
     * -------------------------------------------------------------
     * Scale header.
     *
     * Unlike NPS, this explicitly states that these are people/counts.
     * -------------------------------------------------------------
     */

    $pdf->SetFont(
        'helvetica',
        'B',
        6.5
    );

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#536271'
    );

    $pdf->SetXY(
        $chartx,
        39.0
    );

    $pdf->Cell(
        $chartw,
        4,
        'Quantidade de respondentes',
        0,
        0,
        'C'
    );

    /*
     * Scale marks.
     */
    $ticks = 5;

    for ($tick = 0; $tick <= $ticks; $tick++) {

        $ratio = $tick / $ticks;

        $tickx =
            $chartx
            + ($chartw * $ratio);

        $tickvalue = (int) round(
            $axismax * $ratio
        );

        /*
         * Vertical guide.
         */
        local_feedbackdashboard_coursepdf_set_draw(
            $pdf,
            '#E5EAF0'
        );

        $pdf->SetLineWidth(0.15);

        $pdf->Line(
            $tickx,
            47,
            $tickx,
            174
        );

        /*
         * Integer scale label.
         */
        $pdf->SetFont(
            'helvetica',
            '',
            6.2
        );

        local_feedbackdashboard_coursepdf_set_text(
            $pdf,
            '#718096'
        );

        $pdf->SetXY(
            $tickx - 7,
            43.0
        );

        $pdf->Cell(
            14,
            4,
            (string) $tickvalue,
            0,
            0,
            'C'
        );
    }

    /*
     * -------------------------------------------------------------
     * Feedback rows.
     * -------------------------------------------------------------
     */

    foreach ($rows as $index => $row) {

        $rowy =
            $rowstart
            + ($index * $rowstep);

        $responses = max(
            0,
            (int) $row['responses']
        );

        /*
         * Feedback name.
         */
        $label =
            local_feedbackdashboard_coursepdf_chart_label(
                format_string(
                    $row['name']
                ),
                62
            );

        $pdf->SetFont(
            'helvetica',
            '',
            6.7
        );

        local_feedbackdashboard_coursepdf_set_text(
            $pdf,
            '#455565'
        );

        $pdf->SetXY(
            $labelx,
            $rowy - 1.2
        );

        $pdf->MultiCell(
            $labelw,
            7,
            $label,
            0,
            'L',
            false,
            0,
            '',
            '',
            true,
            0,
            false,
            true,
            7,
            'M'
        );

        /*
         * ---------------------------------------------------------
         * Background ruler.
         * ---------------------------------------------------------
         */

        local_feedbackdashboard_coursepdf_set_draw(
            $pdf,
            '#DDE4EA'
        );

        $pdf->SetLineWidth(1.0);

        $pdf->Line(
            $chartx,
            $rowy + 2,
            $chartx + $chartw,
            $rowy + 2
        );

        /*
         * ---------------------------------------------------------
         * Data line.
         *
         * This is intentionally thin so it does not look like
         * the NPS percentage bars.
         * ---------------------------------------------------------
         */

        $ratio = min(
            1.0,
            $responses / $axismax
        );

        $endpoint =
            $chartx
            + ($chartw * $ratio);

        if ($responses > 0) {

            local_feedbackdashboard_coursepdf_set_draw(
                $pdf,
                $primary
            );

            $pdf->SetLineWidth(1.8);

            $pdf->Line(
                $chartx,
                $rowy + 2,
                $endpoint,
                $rowy + 2
            );

            /*
             * Marker at the exact response count.
             */
            local_feedbackdashboard_coursepdf_set_fill(
                $pdf,
                $primary
            );

            $markersize = 4.2;

            $pdf->RoundedRect(
                $endpoint - ($markersize / 2),
                $rowy + 2 - ($markersize / 2),
                $markersize,
                $markersize,
                2.1,
                '1111',
                'F'
            );
        }

        /*
         * ---------------------------------------------------------
         * Exact integer value.
         * ---------------------------------------------------------
         */

        local_feedbackdashboard_coursepdf_set_fill(
            $pdf,
            local_feedbackdashboard_coursepdf_mix_color(
                $primary,
                '#FFFFFF',
                0.91
            )
        );

        local_feedbackdashboard_coursepdf_set_draw(
            $pdf,
            local_feedbackdashboard_coursepdf_mix_color(
                $primary,
                '#FFFFFF',
                0.65
            )
        );

        $pdf->SetLineWidth(0.2);

        $pdf->RoundedRect(
            $valuex,
            $rowy - 1.4,
            $valuew,
            6.8,
            1.2,
            '1111',
            'DF'
        );

        $pdf->SetFont(
            'helvetica',
            'B',
            7.4
        );

        local_feedbackdashboard_coursepdf_set_text(
            $pdf,
            $dark
        );

        $pdf->SetXY(
            $valuex,
            $rowy - 0.5
        );

        $pdf->Cell(
            $valuew,
            5,
            (string) $responses,
            0,
            0,
            'C'
        );
    }

    /*
     * -------------------------------------------------------------
     * Footer explanation.
     * -------------------------------------------------------------
     */

    $pdf->SetFont(
        'helvetica',
        '',
        6.5
    );

    local_feedbackdashboard_coursepdf_set_text(
        $pdf,
        '#637083'
    );

    $pdf->SetXY(
        $chartx,
        177.5
    );

    $pdf->Cell(
        $chartw,
        4,
        'Escala em quantidade absoluta de respondentes',
        0,
        0,
        'C'
    );
}

\core\session\manager::write_close();

$filename = clean_filename(
    'relatorio_nps_curso_'
    . $course->fullname
    . '.pdf'
);

$pdf->Output(
    $filename,
    'D'
);

exit;
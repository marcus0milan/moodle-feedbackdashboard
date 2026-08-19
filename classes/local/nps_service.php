<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared NPS summary service for Moodle Feedback activities.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_feedbackdashboard\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds compact NPS summaries for the site-wide dashboard.
 */
class nps_service {
    /**
     * Convert a Feedback option label to compact plain text.
     *
     * @param string $text Raw option label.
     * @return string
     */
    private static function clean_text(string $text): string {
        $formatted = format_text($text, FORMAT_HTML, [
            'noclean' => false,
            'para' => false,
        ]);

        $plain = html_to_text($formatted, 0, false);
        $plain = preg_replace('/\s+/u', ' ', $plain);

        return trim((string) $plain);
    }

    /**
     * Extract a 0-to-10 score from an option label.
     *
     * @param string $label Option label.
     * @return int|null
     */
    private static function extract_score_from_label(string $label): ?int {
        $label = self::clean_text($label);

        if (preg_match('/^\s*\(?\s*(10|[0-9])\s*\)?\s*$/u', $label, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^\s*\(?\s*(10|[0-9])\s*\)?\s*(?:[-–—:]|\s)/u', $label, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get option indexes and their corresponding NPS scores.
     *
     * @param \stdClass $item Feedback item.
     * @return array|null
     */
    private static function get_choice_config(\stdClass $item): ?array {
        if (!in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
            return null;
        }

        $itemobject = feedback_get_item_class($item->typ);
        if (!$itemobject) {
            return null;
        }

        $info = $itemobject->get_info($item);
        $scores = [];
        $ismultiple = false;

        if ($item->typ === 'multichoice') {
            $rawoptions = explode(FEEDBACK_MULTICHOICE_LINE_SEP, $info->presentation);
            $ismultiple = ($info->subtype === 'c');

            foreach ($rawoptions as $index => $rawoption) {
                $optionindex = $index + 1;
                $scores[$optionindex] = self::extract_score_from_label((string) $rawoption);
            }
        } else {
            $rawoptions = explode(FEEDBACK_MULTICHOICERATED_LINE_SEP, $info->presentation);

            foreach ($rawoptions as $index => $rawoption) {
                $optionindex = $index + 1;
                $parts = explode(FEEDBACK_MULTICHOICERATED_VALUE_SEP, $rawoption, 2);
                $weight = trim((string) ($parts[0] ?? ''));
                $rawtext = (string) ($parts[1] ?? $parts[0] ?? '');

                if (is_numeric(str_replace(',', '.', $weight))) {
                    $numericweight = (float) str_replace(',', '.', $weight);
                    $rounded = (int) round($numericweight);
                    $scores[$optionindex] = abs($numericweight - $rounded) < 0.00001 ? $rounded : null;
                } else {
                    $scores[$optionindex] = self::extract_score_from_label($rawtext);
                }
            }
        }

        return [
            'scores' => $scores,
            'ismultiple' => $ismultiple,
        ];
    }

    /**
     * Check whether a Feedback item is an exact 0-to-10 single-choice scale.
     *
     * Requiring the complete 0..10 range avoids silently treating another
     * numeric scale as a standard NPS question.
     *
     * @param \stdClass $item Feedback item.
     * @return bool
     */
    private static function is_nps_item(\stdClass $item): bool {
        $config = self::get_choice_config($item);

        if ($config === null || $config['ismultiple']) {
            return false;
        }

        $scores = array_values(array_filter(
            $config['scores'],
            static fn($value) => $value !== null
        ));
        $scores = array_values(array_unique(array_map('intval', $scores)));
        sort($scores);

        return $scores === range(0, 10);
    }

    /**
     * Return the first exact 0-to-10 NPS item.
     *
     * @param array $items Feedback items.
     * @return \stdClass|null
     */
    private static function find_nps_item(array $items): ?\stdClass {
        foreach ($items as $item) {
            if (self::is_nps_item($item)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Decode the stored 1-based option index into the real NPS score.
     *
     * @param \stdClass $item NPS item.
     * @param string $storedvalue Stored Feedback value.
     * @return int|null
     */
    private static function decode_score(\stdClass $item, string $storedvalue): ?int {
        $storedvalue = trim($storedvalue);
        if ($storedvalue === '' || $storedvalue === '0') {
            return null;
        }

        $config = self::get_choice_config($item);
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
     * Build a live NPS summary for one Feedback record.
     *
     * @param \stdClass $feedback Feedback record containing at least id and anonymous.
     * @return array
     */
    public static function get_summary(\stdClass $feedback): array {
        global $DB;

        $responsemode = ((int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES)
            ? FEEDBACK_ANONYMOUS_YES
            : FEEDBACK_ANONYMOUS_NO;

        $totalresponses = $DB->count_records('feedback_completed', [
            'feedback' => $feedback->id,
            'anonymous_response' => $responsemode,
        ]);

        $lastresponse = $DB->get_field_sql(
            'SELECT MAX(timemodified)
               FROM {feedback_completed}
              WHERE feedback = :feedbackid
                AND anonymous_response = :responsemode',
            [
                'feedbackid' => $feedback->id,
                'responsemode' => $responsemode,
            ]
        );

        $items = $DB->get_records_select(
            'feedback_item',
            'feedback = :feedbackid AND hasvalue = :hasvalue',
            [
                'feedbackid' => $feedback->id,
                'hasvalue' => 1,
            ],
            'position ASC'
        );

        $npsitem = self::find_nps_item($items);

        $summary = [
            'hasnps' => $npsitem !== null,
            'npsitemid' => $npsitem ? (int) $npsitem->id : null,
            'npsitemname' => $npsitem ? format_string($npsitem->name) : '',
            'totalresponses' => (int) $totalresponses,
            'validresponses' => 0,
            'promoters' => 0,
            'passives' => 0,
            'detractors' => 0,
            'promoterspct' => 0.0,
            'passivespct' => 0.0,
            'detractorspct' => 0.0,
            'nps' => 0.0,
            'average' => 0.0,
            'lastresponse' => $lastresponse ? (int) $lastresponse : 0,
        ];

        if ($npsitem === null || $totalresponses === 0) {
            return $summary;
        }

        $valuerecords = $DB->get_records_sql(
            'SELECT fv.id, fv.value
               FROM {feedback_value} fv
               JOIN {feedback_completed} fbc ON fbc.id = fv.completed
              WHERE fbc.feedback = :feedbackid
                AND fbc.anonymous_response = :responsemode
                AND fv.item = :itemid',
            [
                'feedbackid' => $feedback->id,
                'responsemode' => $responsemode,
                'itemid' => $npsitem->id,
            ]
        );

        $scores = [];
        foreach ($valuerecords as $valuerecord) {
            $score = self::decode_score($npsitem, (string) $valuerecord->value);
            if ($score !== null) {
                $scores[] = $score;
            }
        }

        $validresponses = count($scores);
        if ($validresponses === 0) {
            return $summary;
        }

        foreach ($scores as $score) {
            if ($score >= 9) {
                $summary['promoters']++;
            } else if ($score >= 7) {
                $summary['passives']++;
            } else {
                $summary['detractors']++;
            }
        }

        $summary['validresponses'] = $validresponses;
        $summary['promoterspct'] = ($summary['promoters'] / $validresponses) * 100;
        $summary['passivespct'] = ($summary['passives'] / $validresponses) * 100;
        $summary['detractorspct'] = ($summary['detractors'] / $validresponses) * 100;
        $summary['nps'] = (($summary['promoters'] - $summary['detractors']) / $validresponses) * 100;
        $summary['average'] = array_sum($scores) / $validresponses;

        return $summary;
    }
}

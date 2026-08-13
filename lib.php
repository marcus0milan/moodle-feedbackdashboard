<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Library callbacks for the Feedback Dashboard plugin.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds a native-looking Dashboard NPS button beside
 * "Exportar para o Excel" on the Feedback analysis page.
 *
 * @return void
 */
function local_feedbackdashboard_before_footer(): void {
    global $PAGE;

    /*
     * Must be inside a course module.
     */
    if (empty($PAGE->cm)) {
        return;
    }

    /*
     * Only inside Moodle Feedback activities.
     */
    if ($PAGE->cm->modname !== 'feedback') {
        return;
    }

    /*
     * Only on:
     *
     * /mod/feedback/analysis.php?id=...
     *
     * Using the path instead of a hardcoded localhost URL means this
     * also works if Moodle is installed in a subdirectory.
     */
    $currentpath = $PAGE->url->get_path();

    if (!str_ends_with($currentpath, '/mod/feedback/analysis.php')) {
        return;
    }

    /*
     * Activity context.
     */
    $context = context_module::instance($PAGE->cm->id);

    /*
     * Only users allowed to access the Dashboard and Feedback reports
     * should see the button.
     */
    if (
        !has_capability(
            'local/feedbackdashboard:view',
            $context
        ) ||
        !has_capability(
            'mod/feedback:viewreports',
            $context
        )
    ) {
        return;
    }

    /*
     * Build the Dashboard URL using the current course-module ID.
     *
     * Example:
     *
     * analysis.php?id=7
     *
     * becomes:
     *
     * /local/feedbackdashboard/index.php?id=7
     */
    $dashboardurl = new moodle_url(
        '/local/feedbackdashboard/index.php',
        [
            'id' => $PAGE->cm->id,
        ]
    );

    /*
     * Prepare PHP values safely for JavaScript.
     */
    $dashboardurljson = json_encode(
        $dashboardurl->out(false),
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    );

    $dashboardlabeljson = json_encode(
        get_string(
            'dashboardbutton',
            'local_feedbackdashboard'
        ),
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    );

    $cmidjson = json_encode(
        (int) $PAGE->cm->id
    );

    /*
     * Clone Moodle's own "Exportar para o Excel" button.
     *
     * This is intentional:
     *
     * - same Bootstrap/Moodle classes;
     * - same spacing;
     * - same height;
     * - same border;
     * - same theme behaviour.
     */
    $javascript = <<<JS
(function() {
    'use strict';

    const dashboardUrl = {$dashboardurljson};
    const dashboardLabel = {$dashboardlabeljson};
    const cmId = {$cmidjson};

    function addDashboardButton() {

        /*
         * Prevent duplication.
         */
        if (
            document.querySelector(
                '[data-feedbackdashboard-button="1"]'
            )
        ) {
            return true;
        }

        /*
         * Moodle 4.5 creates the Export to Excel button inside:
         *
         * <div class="form-buttons">
         */
        const container = document.querySelector(
            '.form-buttons'
        );

        if (!container) {
            return false;
        }

        /*
         * First try to locate the exact native Excel form.
         */
        let exportForm = container.querySelector(
            'form[action*="analysis_to_excel.php"]'
        );

        /*
         * Fallback in case Moodle/theme changes the absolute action.
         */
        if (!exportForm) {
            exportForm = container.querySelector('form');
        }

        if (!exportForm) {
            return false;
        }

        /*
         * Moodle's single_button normally wraps the form inside
         * .singlebutton.
         *
         * Clone the complete wrapper so our button looks exactly
         * like the native button.
         */
        const originalWrapper =
            exportForm.closest('.singlebutton');

        const dashboardWrapper = originalWrapper
            ? originalWrapper.cloneNode(true)
            : exportForm.cloneNode(true);

        dashboardWrapper.setAttribute(
            'data-feedbackdashboard-button',
            '1'
        );

        /*
         * Locate the cloned form.
         */
        let dashboardForm = null;

        if (
            dashboardWrapper.tagName &&
            dashboardWrapper.tagName.toLowerCase() === 'form'
        ) {
            dashboardForm = dashboardWrapper;
        } else {
            dashboardForm =
                dashboardWrapper.querySelector('form');
        }

        if (!dashboardForm) {
            return false;
        }

        /*
         * Change the cloned form destination.
         */
        dashboardForm.setAttribute(
            'action',
            dashboardUrl
        );

        dashboardForm.setAttribute(
            'method',
            'get'
        );

        /*
         * Remove parameters belonging to the Excel export,
         * such as sesskey and its old id field.
         */
        dashboardForm
            .querySelectorAll('input[type="hidden"]')
            .forEach(function(input) {
                input.remove();
            });

        /*
         * Add only the current Feedback course-module ID.
         */
        const idInput = document.createElement('input');

        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = String(cmId);

        dashboardForm.prepend(idInput);

        /*
         * Find Moodle's cloned button.
         */
        const button = dashboardForm.querySelector(
            'button[type="submit"], ' +
            'input[type="submit"], ' +
            '.btn'
        );

        if (!button) {
            return false;
        }

        /*
         * Only change its text.
         *
         * CSS/classes remain exactly those generated by Moodle.
         */
        if (
            button.tagName.toLowerCase() === 'input'
        ) {
            button.value = dashboardLabel;
        } else {
            button.textContent = dashboardLabel;
        }

        button.setAttribute(
            'title',
            dashboardLabel
        );

        /*
         * Keep both buttons on the same line using Moodle/
         * Bootstrap utility classes.
         */
        container.classList.add(
            'd-flex',
            'flex-wrap',
            'align-items-center',
            'gap-2'
        );

        /*
         * Place immediately after Export to Excel.
         */
        if (originalWrapper) {
            originalWrapper.insertAdjacentElement(
                'afterend',
                dashboardWrapper
            );
        } else {
            exportForm.insertAdjacentElement(
                'afterend',
                dashboardWrapper
            );
        }

        return true;
    }

    /*
     * The analysis page normally exists already when footer JS runs.
     */
    if (addDashboardButton()) {
        return;
    }

    /*
     * Safety fallback for themes that render page content later.
     */
    const observer = new MutationObserver(function() {
        if (addDashboardButton()) {
            observer.disconnect();
        }
    });

    observer.observe(
        document.body,
        {
            childList: true,
            subtree: true
        }
    );

    window.setTimeout(function() {
        observer.disconnect();
    }, 10000);

})();
JS;

    $PAGE->requires->js_init_code(
        $javascript
    );
}
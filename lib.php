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
 * Adds the Dashboard NPS button to the native Feedback analysis page.
 *
 * @return string
 */
function local_feedbackdashboard_before_standard_top_of_body_html(): string {
    global $PAGE, $SCRIPT;

    /*
     * Run ONLY on:
     *
     * /mod/feedback/analysis.php
     */
    if ($SCRIPT !== '/mod/feedback/analysis.php') {
        return '';
    }

    /*
     * Make sure we are inside a course module.
     */
    if (empty($PAGE->cm)) {
        return '';
    }

    /*
     * Only native Feedback activities.
     */
    if ($PAGE->cm->modname !== 'feedback') {
        return '';
    }

    $context = context_module::instance(
        $PAGE->cm->id
    );

    /*
     * Only administrators / editing teachers who can
     * access Feedback reports and our Dashboard.
     */
    if (
        !has_capability(
            'mod/feedback:viewreports',
            $context
        )
    ) {
        return '';
    }

    if (
        !has_capability(
            'local/feedbackdashboard:view',
            $context
        )
    ) {
        return '';
    }

    /*
     * Dashboard URL for THIS Feedback.
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

    $urljson = json_encode(
        $dashboardurl->out(false),
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    );

    $cmidjson = json_encode(
        (int) $PAGE->cm->id
    );

    /*
     * We return the script directly instead of using
     * $PAGE->requires.
     *
     * The callback is executed before the body content
     * is rendered, therefore the script waits for
     * DOMContentLoaded.
     */
    return <<<HTML
<style>
    .form-buttons.feedbackdashboard-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .5rem;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const dashboardUrl = {$urljson};
    const cmId = {$cmidjson};

    /*
     * Moodle creates the native Excel button inside:
     *
     * <div class="form-buttons">
     */
    const container = document.querySelector(
        '.form-buttons'
    );

    if (!container) {
        console.warn(
            '[Feedback Dashboard] form-buttons não encontrado.'
        );

        return;
    }

    /*
     * Avoid duplicate buttons.
     */
    if (
        container.querySelector(
            '[data-feedbackdashboard="1"]'
        )
    ) {
        return;
    }

    /*
     * Locate Moodle's native Export to Excel form.
     */
    const exportForm = container.querySelector(
        'form'
    );

    if (!exportForm) {
        console.warn(
            '[Feedback Dashboard] formulário de exportação não encontrado.'
        );

        return;
    }

    /*
     * single_button normally has this structure:
     *
     * <div class="singlebutton">
     *     <form>
     *         <button class="btn ...">
     *     </form>
     * </div>
     *
     * Clone the complete Moodle structure so Dashboard
     * receives exactly the same visual style.
     */
    const originalWrapper =
        exportForm.closest('.singlebutton');

    let dashboardWrapper;

    if (originalWrapper) {

        dashboardWrapper =
            originalWrapper.cloneNode(true);

    } else {

        dashboardWrapper =
            exportForm.cloneNode(true);

    }

    dashboardWrapper.setAttribute(
        'data-feedbackdashboard',
        '1'
    );

    /*
     * Find the cloned form.
     */
    let dashboardForm;

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
        console.warn(
            '[Feedback Dashboard] clone do formulário falhou.'
        );

        return;
    }

    /*
     * Replace the Excel destination with Dashboard.
     */
    dashboardForm.action = dashboardUrl;
    dashboardForm.method = 'get';

    /*
     * Remove Excel parameters such as:
     *
     * sesskey
     * old id
     */
    dashboardForm
        .querySelectorAll(
            'input[type="hidden"]'
        )
        .forEach(function(input) {

            input.remove();

        });

    /*
     * Add the current Feedback ID.
     */
    const idInput =
        document.createElement('input');

    idInput.type = 'hidden';
    idInput.name = 'id';
    idInput.value = String(cmId);

    dashboardForm.prepend(
        idInput
    );

    /*
     * Locate Moodle's cloned button.
     */
    const button =
        dashboardForm.querySelector(
            'button[type="submit"], ' +
            'input[type="submit"], ' +
            '.btn'
        );

    if (!button) {
        console.warn(
            '[Feedback Dashboard] botão nativo não encontrado.'
        );

        return;
    }

    /*
     * Change only the text.
     *
     * The native Moodle classes remain untouched.
     */
    if (
        button.tagName.toLowerCase() === 'input'
    ) {

        button.value =
            'Dashboard NPS';

    } else {

        button.textContent =
            'Dashboard NPS';

    }

    button.title =
        'Abrir Dashboard NPS';

    /*
     * Keep both actions on the same row.
     */
    container.classList.add(
        'feedbackdashboard-actions'
    );

    /*
     * Insert directly after Exportar para o Excel.
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

    console.info(
        '[Feedback Dashboard] botão inserido com sucesso.'
    );
});
</script>
HTML;
}
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

$id = required_param('id', PARAM_INT);

// Load and validate the Feedback activity.
[$course, $cm] = get_course_and_cm_from_cmid($id, 'feedback');

require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);

require_capability(
    'local/feedbackdashboard:view',
    $context
);

// Load the Feedback database record.
$feedback = $DB->get_record(
    'feedback',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);

$pageurl = new moodle_url(
    '/local/feedbackdashboard/index.php',
    ['id' => $cm->id]
);

// Configure the page before generating the header/navigation.
$PAGE->set_url($pageurl);
$PAGE->set_course($course);
$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(
    get_string('dashboardtitle', 'local_feedbackdashboard')
    . ': '
    . format_string($feedback->name)
);
$PAGE->set_heading(format_string($course->fullname));

// Explicitly mark Dashboard as the active tab.
$PAGE->set_secondary_active_tab('feedbackdashboard');

// Set the navigation item as active when possible.
$dashboardnode = $PAGE->settingsnav->find(
    'feedbackdashboard',
    navigation_node::TYPE_CUSTOM
);

if ($dashboardnode) {
    $dashboardnode->make_active();
}

// Detect whether the Feedback is anonymous.
$isanonymous = ((int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES);

echo $OUTPUT->header();

echo $OUTPUT->heading(
    get_string('dashboardtitle', 'local_feedbackdashboard'),
    2
);

echo $OUTPUT->heading(format_string($feedback->name), 3);

$statusclass = $isanonymous ? 'alert alert-warning' : 'alert alert-info';
$statustext = $isanonymous
    ? get_string('anonymousfeedback', 'local_feedbackdashboard')
    : get_string('identifiedfeedback', 'local_feedbackdashboard');

echo html_writer::div(
    s($statustext),
    $statusclass
);

$table = new html_table();

$table->head = [
    get_string('feedbackname', 'local_feedbackdashboard'),
    get_string('coursename', 'local_feedbackdashboard'),
    get_string('responsecount', 'local_feedbackdashboard'),
    get_string('questionscount', 'local_feedbackdashboard'),
];

$table->data[] = [
    format_string($feedback->name),
    format_string($course->fullname),
    $responsecount,
    $questionscount,
];

echo html_writer::table($table);

echo $OUTPUT->notification(
    'A estrutura inicial do Dashboard está funcionando. '
    . 'Na próxima etapa, serão carregadas as perguntas e respostas.',
    'success'
);

echo $OUTPUT->footer();
<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');

$id = required_param('id', PARAM_INT);

// Load and validate the course module.
[$course, $cm] = get_course_and_cm_from_cmid($id, 'feedback');

// Require the user to be authenticated and enrolled or otherwise authorised.
require_course_login($course, true, $cm);

// Create the activity context.
$context = context_module::instance($cm->id);

// Use the same report permission used by the native Feedback reports.
require_capability('mod/feedback:viewreports', $context);

// Load the Feedback database record.
$feedback = $DB->get_record(
    'feedback',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);

// Count completed submissions.
$responsecount = $DB->count_records(
    'feedback_completed',
    ['feedback' => $feedback->id]
);

// Count questions and other Feedback items.
$questionscount = $DB->count_records_select(
    'feedback_item',
    'feedback = :feedbackid AND typ <> :labeltype AND typ <> :pagebreaktype',
    [
        'feedbackid' => $feedback->id,
        'labeltype' => 'label',
        'pagebreaktype' => 'pagebreak',
    ]
);

// Configure the page URL.
$pageurl = new moodle_url(
    '/local/feedbackdashboard/index.php',
    ['id' => $cm->id]
);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(
    get_string('dashboardtitle', 'local_feedbackdashboard') .
    ': ' .
    format_string($feedback->name)
);
$PAGE->set_heading(format_string($course->fullname));

// Set the navigation item as active when possible.
$dashboardnode = $PAGE->settingsnav->find(
    'local_feedbackdashboard',
    navigation_node::TYPE_SETTING
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
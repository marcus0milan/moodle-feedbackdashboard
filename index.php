<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Main page for the Feedback Dashboard plugin.
 *
 * @package    local_feedbackdashboard
 * @copyright  2026 Marcus Vinícius Milan da Silva
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');

global $DB, $OUTPUT, $PAGE;

// Course module ID from the URL.
$id = required_param('id', PARAM_INT);

/*
 * Load the course and course module.
 *
 * The second parameter guarantees that the supplied module ID belongs
 * to a native Moodle Feedback activity.
 */
[$course, $cm] = get_course_and_cm_from_cmid($id, 'feedback');

// Require the user to be logged in and able to access the course/module.
require_course_login($course, true, $cm);

// Create the activity context.
$context = context_module::instance($cm->id);

/*
 * Only administrators and users with the plugin capability may access
 * the Dashboard.
 *
 * Site administrators normally pass capability checks automatically,
 * but the explicit condition makes the intended rule clear.
 */
if (!is_siteadmin()) {
    require_capability(
        'local/feedbackdashboard:view',
        $context
    );
}

// Load the Feedback activity record.
$feedback = $DB->get_record(
    'feedback',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);

// Dashboard URL.
$pageurl = new moodle_url(
    '/local/feedbackdashboard/index.php',
    ['id' => $cm->id]
);

/*
 * Configure the Moodle page before calling $OUTPUT->header().
 *
 * set_cm() is especially important because the frontend callback in
 * lib.php checks $PAGE->cm to inject the Dashboard item into the
 * secondary navigation.
 */
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

$PAGE->set_heading(
    format_string($course->fullname)
);

/*
 * Count completed Feedback submissions.
 *
 * Each record in feedback_completed represents one completed submission.
 */
$responsecount = $DB->count_records(
    'feedback_completed',
    ['feedback' => $feedback->id]
);

/*
 * Count actual questions.
 *
 * Labels and page breaks are layout elements, not questions, so they
 * are excluded from the total.
 */
$questionscount = $DB->count_records_select(
    'feedback_item',
    'feedback = :feedbackid
        AND typ <> :labeltype
        AND typ <> :pagebreaktype',
    [
        'feedbackid' => $feedback->id,
        'labeltype' => 'label',
        'pagebreaktype' => 'pagebreak',
    ]
);

/*
 * Determine whether the Feedback is anonymous.
 *
 * mod/feedback/lib.php provides the Feedback anonymity constants.
 */
$isanonymous = (
    (int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES
);

/*
 * Dashboard content starts here.
 */
echo $OUTPUT->header();

echo $OUTPUT->heading(
    get_string('dashboardtitle', 'local_feedbackdashboard'),
    2
);

echo $OUTPUT->heading(
    format_string($feedback->name),
    3
);

// Show the Feedback identification mode.
if ($isanonymous) {
    echo $OUTPUT->notification(
        get_string(
            'anonymousfeedback',
            'local_feedbackdashboard'
        ),
        'warning'
    );
} else {
    echo $OUTPUT->notification(
        get_string(
            'identifiedfeedback',
            'local_feedbackdashboard'
        ),
        'info'
    );
}

/*
 * Summary table.
 */
$summarytable = new html_table();

$summarytable->attributes = [
    'class' => 'generaltable',
];

$summarytable->head = [
    get_string(
        'feedbackname',
        'local_feedbackdashboard'
    ),
    get_string(
        'coursename',
        'local_feedbackdashboard'
    ),
    get_string(
        'responsecount',
        'local_feedbackdashboard'
    ),
    get_string(
        'questionscount',
        'local_feedbackdashboard'
    ),
];

$summarytable->data[] = [
    format_string($feedback->name),
    format_string($course->fullname),
    format_string((string) $responsecount),
    format_string((string) $questionscount),
];

echo html_writer::table($summarytable);

/*
 * Empty-state message.
 */
if ($questionscount === 0) {
    echo $OUTPUT->notification(
        'Esta pesquisa ainda não possui questões.',
        'info'
    );
} else if ($responsecount === 0) {
    echo $OUTPUT->notification(
        'Esta pesquisa possui questões, mas ainda não recebeu respostas.',
        'info'
    );
} else {
    echo $OUTPUT->notification(
        'A estrutura inicial do Dashboard está funcionando. '
        . 'Na próxima etapa serão carregadas as perguntas e respostas.',
        'success'
    );
}

/*
 * Temporary diagnostic information.
 *
 * This block helps confirm that the correct Feedback activity is being
 * loaded during development. It can be removed later.
 */
$diagnostictable = new html_table();

$diagnostictable->attributes = [
    'class' => 'generaltable',
];

$diagnostictable->caption = 'Informações técnicas';

$diagnostictable->head = [
    'Campo',
    'Valor',
];

$diagnostictable->data = [
    [
        'Course module ID',
        format_string((string) $cm->id),
    ],
    [
        'Feedback instance ID',
        format_string((string) $feedback->id),
    ],
    [
        'Course ID',
        format_string((string) $course->id),
    ],
    [
        'Modo',
        $isanonymous
            ? get_string(
                'anonymousfeedback',
                'local_feedbackdashboard'
            )
            : get_string(
                'identifiedfeedback',
                'local_feedbackdashboard'
            ),
    ],
];

echo html_writer::table($diagnostictable);

echo $OUTPUT->footer();
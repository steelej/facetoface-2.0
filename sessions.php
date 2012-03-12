<?php
global $DB, $OUTPUT, $PAGE;

require_once '../../config.php';
require_once 'lib.php';
require_once 'session_form.php';

$id = optional_param('id', 0, PARAM_INT); // Course Module ID
$f = optional_param('f', 0, PARAM_INT); // facetoface Module ID
$s = optional_param('s', 0, PARAM_INT); // facetoface session ID
$c = optional_param('c', 0, PARAM_INT); // copy session
$d = optional_param('d', 0, PARAM_INT); // delete session
$confirm = optional_param('confirm', false, PARAM_BOOL); // delete confirmation

$nbdays = 1; // default number to show

$session = null;
if ($id) {
    if (!$cm = $DB->get_record('course_modules', array('id'=>$id))) {
        print_error('error:incorrectcoursemoduleid', 'facetoface');
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        print_error('error:coursemisconfigured', 'facetoface');
    }
    if (!$facetoface =$DB->get_record('facetoface',array('id'=>$cm->instance))) {
        print_error('error:incorrectcoursemodule', 'facetoface');
    }
}
elseif ($s) {
     if (!$session = facetoface_get_session($s)) {
         print_error('error:incorrectcoursemodulesession', 'facetoface');
     }
     if (!$facetoface = $DB->get_record('facetoface',array('id'=>$session->facetoface))) {
         print_error('error:incorrectfacetofaceid', 'facetoface');
     }
     if (!$course = $DB->get_record('course', array('id'=> $facetoface->course))) {
         print_error('error:coursemisconfigured', 'facetoface');
     }
     if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
         print_error('error:incorrectcoursemoduleid', 'facetoface');
     }

     $nbdays = count($session->sessiondates);
}
else {
    if (!$facetoface = $DB->get_record('facetoface', array('id'=>$f))) {
        print_error('error:incorrectfacetofaceid', 'facetoface');
    }
    if (!$course = $DB->get_record('course', array('id'=>$facetoface->course))) {
        print_error('error:coursemisconfigured', 'facetoface');
    }
    if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
        print_error('error:incorrectcoursemoduleid', 'facetoface');
    }
}

require_course_login($course,true,$cm);
$errorstr = '';
$context = get_context_instance(CONTEXT_COURSE, $course->id);
require_capability('mod/facetoface:editsessions', $context);

$returnurl = "view.php?f=$facetoface->id";

// Handle deletions
if ($d and $confirm) {
    if (!confirm_sesskey()) {
        print_error('confirmsesskeybad', 'error');
    }

    if (facetoface_delete_session($session)) {
        add_to_log($course->id, 'facetoface', 'delete session', 'sessions.php?s='.$session->id, $facetoface->id, $cm->id);
    }
    else {
        add_to_log($course->id, 'facetoface', 'delete session (FAILED)', 'sessions.php?s='.$session->id, $facetoface->id, $cm->id);
        print_error('error:couldnotdeletesession', 'facetoface', $returnurl);
    }
    redirect($returnurl);
}

$customfields = facetoface_get_session_customfields();

$mform = new mod_facetoface_session_form(null, compact('id', 'f', 's', 'c', 'nbdays', 'customfields', 'course'));
if ($mform->is_cancelled()){
    redirect($returnurl);
}

if ($fromform = $mform->get_data()) { // Form submitted

    if (empty($fromform->submitbutton)) {
        print_error('error:unknownbuttonclicked', 'facetoface', $returnurl);
    }

    // Pre-process fields
    if (empty($fromform->allowoverbook)) {
        $fromform->allowoverbook = 0;
    }
    if (empty($fromform->duration)) {
        $fromform->duration = 0;
    }
    if (empty($fromform->normalcost)) {
        $fromform->normalcost = 0;
    }
    if (empty($fromform->discountcost)) {
        $fromform->discountcost = 0;
    }

    $sessiondates = array();
    for ($i = 0; $i < $fromform->date_repeats; $i++) {
        if (!empty($fromform->datedelete[$i])) {
            continue; // skip this date
        }

        $timestartfield = "timestart[$i]";
        $timefinishfield = "timefinish[$i]";
        if (!empty($fromform->$timestartfield) and !empty($fromform->$timefinishfield)) {
            $date = new object();
            $date->timestart = $fromform->$timestartfield;
            $date->timefinish = $fromform->$timefinishfield;
            $sessiondates[] = $date;
        }
    }

    $todb = new object();
    $todb->facetoface = $facetoface->id;
    $todb->datetimeknown = $fromform->datetimeknown;
    $todb->capacity = $fromform->capacity;
    $todb->allowoverbook = $fromform->allowoverbook;
    $todb->duration = $fromform->duration;
    $todb->normalcost = $fromform->normalcost;
    $todb->discountcost = $fromform->discountcost;
    $todb->details = trim($fromform->details);

    $sessionid = null;
    $transaction = $DB->start_delegated_transaction();

    $update = false;
    if (!$c and $session != null) {
        $update = true;
        $sessionid = $session->id;

        $todb->id = $session->id;
        if (!facetoface_update_session($todb, $sessiondates)) {
            $transaction->force_transaction_rollback();
            add_to_log($course->id, 'facetoface', 'update session (FAILED)', "sessions.php?s=$session->id", $facetoface->id, $cm->id);
            print_error('error:couldnotupdatesession', 'facetoface', $returnurl);
        }

        // Remove old site-wide calendar entry
        if (!facetoface_remove_session_from_site_calendar($session)) {
            $transaction->force_transaction_rollback();
            print_error('error:couldnotupdatecalendar', 'facetoface', $returnurl);
        }
    }
    else {
        if (!$sessionid = facetoface_add_session($todb, $sessiondates)) {
            $transaction->force_transaction_rollback();
            add_to_log($course->id, 'facetoface', 'add session (FAILED)', 'sessions.php?f='.$facetoface->id, $facetoface->id, $cm->id);
            print_error('error:couldnotaddsession', 'facetoface', $returnurl);
        }
    }

    foreach ($customfields as $field) {
        $fieldname = "custom_$field->shortname";
        if (!isset($fromform->$fieldname)) {
            $fromform->$fieldname = ''; // need to be able to clear fields
        }

        if (!facetoface_save_customfield_value($field->id, $fromform->$fieldname, $sessionid, 'session')) {
            $transaction->force_transaction_rollback();
            print_error('error:couldnotsavecustomfield', 'facetoface', $returnurl);
        }
    }

    // Save trainer roles
    if (isset($fromform->trainerrole)) {
        facetoface_update_trainers($sessionid, $fromform->trainerrole);
    }

    // Retrieve record that was just inserted/updated
    if (!$session = facetoface_get_session($sessionid)) {
        $transaction->force_transaction_rollback();
        print_error('error:couldnotfindsession', 'facetoface', $returnurl);
    }

    // Put the session in the site-wide calendar (needs customfields to be up to date)
    if (!facetoface_add_session_to_site_calendar($session, $facetoface)) {
        $transaction->force_transaction_rollback();
        print_error('error:couldnotupdatecalendar', 'facetoface', $returnurl);
    }

    if ($update) {
        add_to_log($course->id, 'facetoface', 'updated session', "sessions.php?s=$session->id", $facetoface->id, $cm->id);
    }
    else {
        add_to_log($course->id, 'facetoface', 'added session', 'facetoface', 'sessions.php?f='.$facetoface->id, $facetoface->id, $cm->id);
    }

    $transaction->allow_commit();
    redirect($returnurl);
}
elseif ($session != null) { // Edit mode
    // Set values for the form
    $toform = new object();
    $toform->datetimeknown = (1 == $session->datetimeknown);
    $toform->capacity = $session->capacity;
    $toform->allowoverbook = $session->allowoverbook;
    $toform->duration = $session->duration;
    $toform->normalcost = $session->normalcost;
    $toform->discountcost = $session->discountcost;
    $toform->details = $session->details;

    if ($session->sessiondates) {
        $i = 0;
        foreach ($session->sessiondates as $date) {
            $idfield = "sessiondateid[$i]";
            $timestartfield = "timestart[$i]";
            $timefinishfield = "timefinish[$i]";
            $toform->$idfield = $date->id;
            $toform->$timestartfield = $date->timestart;
            $toform->$timefinishfield = $date->timefinish;
            $i++;
        }
    }

    foreach ($customfields as $field) {
        $fieldname = "custom_$field->shortname";
        $toform->$fieldname = facetoface_get_customfield_value($field, $session->id, 'session');
    }

    $mform->set_data($toform);
}

if ($c) {
    $heading = get_string('copyingsession', 'facetoface', $facetoface->name);
}
else if ($d) {
    $heading = get_string('deletingsession', 'facetoface', $facetoface->name);
}
else if ($id or $f) {
    $heading = get_string('addingsession', 'facetoface', $facetoface->name);
}
else {
    $heading = get_string('editingsession', 'facetoface', $facetoface->name);
}

$pagetitle = format_string($facetoface->name);

$PAGE->set_cm($cm);
$PAGE->set_url('/mod/facetoface/sessions.php', array('f' => $f));
$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

echo $OUTPUT->box_start();
echo $OUTPUT->heading($heading);

if (!empty($errorstr)) {
    echo '<div class="notifyproblem" align="center"><span style="font-size: 12px; line-height: 18px;">'.$errorstr.'</span></div>';
}

if ($d) {
    $viewattendees = has_capability('mod/facetoface:viewattendees', $context);
    facetoface_print_session($session, $viewattendees);
    $optionsyes = array('sesskey'=>sesskey(), 's'=>$session->id, 'd'=>1, 'confirm'=>1);
    echo $OUTPUT->confirm(get_string('deletesessionconfirm', 'facetoface', format_string($facetoface->name)),
        new moodle_url('sessions.php', $optionsyes),
        new moodle_url($returnurl));
}
else {
    $mform->display();
}

echo $OUTPUT->box_end();
echo $OUTPUT->footer($course);

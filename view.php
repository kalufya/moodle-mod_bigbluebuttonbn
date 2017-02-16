<?php
/**
 * View a BigBlueButton room
 *
 * @package   mod_bigbluebuttonbn
 * @author    Fred Dixon  (ffdixon [at] blindsidenetworks [dt] com)
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 * @copyright 2010-2015 Blindside Networks Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v2 or later
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/locallib.php');

$id = required_param('id', PARAM_INT); // Course Module ID, or
$b  = optional_param('n', 0, PARAM_INT); // bigbluebuttonbn instance ID
$group  = optional_param('group', 0, PARAM_INT); // group instance ID

if ($id) {
    $cm = get_coursemodule_from_id('bigbluebuttonbn', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $bigbluebuttonbn = $DB->get_record('bigbluebuttonbn', array('id' => $cm->instance), '*', MUST_EXIST);
} elseif ($b) {
    $bigbluebuttonbn = $DB->get_record('bigbluebuttonbn', array('id' => $b), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $bigbluebuttonbn->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('bigbluebuttonbn', $bigbluebuttonbn->id, $course->id, false, MUST_EXIST);
} else {
    print_error(get_string('view_error_url_missing_parameters', 'bigbluebuttonbn'));
}

require_login($course, true, $cm);

$version_major = bigbluebuttonbn_get_moodle_version_major();
if ($version_major < '2013111800') {
    //This is valid before v2.6
    $module = $DB->get_record('modules', array('name' => 'bigbluebuttonbn'));
    $module_version = $module->version;
} else {
    //This is valid after v2.6
    $module_version = get_config('mod_bigbluebuttonbn', 'version');
}
$context = bigbluebuttonbn_get_context_module($cm->id);


bigbluebuttonbn_event_log(BIGBLUEBUTTON_EVENT_ACTIVITY_VIEWED, $bigbluebuttonbn, $context, $cm);

////////////////////////////////////////////////
/////  BigBlueButton Session Setup Starts  /////
////////////////////////////////////////////////
// BigBluebuttonBN activity data
$bbbsession['bigbluebuttonbn'] = $bigbluebuttonbn;

// User data
$bbbsession['username'] = fullname($USER);
$bbbsession['userID'] = $USER->id;
$bbbsession['roles'] = get_user_roles($context, $USER->id, true);

// User roles
if ($bigbluebuttonbn->participants == null || $bigbluebuttonbn->participants == "" || $bigbluebuttonbn->participants == "[]") {
    //The room that is being used comes from a previous version
    $bbbsession['moderator'] = has_capability('mod/bigbluebuttonbn:moderate', $context);
} else {
    $bbbsession['moderator'] = bigbluebuttonbn_is_moderator($bbbsession['userID'], $bbbsession['roles'], $bigbluebuttonbn->participants);
}
$bbbsession['administrator'] = has_capability('moodle/category:manage', $context);
$bbbsession['managerecordings'] = ($bbbsession['administrator'] || has_capability('mod/bigbluebuttonbn:managerecordings', $context));

// BigBlueButton server data
$bbbsession['endpoint'] = bigbluebuttonbn_get_cfg_server_url();
$bbbsession['shared_secret'] = bigbluebuttonbn_get_cfg_shared_secret();

// Server data
$bbbsession['modPW'] = $bigbluebuttonbn->moderatorpass;
$bbbsession['viewerPW'] = $bigbluebuttonbn->viewerpass;

// Database info related to the activity
$bbbsession['meetingdescription'] = $bigbluebuttonbn->intro;
$bbbsession['welcome'] = $bigbluebuttonbn->welcome;
if (!isset($bbbsession['welcome']) || $bbbsession['welcome'] == '') {
    $bbbsession['welcome'] = get_string('mod_form_field_welcome_default', 'bigbluebuttonbn');
}

$bbbsession['userlimit'] = bigbluebuttonbn_get_cfg_userlimit_editable() ? intval($bigbluebuttonbn->userlimit) : intval(bigbluebuttonbn_get_cfg_userlimit_default());
$bbbsession['voicebridge'] = ($bigbluebuttonbn->voicebridge > 0) ? 70000 + $bigbluebuttonbn->voicebridge : $bigbluebuttonbn->voicebridge;
$bbbsession['wait'] = $bigbluebuttonbn->wait;
$bbbsession['record'] = $bigbluebuttonbn->record;
if ($bigbluebuttonbn->record) {
    $bbbsession['welcome'] .= '<br><br>' . get_string('bbbrecordwarning', 'bigbluebuttonbn');
}
$bbbsession['tagging'] = $bigbluebuttonbn->tagging;

$bbbsession['openingtime'] = $bigbluebuttonbn->openingtime;
$bbbsession['closingtime'] = $bigbluebuttonbn->closingtime;

// Additional info related to the course
$bbbsession['course'] = $course;
$bbbsession['coursename'] = $course->fullname;
$bbbsession['cm'] = $cm;
$bbbsession['context'] = $context;

// Metadata (origin)
$bbbsession['origin'] = "Moodle";
$bbbsession['originVersion'] = $CFG->release;
$parsedUrl = parse_url($CFG->wwwroot);
$bbbsession['originServerName'] = $parsedUrl['host'];
$bbbsession['originServerUrl'] = $CFG->wwwroot;
$bbbsession['originServerCommonName'] = '';
$bbbsession['originTag'] = 'moodle-mod_bigbluebuttonbn (' . $module_version . ')';
////////////////////////////////////////////////
/////   BigBlueButton Session Setup Ends   /////
////////////////////////////////////////////////

// Validates if the BigBlueButton server is running
$serverVersion = bigbluebuttonbn_getServerVersion($bbbsession['endpoint']);
if (!isset($serverVersion)) { //Server is not working
    if ($bbbsession['administrator']) {
        print_error('view_error_unable_join', 'bigbluebuttonbn', $CFG->wwwroot . '/admin/settings.php?section=modsettingbigbluebuttonbn');
    } else if ($bbbsession['moderator']) {
        print_error('view_error_unable_join_teacher', 'bigbluebuttonbn', $CFG->wwwroot . '/course/view.php?id=' . $bigbluebuttonbn->course);
    } else {
        print_error('view_error_unable_join_student', 'bigbluebuttonbn', $CFG->wwwroot . '/course/view.php?id=' . $bigbluebuttonbn->course);
    }
}

// Mark viewed by user (if required)
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Print the page header
$PAGE->set_context($context);
$PAGE->set_url($CFG->wwwroot . '/mod/bigbluebuttonbn/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($bigbluebuttonbn->name));
$PAGE->set_cacheable(false);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// Validate if the user is in a role allowed to join
if (!has_capability('moodle/category:manage', $context) && !has_capability('mod/bigbluebuttonbn:join', $context)) {
    echo $OUTPUT->header();
    if (isguestuser()) {
        echo $OUTPUT->confirm('<p>' . get_string('view_noguests', 'bigbluebuttonbn') . '</p>' . get_string('liketologin'),
            get_login_url(), $CFG->wwwroot . '/course/view.php?id=' . $course->id);
    } else {
        echo $OUTPUT->confirm('<p>' . get_string('view_nojoin', 'bigbluebuttonbn') . '</p>' . get_string('liketologin'),
            get_login_url(), $CFG->wwwroot . '/course/view.php?id=' . $course->id);
    }

    echo $OUTPUT->footer();
    exit;
}

// Operation URLs
$bbbsession['courseURL'] = $CFG->wwwroot . '/course/view.php?id=' . $bigbluebuttonbn->course;
$bbbsession['logoutURL'] = $CFG->wwwroot . '/mod/bigbluebuttonbn/bbb_view.php?action=logout&id=' . $id . '&bn=' . $bbbsession['bigbluebuttonbn']->id;
$bbbsession['recordingReadyURL'] = $CFG->wwwroot.'/mod/bigbluebuttonbn/bbb_broker.php?action=recording_ready&bigbluebuttonbn='.$bbbsession['bigbluebuttonbn']->id;
$bbbsession['meetingEventsURL'] = $CFG->wwwroot . '/mod/bigbluebuttonbn/bbb_broker.php?action=meeting_events&bigbluebuttonbn=' . $bbbsession['bigbluebuttonbn']->id;
$bbbsession['joinURL'] = $CFG->wwwroot . '/mod/bigbluebuttonbn/bbb_view.php?action=join&id=' . $id . '&bigbluebuttonbn=' . $bbbsession['bigbluebuttonbn']->id;

// Output starts here
echo $OUTPUT->header();
/// Shows version as a comment
echo '
<!-- moodle-mod_bigbluebuttonbn ('.$module_version.') -->'."\n";


/// find out current groups mode
$groupmode = groups_get_activity_groupmode($bbbsession['cm']);
if ($groupmode == NOGROUPS) {  //No groups mode
    $bbbsession['meetingid'] = $bbbsession['bigbluebuttonbn']->meetingid . '-' . $bbbsession['course']->id . '-' . $bbbsession['bigbluebuttonbn']->id;
    $bbbsession['meetingname'] = $bbbsession['bigbluebuttonbn']->name;

} else {                                        // Separate or visible groups mode
    echo $OUTPUT->box_start('generalbox boxaligncenter');
    echo '<br><div class="alert alert-warning">' . get_string('view_groups_selection_warning', 'bigbluebuttonbn') . '</div>';
    echo $OUTPUT->box_end();

    groups_print_activity_menu($cm, $CFG->wwwroot . '/mod/bigbluebuttonbn/view.php?id=' . $bbbsession['cm']->id);
    if ($groupmode == SEPARATEGROUPS) {
        $groups = groups_get_activity_allowed_groups($bbbsession['cm']);
        $current_group = current($groups);
        $bbbsession['group'] = $current_group->id;
    } else {
        $groups = groups_get_all_groups($bbbsession['course']->id);
        $bbbsession['group'] = groups_get_activity_group($bbbsession['cm'], true);
    }

    $bbbsession['meetingid'] = $bbbsession['bigbluebuttonbn']->meetingid . '-' . $bbbsession['course']->id . '-' . $bbbsession['bigbluebuttonbn']->id . '[' . $bbbsession['group'] . ']';
    if ($bbbsession['group'] > 0) {
        $group_name = groups_get_group_name($bbbsession['group']);
    } else {
        $group_name = get_string('allparticipants');
    }
    $bbbsession['meetingname'] = $bbbsession['bigbluebuttonbn']->name . ' (' . $group_name . ')';
}
// Metadata (context)
$bbbsession['contextActivityName'] = $bbbsession['meetingname'];
$bbbsession['contextActivityDescription'] = bigbluebuttonbn_html2text($bbbsession['meetingdescription'], 64);
$bbbsession['contextActivityTags'] = bigbluebuttonbn_get_tags($cm->id); // Same as $id

$bigbluebuttonbn_activity = 'open';
$now = time();
if (!empty($bigbluebuttonbn->openingtime) && $now < $bigbluebuttonbn->openingtime) {
    //ACTIVITY HAS NOT BEEN OPENED
    $bigbluebuttonbn_activity = 'not_started';

} else if (!empty($bigbluebuttonbn->closingtime) && $now > $bigbluebuttonbn->closingtime) {
    //ACTIVITY HAS BEEN CLOSED
    $bigbluebuttonbn_activity = 'ended';
    $bbbsession['presentation'] = bigbluebuttonbn_get_presentation_array($context, $bigbluebuttonbn->presentation);

} else {
    //ACTIVITY OPEN
    $bbbsession['presentation'] = bigbluebuttonbn_get_presentation_array($bbbsession['context'], $bigbluebuttonbn->presentation, $bigbluebuttonbn->id);
}

// Initialize session variable used across views
$SESSION->bigbluebuttonbn_bbbsession = $bbbsession;
bigbluebuttonbn_view($bbbsession, $bigbluebuttonbn_activity);

// Finish the page
echo $OUTPUT->footer();

function bigbluebuttonbn_view($bbbsession, $activity) {
    global $CFG, $DB, $OUTPUT, $PAGE;

    $instance_type_profiles = bigbluebuttonbn_get_instance_type_profiles();
    $features = isset($bbbsession['bigbluebuttonbn']->type) ? $instance_type_profiles[$bbbsession['bigbluebuttonbn']->type]['features'] : $instance_type_profiles[0]['features'];
    $showroom = (in_array('all', $features) || in_array('showroom', $features));
    $showrecordings = (in_array('all', $features) || in_array('showrecordings', $features));
    $importrecordings = (in_array('all', $features) || in_array('importrecordings', $features));

    //JavaScript variables
    $waitformoderator_ping_interval = bigbluebuttonbn_get_cfg_waitformoderator_ping_interval();
    list($lang, $locale_encoder) = explode('.', get_string('locale', 'core_langconfig'));
    list($locale_code, $locale_sub_code) = explode('_', $lang);
    $jsvars = array(
        'activity' => $activity,
        'locales' => bigbluebuttonbn_get_locales_for_view(),
        'locale' => $locale_code,
        'profile_features' => $features
    );
    $jsdependences = array();

    $output  = $OUTPUT->heading($bbbsession['meetingname'], 3);
    $output .= $OUTPUT->heading($bbbsession['meetingdescription'], 5);


    if ($showroom) {
        //JavaScript variables for room
        $jsvars += array(
            'meetingid' => $bbbsession['meetingid'],
            'bigbluebuttonbnid' => $bbbsession['bigbluebuttonbn']->id,
            'ping_interval' => ($waitformoderator_ping_interval > 0 ? $waitformoderator_ping_interval * 1000 : 15000),
            'userlimit' => $bbbsession['userlimit'],
            'opening' => ($bbbsession['openingtime']) ? get_string('mod_form_field_openingtime', 'bigbluebuttonbn') . ': ' . userdate($bbbsession['openingtime']) : '',
            'closing' => ($bbbsession['closingtime']) ? get_string('mod_form_field_closingtime', 'bigbluebuttonbn') . ': ' . userdate($bbbsession['closingtime']) : ''
        );

        //JavaScript dependences for room
        $jsdependences += array('datasource-get', 'datasource-jsonschema', 'datasource-polling');

        $output .= $OUTPUT->box_start('generalbox boxaligncenter', 'bigbluebuttonbn_view_message_box');
        $output .= '<br><span id="status_bar"></span><br>';
        $output .= '<span id="control_panel"></span>';
        $output .= $OUTPUT->box_end();

        $output .= $OUTPUT->box_start('generalbox boxaligncenter', 'bigbluebuttonbn_view_action_button_box');
        $output .= '<br><br><span id="join_button"></span>&nbsp;<span id="end_button"></span>' . "\n";
        $output .= $OUTPUT->box_end();

        if ($activity == 'not_started') {
            // Do nothing
        } else {
            if ($activity == 'ended') {
                bigbluebuttonbn_view_ended($bbbsession);
            } else {
                bigbluebuttonbn_view_joining($bbbsession);
            }
        }

        if ($showrecordings && isset($bbbsession['record']) && $bbbsession['record']) {
            $output .= html_writer::tag('h4', get_string('view_section_title_recordings', 'bigbluebuttonbn'));
        }
    }

    if ($showrecordings) {
        // Get recordings
        $recordings = bigbluebuttonbn_get_recordings($bbbsession['course']->id, $showroom ? $bbbsession['bigbluebuttonbn']->id : NULL, FALSE, $bbbsession['bigbluebuttonbn']->recordings_deleted_activities);

        if ( isset($recordings) && !empty($recordings) && !array_key_exists('messageKey', $recordings)) {  // There are recordings for this meeting
            //If there are meetings with recordings load the data to the table
            if( $bbbsession['bigbluebuttonbn']->recordings_html ) {
                // Render a plain html table
                $output .= bigbluebutton_output_recording_table($bbbsession, $recordings) . "\n";

            } else {
                // Render a YUI table
                $output .= html_writer::div('', '', array('id' => 'bigbluebuttonbn_yui_table'));

                //JavaScript variables for recordings with YUI
                $jsvars += array(
                        'columns' => bigbluebuttonbn_get_recording_columns($bbbsession, $recordings),
                        'data' => bigbluebuttonbn_get_recording_data($bbbsession, $recordings)
                );

                //JavaScript dependences for recordings with YUI
                $jsdependences += array('datatable', 'datatable-sort', 'datatable-paginator', 'datatype-number');
            }
        } else {
            //There are no recordings to be shown.
            $output .= html_writer::div(get_string('view_message_norecordings', 'bigbluebuttonbn'), '', array('id' => 'bigbluebuttonbn_html_table'));
        }

        if ($importrecordings && $bbbsession['managerecordings'] && bigbluebuttonbn_get_cfg_importrecordings_enabled()) {
            $button_import_recordings = html_writer::tag('input', '', array('type' => 'button', 'value' => get_string('view_recording_button_import', 'bigbluebuttonbn'), 'class' => 'btn btn-secondary', 'onclick' => 'window.location=\'' . $CFG->wwwroot . '/mod/bigbluebuttonbn/import_view.php?bn=' . $bbbsession['bigbluebuttonbn']->id . '\''));
            $output .= html_writer::start_tag('br');
            $output .= html_writer::tag('span', $button_import_recordings, array('id'=>"import_recording_links_button"));
            $output .= html_writer::tag('span', '', array('id'=>"import_recording_links_table"));
        }
    }

    $output .= html_writer::empty_tag('br').html_writer::empty_tag('br').html_writer::empty_tag('br');

    echo $output;

    //Require aggregated JavaScript variables
    $PAGE->requires->data_for_js('bigbluebuttonbn', $jsvars);

    //Require JavaScript module
    $jsmodule = array(
        'name'     => 'mod_bigbluebuttonbn',
        'fullpath' => '/mod/bigbluebuttonbn/module.js',
        'requires' => $jsdependences,
    );
    $PAGE->requires->js_init_call('M.mod_bigbluebuttonbn.view_init', array(), false, $jsmodule);
}

function bigbluebuttonbn_view_joining($bbbsession) {

    if ($bbbsession['tagging'] && ($bbbsession['administrator'] || $bbbsession['moderator'])) {
        echo '' .
            '<div id="panelContent" class="hidden">' . "\n" .
            '  <div class="yui3-widget-bd">' . "\n" .
            '    <form>' . "\n" .
            '      <fieldset>' . "\n" .
            '        <input type="hidden" name="join" id="meeting_join_url" value="">' . "\n" .
            '        <input type="hidden" name="message" id="meeting_message" value="">' . "\n" .
            '        <div>' . "\n" .
            '          <label for="name">' . get_string('view_recording_name', 'bigbluebuttonbn') . '</label><br/>' . "\n" .
            '          <input type="text" name="name" id="recording_name" placeholder="">' . "\n" .
            '        </div><br>' . "\n" .
            '        <div>' . "\n" .
            '          <label for="description">' . get_string('view_recording_description', 'bigbluebuttonbn') . '</label><br/>' . "\n" .
            '          <input type="text" name="description" id="recording_description" value="" placeholder="">' . "\n" .
            '        </div><br>' . "\n" .
            '        <div>' . "\n" .
            '          <label for="tags">' . get_string('view_recording_tags', 'bigbluebuttonbn') . '</label><br/>' . "\n" .
            '          <input type="text" name="tags" id="recording_tags" value="" placeholder="">' . "\n" .
            '        </div>' . "\n" .
            '      </fieldset>' . "\n" .
            '    </form>' . "\n" .
            '  </div>' . "\n" .
            '</div>';
    }
}

function bigbluebuttonbn_view_ended($bbbsession) {
    global $OUTPUT;

    if (!is_null($bbbsession['presentation']['url'])) {
        $attributes = array('title' => $bbbsession['presentation']['name']);
        $icon = new pix_icon($bbbsession['presentation']['icon'], $bbbsession['presentation']['mimetype_description']);

        echo '<h4>' . get_string('view_section_title_presentation', 'bigbluebuttonbn') . '</h4>' .
                '' . $OUTPUT->action_icon($bbbsession['presentation']['url'], $icon, null, array(), false) . '' .
                '' . $OUTPUT->action_link($bbbsession['presentation']['url'], $bbbsession['presentation']['name'], null, $attributes) . '<br><br>';
    }
}

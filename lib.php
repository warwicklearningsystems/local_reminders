<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library function for reminders cron function.
 *
 * @package    local
 * @subpackage reminders
 * @copyright  2012 Isuru Madushanka Weerarathna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/reminders/reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/site_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/user_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/course_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/group_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder_questionnaire.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder_assignment.class.php');
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder_quiz.class.php');

require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/accesslib.php');

require_once($CFG->dirroot . '/availability/classes/info_module.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/questionnaire/locallib.php');
require_once($CFG->dirroot . '/local/reminders/classes/quiz/report.php');

/// CONSTANTS ///////////////////////////////////////////////////////////

DEFINE('REMINDERS_DAYIN_SECONDS', 24 * 3600);

DEFINE('REMINDERS_FIRST_CRON_CYCLE_CUTOFF_DAYS', 5);

DEFINE('REMINDERS_7DAYSBEFORE_INSECONDS', 7*24*3600);
DEFINE('REMINDERS_3DAYSBEFORE_INSECONDS', 3*24*3600);
DEFINE('REMINDERS_1DAYBEFORE_INSECONDS', 24*3600);

DEFINE('REMINDERS_SEND_ALL_EVENTS', 50);
DEFINE('REMINDERS_SEND_ONLY_VISIBLE', 51);

DEFINE('REMINDERS_ACTIVITY_BOTH', 60);
DEFINE('REMINDERS_ACTIVITY_ONLY_OPENINGS', 61);
DEFINE('REMINDERS_ACTIVITY_ONLY_CLOSINGS', 62);

DEFINE('REMINDERS_SEND_AS_NO_REPLY', 70);
DEFINE('REMINDERS_SEND_AS_ADMIN', 71);

// require_login();

/// FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Function to be run periodically according to the moodle cron
 * Finds all events due for a reminder and send them out to the users.
 *  
 */
function local_reminders_cron() {
    global $CFG, $DB, $PAGE;
    
    if (!isset($CFG->local_reminders_enable) || !$CFG->local_reminders_enable) {
        mtrace("   [Local Reminder] This cron cycle will be skipped, because plugin is not enabled!");
        return;
    }
       
    $aheaddaysindex = array(7 => 0, 3 => 1, 1 => 2);
    $eventtypearray = array('site', 'user', 'course', 'due', 'group');

    // loading roles allowed to receive reminder messages from configuration
    //
    $allroles = get_all_roles();
    $courseroleids = array();
    $activityroleids = array();
    if (!empty($allroles)) {
        $flag = 0;
        foreach ($allroles as $arole) {
            $roleoptionactivity = $CFG->local_reminders_activityroles;
            if (isset($roleoptionactivity[$flag]) && $roleoptionactivity[$flag] == '1') {
                $activityroleids[] = $arole->id;
            }
            $roleoption = $CFG->local_reminders_courseroles;
            if (isset($roleoption[$flag]) && $roleoption[$flag] == '1') {
                $courseroleids[] = $arole->id;
            }
            $flag++;
        }
    }

    // older implementation to retrieve most recent execution about reminders cron
    // cycle
    //
    //$params = array();
    //$selector = "l.course = 0 AND l.module = 'local_reminders' AND l.action = 'cron'";
    //$totalcount = 0;
    //$logrows = get_logs($selector, $params, 'l.time DESC', '', 1, $totalcount);

    // we need only last record only, so we limit the returning number of rows at most by one.
    //
    $logrows = $DB->get_records("local_reminders", array(), 'time DESC', '*', 0, 1);

    $timewindowstart = time();
    if (!$logrows) {  // this is the first cron cycle, after plugin is just installed
        mtrace("   [Local Reminder] This is the first cron cycle");
        $timewindowstart = $timewindowstart - REMINDERS_FIRST_CRON_CYCLE_CUTOFF_DAYS * 24 * 3600;
    } else {
        // info field includes that starting time of last cron cycle.
        $firstrecord = current($logrows);
        $timewindowstart = $firstrecord->time + 1;
    }
    
    // end of the time window will be set as current
    $timewindowend = time();

    // now lets filter appropiate events to send reminders
    //
    $secondsaheads = array(REMINDERS_7DAYSBEFORE_INSECONDS, REMINDERS_3DAYSBEFORE_INSECONDS, 
        REMINDERS_1DAYBEFORE_INSECONDS);

    // append custom schedule if any of event categories has defined it.
    foreach ($eventtypearray as $etype) {
        $tempconfigstr = 'local_reminders_'.$etype.'custom';
        if (isset($CFG->$tempconfigstr) && !empty($CFG->$tempconfigstr)
            && $CFG->$tempconfigstr > 0 && !in_array($CFG->$tempconfigstr, $secondsaheads)) {
            array_push($secondsaheads, $CFG->$tempconfigstr);
        }
    }

    $whereclause = '((timestart + timeduration) > '.$timewindowend.') AND (';
    $flagor = false;
    foreach ($secondsaheads as $sahead) {
        if($flagor) {
            $whereclause .= ' OR ';
        }
        $whereclause .= '((timestart + timeduration) - '.$sahead.' >= '.$timewindowstart.' AND '.
                        '(timestart + timeduration) - '.$sahead.' <= '.$timewindowend.')';
        $flagor = true;
    }
    $whereclause .= ')';
    
    if (isset($CFG->local_reminders_filterevents)) {
        if ($CFG->local_reminders_filterevents == REMINDERS_SEND_ONLY_VISIBLE) {
            $whereclause .= 'AND visible = 1';
        }
    }

    mtrace("   [Local Reminder] Time window: ".userdate($timewindowstart)." to ".userdate($timewindowend));
    //mtrace("   [Local Reminder] Time window: ".$timewindowstart." to ".$timewindowend);
    mtrace("   [Local Reminder] Where clause: ".$whereclause);

    $upcomingevents = $DB->get_records_select('event', $whereclause);
    if ($upcomingevents == false) {     // no upcoming events, so let's stop.
        mtrace("   [Local Reminder] No upcoming events. Aborting...");

        add_flag_record_db($timewindowend, 'no_events');
        return;
    }
    
    mtrace("   [Local Reminder] Found ".count($upcomingevents)." upcoming events. Continuing...");
    
    $fromuser = core_user::get_noreply_user();
    if (isset($CFG->local_reminders_sendasname) && !empty($CFG->local_reminders_sendasname)) {
        $fromuser->firstname = $CFG->local_reminders_sendasname;
    }
    if (isset($CFG->local_reminders_sendas) && $CFG->local_reminders_sendas == REMINDERS_SEND_AS_ADMIN) {
        mtrace("  [Local Reminder] Sending all reminders as Admin User...");
        $fromuser = get_admin();
    }
    
    // iterating through each event...
    foreach ($upcomingevents as $event) {
        $event = new calendar_event($event);

        $aheadday = 0;
        $fromcustom = false;

        if (($event->timestart + $event->timeduration) - REMINDERS_1DAYBEFORE_INSECONDS >= $timewindowstart &&
                ($event->timestart + $event->timeduration) - REMINDERS_1DAYBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 1;
        } else if (($event->timestart + $event->timeduration) - REMINDERS_3DAYSBEFORE_INSECONDS >= $timewindowstart &&
                ($event->timestart + $event->timeduration) - REMINDERS_3DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 3;
        } else if (($event->timestart + $event->timeduration) - REMINDERS_7DAYSBEFORE_INSECONDS >= $timewindowstart &&
                ($event->timestart + $event->timeduration) - REMINDERS_7DAYSBEFORE_INSECONDS <= $timewindowend) {
            $aheadday = 7;
        } else {
            // find if custom schedule has been defined by user...
            $tempconfigstr = 'local_reminders_'.$event->eventtype.'custom';
            if (isset($CFG->$tempconfigstr) && !empty($CFG->$tempconfigstr) && $CFG->$tempconfigstr > 0) {
                $customsecs = $CFG->$tempconfigstr;
                if (($event->timestart + $event->timeduration) - $customsecs >= $timewindowstart &&
                    ($event->timestart + $event->timeduration) - $customsecs <= $timewindowend) {
                    $aheadday = $customsecs / (REMINDERS_DAYIN_SECONDS * 1.0);
                    mtrace($aheadday);
                    $fromcustom = true;
                }
            }
        }
        
        if ($aheadday == 0) continue;
        mtrace("   [Local Reminder] Processing event#$event->id [Type: $event->eventtype, inaheadof=$aheadday days]...");

        if (!$fromcustom) {
            $optionstr = 'local_reminders_' . $event->eventtype . 'rdays';
            if (!isset($CFG->$optionstr)) {
                if ($event->modulename) {
                    $optionstr = 'local_reminders_duerdays';
                } else {
                    mtrace("   [Local Reminder] Couldn't find option for event $event->id [type: $event->eventtype]");
                    continue;
                }
            }

            $options = $CFG->$optionstr;

            if (empty($options) || $options == null) {
                mtrace("   [Local Reminder] No configuration for eventtype $event->eventtype " .
                    "[event#$event->id is ignored!]...");
                continue;
            }

            // this reminder will not be set up to send by configurations
            if ($options[$aheaddaysindex[$aheadday]] == '0') {
                mtrace("   [Local Reminder] No reminder is due in ahead of $aheadday for eventtype $event->eventtype " .
                    "[event#$event->id is ignored!]...");
                continue;
            }

        } else {
            mtrace("   [Local Reminder] A reminder can be sent for event#$event->id ".
                    ", detected through custom schedule.");
        }
        
        $reminder = null;
        $eventdata = null;
        $sendusers = array();
        
        mtrace("   [Local Reminder] Finding out users for event#".$event->id."...");
        
        try {
        
            switch ($event->eventtype) {
                case 'site':
                    $reminder = new site_reminder($event, $aheadday);
                    $sendusers = $DB->get_records_sql("SELECT * 
                        FROM {user} 
                        WHERE id > 1 AND deleted=0 AND suspended=0 AND confirmed=1;");
                    $eventdata = $reminder->create_reminder_message_object($fromuser);

                    break;

                case 'user':
                    $user = $DB->get_record('user', array('id' => $event->userid));

                    if (!empty($user)) {
                        $reminder = new user_reminder($event, $user, $aheadday);
                        $eventdata = $reminder->create_reminder_message_object($fromuser);
                        $sendusers[] = $user;
                    } 

                    break;

                case 'course':
                    $course = $DB->get_record('course', array('id' => $event->courseid));
                    $coursesettings = $DB->get_record('local_reminders_course', array('courseid'=>$event->courseid));
                    if (isset($coursesettings->status_course) && $coursesettings->status_course == 0) {
                        mtrace("  [Local Reminder] Reminder sending for course events has been restricted in the course specific configurations.");
                        break;
                    }

                    if (!empty($course)) {
                        $context = context_course::instance($course->id); //get_context_instance(CONTEXT_COURSE, $course->id);
                        $PAGE->set_context($context);
                        $roleusers = get_role_users($courseroleids, $context, true, 'ra.id as ra_id, u.*');
                        $senduserids = array_map(function($u) { return $u->id; }, $roleusers);
                        $sendusers = array_combine($senduserids, $roleusers);

                        // create reminder object...
                        //
                        $reminder = new course_reminder($event, $course, $aheadday);
                        $eventdata = $reminder->create_reminder_message_object($fromuser);
                    }

                    break;

                case 'open':

                    // if we dont want to send reminders for activity openings...
                    //
                    $isQuestionnaireOpenWithClose = ( ( 'questionnaire' == strtolower( $event->modulename ) && $event->timeduration > 0 ) );

                    if (!$isQuestionnaireOpenWithClose  && ( isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_CLOSINGS)) {
                        mtrace("  [Local Reminder] Reminder sending for activity openings has been restricted in the configurations.");
                        break; 
                    }

                case 'close':

                    // if we dont want to send reminders for activity closings...
                    //
                    if (isset($CFG->local_reminders_duesend) && $CFG->local_reminders_duesend == REMINDERS_ACTIVITY_ONLY_OPENINGS) {
                        mtrace("  [Local Reminder] Reminder sending for activity closings has been restricted in the configurations.");
                        break; 
                    }

                case 'due':

                    if (!isemptyString($event->modulename)) {

                        if( ( 'assign' == strtolower( $event->modulename ) ) &&  !$CFG->local_reminders_assignment_enabled ){
                            mtrace("  [Local Reminder] Reminder sending for Assignment closings has been restricted in the configuration.");
                            break;
                        }

                        if( ( 'quiz' == strtolower( $event->modulename ) ) && !$CFG->local_reminders_quiz_enabled ){
                            mtrace("  [Local Reminder] Reminder sending for Quiz closings has been restricted in the configuration.");
                            break;
                        }

                        if( ( 'questionnaire' == strtolower( $event->modulename ) ) && !$CFG->local_reminders_questionnaire_enabled ){
                            mtrace("  [Local Reminder] Reminder sending for Questionnaire closings has been restricted in the configuration.");
                            break;
                        }

                        $courseandcm = get_course_and_cm_from_instance($event->instance, $event->modulename, $event->courseid);
                        $course = $courseandcm[0];
                        $cm = $courseandcm[1];
                        $coursesettings = $DB->get_record('local_reminders_course', array('courseid'=>$event->courseid));
                        if (isset($coursesettings->status_activities) && $coursesettings->status_activities == 0) {
                            mtrace("  [Local Reminder] Reminder sending for activities has been restricted in the course specific configurations.");
                            break;
                        }

                        if( 'questionnaire' == strtolower( $event->modulename ) ){

                            if( !core_tag_tag::is_enabled( 'core', 'course_modules' ) ){
                                mtrace("  [Local Reminder] Reminder sending for Questionnaires has been prevented because tagging is disbaled and must be enabled.");
                                break;
                            }

                            if( !$CFG->local_reminders_questionnaire_tags ){
                                mtrace("  [Local Reminder] Reminder sending for Questionnaires has been prevented because there are no tags configured.");
                                break;
                            }

                            $tagsToSendRemindersForMap = stristr( $CFG->local_reminders_questionnaire_tags, ',' ) ? explode( ',', $CFG->local_reminders_questionnaire_tags ) : [ $CFG->local_reminders_questionnaire_tags ];

                            $moduleSpecificTags = core_tag_tag::get_item_tags_array( 'core', 'course_modules', $cm->id );
                            $tagCountBeforeFilter = count( $moduleSpecificTags );

                            $moduleSpecificTags = array_filter( $moduleSpecificTags, function( $moduleSpecificTag ) use ( $tagsToSendRemindersForMap ){
                                return in_array( $moduleSpecificTag, $tagsToSendRemindersForMap );
                            });

                            $tagCountAfterFilter = count( $moduleSpecificTags );

                            if( !( $tagCountBeforeFilter == $tagCountAfterFilter ) ){
                                mtrace("  [Local Reminder] Reminder sending for Questionnaires has been prevented because there's a tag mismatch.");
                                break;
                            }
                        }

                        if (!empty($course) && !empty($cm)) {
                            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid);
                            $context = context_module::instance($cm->id); //get_context_instance(CONTEXT_MODULE, $cm->id);
                            $PAGE->set_context($context);

                            if ($event->courseid <= 0 && $event->userid > 0) {
                                // a user overridden activity...
                                mtrace("  [Local Reminder] Event #".$event->id." is a user overridden ".$event->modulename." event.");
                                $user = $DB->get_record('user', array('id' => $event->userid));
                                $sendusers[] = $user;
                            } else if ($event->courseid <= 0 && $event->groupid > 0) {
                                // a group overridden activity...
                                mtrace("  [Local Reminder] Event #".$event->id." is a group overridden ".$event->modulename." event.");
                                $group = $DB->get_record('groups', array('id' => $event->groupid));
                                $sendusers = get_users_in_group($group);
                            } else {
                                // 'ra.id field added to avoid printing debug message from get_role_users (has odd behaivior when called with an array for $roleid param'
                                $sendusers = get_role_users($activityroleids, $context, true, 'ra.id, u.*');

                                // filter user list, replacement for deprecated/removed $cm->groupmembersonly & groups_get_grouping_members($cm->groupingid);
                                //   see: https://docs.moodle.org/dev/Availability_API#Display_a_list_of_users_who_may_be_able_to_access_the_current_activity
                                $info = new \core_availability\info_module($cm);
                                $sendusers = $info->filter_user_list($sendusers);
                            }

                            $reminder = new due_reminder($event, $course, $context, $aheadday);

                            if( 'assign' == strtolower( $event->modulename ) ){
                                $assign = new assign($context, $cm, $course);
                                $usersWithSubmissions = get_assignment_submissions( $assign, array_column( $sendusers, 'id' ) );

                                if( $usersWithSubmissions && !empty( $usersWithSubmissions ) ){

                                    $userIdsWithSubmissions = array_column( $usersWithSubmissions, 'userid' );

                                    $sendusers = array_filter( $sendusers, function( $sendToUser ) use ( $userIdsWithSubmissions ){
                                        return !in_array( $sendToUser->id, $userIdsWithSubmissions );
                                    });
                                }

                                $reminder = new due_reminder_assignment( $event, $course, $context, $aheadday );
                            }

                            if( 'questionnaire' == strtolower( $event->modulename ) ){
                                $nonRespondents = questionnaire_get_incomplete_users( $cm, 0 );

                                if( $nonRespondents && !empty( $nonRespondents ) ){
                                    $sendusers = array_filter( $sendusers, function( $sendToUser ) use ( $nonRespondents ){
                                        return in_array( $sendToUser->id, $nonRespondents );
                                    });
                                }

                                $reminder = new due_reminder_questionnaire( $event, $course, $context, $aheadday );
                            }

                            if( 'quiz' == strtolower( $event->modulename ) ){
                                $quiz = $DB->get_record( 'quiz', array( 'id' => $cm->instance ) );
                                $quizReport = new reminders_quiz_report();
                                $usersWithoutAttempts = $quizReport->getStudentsWithoutAttempts( $quiz, $cm, $course );

                                if( $usersWithoutAttempts && !empty( $usersWithoutAttempts ) ){
                                    $userIdsWithoutAttempts = array_column( $usersWithoutAttempts, 'userid');

                                    $sendusers = array_filter( $sendusers, function( $sendToUser ) use ( $userIdsWithoutAttempts ){
                                        return in_array( $sendToUser->id, $userIdsWithoutAttempts );
                                    });
                                }
                                $reminder = new due_reminder_quiz( $event, $course, $context, $aheadday );
                            }

                            $reminder->set_activity($event->modulename, $activityobj);
                            $eventdata = $reminder->create_reminder_message_object($fromuser);
                        }
                    }

                    break;

                case 'group':
                    $group = $DB->get_record('groups', array('id' => $event->groupid));

                    if (!empty($group)) {
                        $coursesettings = $DB->get_record('local_reminders_course', array('courseid'=>$group->courseid));
                        if (isset($coursesettings->status_group) && $coursesettings->status_group == 0) {
                            mtrace("  [Local Reminder] Reminder sending for group events has been restricted in the course specific configurations.");
                            break;
                        }

                        $reminder = new group_reminder($event, $group, $aheadday);

                        // add module details, if this event is a mod type event
                        //
                        if (!isemptyString($event->modulename) && $event->courseid > 0) {
                            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid);
                            $reminder->set_activity($event->modulename, $activityobj);
                        }
                        $eventdata = $reminder->create_reminder_message_object($fromuser);

                        $sendusers = get_users_in_group($group);
                    }

                    break;

                default:
                     if (!isemptyString($event->modulename)) {
                        $course = $DB->get_record('course', array('id' => $event->courseid));
                        $cm = get_coursemodule_from_instance($event->modulename, $event->instance, $event->courseid);

                        if (!empty($course) && !empty($cm)) {
                            $activityobj = fetch_module_instance($event->modulename, $event->instance, $event->courseid);
                            $context = context_module::instance($cm->id); // get_context_instance(CONTEXT_MODULE, $cm->id);
                            $PAGE->set_context($context);
                            $sendusers = get_role_users($activityroleids, $context, true, 'u.*');
                            
                            //$sendusers = get_enrolled_users($context, '', $event->groupid, 'u.*');
                            $reminder = new due_reminder($event, $course, $context, $aheadday);
                            $reminder->set_activity($event->modulename, $activityobj);
                            $eventdata = $reminder->create_reminder_message_object($fromuser);
                        }
                     } else {
                         mtrace("  [Local Reminder] Unknown event type [$event->eventtype]");
                     }
            }

        } catch (Exception $ex) {
            mtrace("  [Local Reminder - ERROR] Error occured when initializing ".
                    "for event#[$event->id] (type: $event->eventtype) ".$ex->getMessage());
            mtrace("  [Local Reminder - ERROR] ".$ex->getTraceAsString());
            continue;
        }
        
        if ($eventdata == null) {
            mtrace("  [Local Reminder] Event object is not set for the event $event->id [type: $event->eventtype]");
            continue;
        }
        
        $usize = count($sendusers);
        if ($usize == 0) {
            mtrace("  [Local Reminder] No users found to send reminder for the event#$event->id");
            continue;
        }
        
        mtrace("  [Local Reminder] Starting sending reminders for $event->id [type: $event->eventtype]");
        $failedcount = 0;
        
        foreach ($sendusers as $touser) {
            $eventdata = $reminder->set_sendto_user($touser);
            //$eventdata->userto = $touser;
        
            //foreach ($touser as $key => $value) {
            //    mtrace(" User: $key : $value");
            //}
            //$mailresult = 1; //message_send($eventdata);
            //mtrace("-----------------------------------");
            //mtrace($eventdata->fullmessagehtml);
            //mtrace("-----------------------------------");
            try {
                $mailresult = message_send($eventdata);
                mtrace('[LOCAL_REMINDERS] Mail Result: '.$mailresult);

                if (!$mailresult) {
                    throw new coding_exception("Could not send out message for event#$event->id to user $eventdata->userto");
                } 
            } catch (moodle_exception $mex) {
                $failedcount++;
                mtrace('Error: local/reminders/lib.php local_reminders_cron(): '.$mex->getMessage());
            }
        }
        
        if ($failedcount > 0) {
            mtrace("  [Local Reminder] Failed to send $failedcount reminders to users for event#$event->id");
        } else {
            mtrace("  [Local Reminder] All reminders was sent successfully for event#$event->id !");
        }
        
        unset($sendusers);
        
    }
    
    //add_to_log(0, 'local_reminders', 'cron', '', $timewindowend, 0, 0);
    add_flag_record_db($timewindowend, 'sent');
}

function get_assignment_submissions(assign $assignment, $users ) {
    global $CFG, $PAGE, $DB, $USER;

    // The filters do not make sense when there are no submissions, so do not apply them.
    if( !$assignment->is_any_submission_plugin_enabled() )
        return false;

    if (count($users) == 0) {
        // Insert a record that will never match to the sql is still valid.
        $users[] = -1;
    }

    $params = array();
    $params['assignmentid1'] = (int)$assignment->get_instance()->id;
    $params['assignmentid2'] = (int)$assignment->get_instance()->id;
    $params['assignmentid3'] = (int)$assignment->get_instance()->id;
    $params['newstatus'] = ASSIGN_SUBMISSION_STATUS_NEW;


    $fields = 'u.id as userid, ';
    $fields .= 's.status as status, ';
    $fields .= 's.id as submissionid, ';
    $fields .= 's.timecreated as firstsubmission, ';
    $fields .= "CASE WHEN status <> :newstatus THEN s.timemodified ELSE NULL END as timesubmitted, ";
    $fields .= 's.attemptnumber as attemptnumber, ';
    $fields .= 'g.id as gradeid, ';
    $fields .= 'g.grade as grade, ';
    $fields .= 'g.timemodified as timemarked, ';
    $fields .= 'g.timecreated as firstmarked, ';
    $fields .= 'uf.mailed as mailed, ';
    $fields .= 'uf.locked as locked, ';
    $fields .= 'uf.extensionduedate as extensionduedate, ';
    $fields .= 'uf.workflowstate as workflowstate, ';
    $fields .= 'uf.allocatedmarker as allocatedmarker';

    $from = '{user} u
                     LEFT JOIN {assign_submission} s
                            ON u.id = s.userid
                           AND s.assignment = :assignmentid1
                           AND s.latest = 1 ';

    // For group assignments, there can be a grade with no submission.
    $from .= ' LEFT JOIN {assign_grades} g
                        ON g.assignment = :assignmentid2
                       AND u.id = g.userid
                       AND (g.attemptnumber = s.attemptnumber OR s.attemptnumber IS NULL) ';

    $from .= 'LEFT JOIN {assign_user_flags} uf
                     ON u.id = uf.userid
                    AND uf.assignment = :assignmentid3 ';

    $hasoverrides = $assignment->has_overrides();

    if ($hasoverrides) {
        $params['assignmentid5'] = (int)$assignment->get_instance()->id;
        $params['assignmentid6'] = (int)$assignment->get_instance()->id;
        $params['assignmentid7'] = (int)$assignment->get_instance()->id;
        $params['assignmentid8'] = (int)$assignment->get_instance()->id;
        $params['assignmentid9'] = (int)$assignment->get_instance()->id;

        $fields .= ', priority.priority, ';
        $fields .= 'effective.allowsubmissionsfromdate, ';
        $fields .= 'effective.duedate, ';
        $fields .= 'effective.cutoffdate ';

        $from .= ' LEFT JOIN (
           SELECT merged.userid, min(merged.priority) priority FROM (
              ( SELECT u.id as userid, 9999999 AS priority
                  FROM {user} u
              )
              UNION
              ( SELECT uo.userid, 0 AS priority
                  FROM {assign_overrides} uo
                 WHERE uo.assignid = :assignmentid5
              )
              UNION
              ( SELECT gm.userid, go.sortorder AS priority
                  FROM {assign_overrides} go
                  JOIN {groups} g ON g.id = go.groupid
                  JOIN {groups_members} gm ON gm.groupid = g.id
                 WHERE go.assignid = :assignmentid6
              )
            ) merged
            GROUP BY merged.userid
          ) priority ON priority.userid = u.id

        JOIN (
          (SELECT 9999999 AS priority,
                  u.id AS userid,
                  a.allowsubmissionsfromdate,
                  a.duedate,
                  a.cutoffdate
             FROM {user} u
             JOIN {assign} a ON a.id = :assignmentid7
          )
          UNION
          (SELECT 0 AS priority,
                  uo.userid,
                  uo.allowsubmissionsfromdate,
                  uo.duedate,
                  uo.cutoffdate
             FROM {assign_overrides} uo
            WHERE uo.assignid = :assignmentid8
          )
          UNION
          (SELECT go.sortorder AS priority,
                  gm.userid,
                  go.allowsubmissionsfromdate,
                  go.duedate,
                  go.cutoffdate
             FROM {assign_overrides} go
             JOIN {groups} g ON g.id = go.groupid
             JOIN {groups_members} gm ON gm.groupid = g.id
            WHERE go.assignid = :assignmentid9
          )

        ) effective ON effective.priority = priority.priority AND effective.userid = priority.userid ';
    }

    $userparams = array();
    $userindex = 0;

    list($userwhere, $userparams) = $DB->get_in_or_equal($users, SQL_PARAMS_NAMED, 'user');
    $where = 'u.id ' . $userwhere;
    $params = array_merge($params, $userparams);

    $where .= ' AND (s.timemodified IS NOT NULL AND s.status = :submitted) ';
    $params['submitted'] = ASSIGN_SUBMISSION_STATUS_SUBMITTED;

    $sql = "SELECT {$fields} FROM {$from} WHERE {$where}";

    return $DB->get_records_sql( $sql, $params );
}

/**
 * Returns all users belong to the given group.
 *
 * @param $group group object as received from db.
 * @return array users in an array
 */
function get_users_in_group($group) {
    global $DB;

    $sendusers = array();
    $groupmemberroles = groups_get_members_by_role($group->id, $group->courseid, 'u.id');
    if ($groupmemberroles) {
        foreach($groupmemberroles as $roleid => $roledata) {
            foreach($roledata->users as $member) {
                $sendusers[] = $DB->get_record('user', array('id' => $member->id));
            }
        }
    }
    return $sendusers;
}

/**
 * Adds a database record to local_reminders table, to mark
 * that the current cron cycle is over. Then we flag the time
 * of end of the cron time window, so that no reminders sent
 * twice.
 *
 * @param $timewindowend string cron window time end.
 * @param string $crontype type of reminders cron.
 */
function add_flag_record_db($timewindowend, $crontype = '') {
    global $DB;

    $newRecord = new stdClass();
    $newRecord->time = $timewindowend;
    $newRecord->type = $crontype;
    $DB->insert_record("local_reminders", $newRecord);
}

/**
 * Function to retrive module instace from corresponding module
 * table. This function is written because when sending reminders 
 * it can restrict showing some fields in the message which are sensitive
 * to user. (Such as some descriptions are hidden until defined date)
 * Function is very similar to the function in datalib.php/get_coursemodule_from_instance,
 * but by below it returns all fields of the module.
 * 
 * Eg: can get the quiz instace from quiz table, can get the new assignment
 * instace from assign table, etc.
 * 
 * @param string $modulename name of module type, eg. resource, assignment,...
 * @param int $instance module instance number (id in resource, assignment etc. table)
 * @param int $courseid optional course id for extra validation
 * 
 * @return individual module instance (a quiz, a assignment, etc). 
 *          If fails returns null
 */
function fetch_module_instance($modulename, $instance, $courseid=0) {
    global $DB;

    $params = array('instance'=>$instance, 'modulename'=>$modulename);

    $courseselect = "";

    if ($courseid) {
        $courseselect = "AND cm.course = :courseid";
        $params['courseid'] = $courseid;
    }

    $sql = "SELECT m.*
              FROM {course_modules} cm
                   JOIN {modules} md ON md.id = cm.module
                   JOIN {".$modulename."} m ON m.id = cm.instance
             WHERE m.id = :instance AND md.name = :modulename
                   $courseselect";

    try {
        return $DB->get_record_sql($sql, $params, IGNORE_MISSING);
    } catch (moodle_exception $mex) {
        mtrace('  [Local Reminder - ERROR] Failed to fetch module instance! '.$mex.getMessage);
        return null;
    }
}

/**
 * Returns true if input string is empty/whitespaces only, otherwise false.
 * 
 * @param type $str string
 * 
 * @return boolean true if string is empty or whitespace
 */
function isemptyString($str) {
    return !isset($str) || empty($str) || trim($str) === '';
}

function local_reminders_extend_settings_navigation($settingsnav, $context) {
    global $PAGE;
 
    // Only add this settings item on non-site course pages.
    if (!$PAGE->course or $PAGE->course->id == 1) {
        return;
    }
 
    // Only let users with the appropriate capability see this settings item.
    if (!has_capability('moodle/course:update', context_course::instance($PAGE->course->id))) {
        return;
    }
 
    if ($settingnode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE)) {
        $name = get_string('admintreelabel', 'local_reminders');
        $url = new moodle_url('/local/reminders/coursesettings.php', array('courseid' => $PAGE->course->id));
        $navnode = navigation_node::create(
            $name,
            $url,
            navigation_node::NODETYPE_LEAF,
            'reminders',
            'reminders',
            new pix_icon('i/calendar', $name)
        );
        if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
            $navnode->make_active();
        }
        $settingnode->add_node($navnode);
    }
}

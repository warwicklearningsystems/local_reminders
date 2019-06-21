<?php
require_once($CFG->dirroot . '/local/reminders/contents/due_reminder.class.php');

class due_reminder_assignment extends due_reminder {
    public function __construct($event, $course, $cm, $aheaddays = 1) {
        parent::__construct($event, $course, $cm, $aheaddays);
    }
    
    public function get_message_provider() {
        return 'reminders_due_assignment';
    }
}
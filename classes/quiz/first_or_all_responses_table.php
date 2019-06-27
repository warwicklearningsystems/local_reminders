<?php
require_once($CFG->dirroot . '/mod/quiz/report/responses/last_responses_table.php');
require_once($CFG->dirroot . '/mod/quiz/report/responses/first_or_all_responses_table.php');

class reminders_quiz_first_of_all_reponses_table extends quiz_first_or_all_responses_table{
    public function __construct($quiz, $context, $qmsubselect, \quiz_responses_options $options, \core\dml\sql_join $groupstudentsjoins, \core\dml\sql_join $studentsjoins, $questions, $reporturl) {
        parent::__construct($quiz, $context, $qmsubselect, $options, $groupstudentsjoins, $studentsjoins, $questions, $reporturl);
    }
    
    public function queryDb(){
        global $DB;

        // Fetch the attempts
        $sql = "SELECT
                {$this->sql->fields}
                FROM {$this->sql->from}
                WHERE {$this->sql->where}";

        $this->rawdata = $DB->get_records_sql($sql, $this->sql->params);
    }   
}
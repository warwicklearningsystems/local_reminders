<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/default.php');
require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/local/reminders/classes/quiz/first_or_all_responses_table.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/report/responses/responses_options.php');
require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');

class reminders_quiz_report extends quiz_attempts_report {
    
    /**
     * 
     * @param type $quiz
     * @param type $cm
     * @param type $course
     * @return type
     */
    public function getStudentsWithoutAttempts( $quiz, $cm, $course = null ){
        $this->context = context_module::instance( $cm->id );
        list( $currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins ) = $this->get_students_joins( $cm, $course );

        $options = new quiz_responses_options('overview', $quiz, $cm, $course);
        $options->attempts = quiz_attempts_report::ENROLLED_WITHOUT;
        
        $questions = quiz_report_get_significant_questions($quiz);
        
        $table = new reminders_quiz_first_of_all_reponses_table(
            $quiz, $this->context, '', $options, $groupstudentsjoins, $studentsjoins, $questions, $options->get_url()
        );
        
        $table->setup_sql_queries( $allowedjoins );        
        $table->queryDb();
        
        $data = $table->rawdata;
        $table->close_recordset();
        
        return $data;
    }

    /**
     * 
     * @param type $cm
     * @param type $course
     * @param type $quiz
     * @return type
     */
    public function display($cm, $course, $quiz) {
        return parent::display($cm, $course, $quiz);
    }
}
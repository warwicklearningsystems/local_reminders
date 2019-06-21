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
 * Defines message providers for the reminder plugin.
 *
 * @package    local
 * @subpackage reminders
 * @copyright  2012 Isuru Madushanka Weerarathna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = array (
    
    // reminders for site events
    'reminders_site' => array (
    ),
    
    // reminders for user events
    'reminders_user' => array (
    ),
    
    // reminders for course events
    'reminders_course' => array (
    ),
    
    // reminders for group events
    'reminders_group' => array (
    ),
    
    // reminders for due events
    'reminders_due' => array (
    ),

    //reminders for questionnaire due events
    'reminders_due_questionnaire' => array(
    ),

    //reminders for assignment due events
    'reminders_due_assignment' => array(
    ),

    //reminders for quiz due events
    'reminders_due_quiz' => array(
    )
        
);

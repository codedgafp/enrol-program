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
 *  Event observers used in enrol_program.
 *
 * @package    enrol_program
 * @copyright  2024 Edunao SAS (contact@edunao.com)
 * @author     Florent Drousset <florent.drousset@edunao.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/lib/classes/user.php');
require_once($CFG->dirroot . '/lib/classes/event/base.php');
require_once($CFG->dirroot . '/enrol/program/classes/database_interface.php');
require_once($CFG->dirroot . '/enrol/program/lib.php');

/**
 * Enrol_program observer.
 */
class enrol_program_observer {
    /**
     * Add member to program observer.
     *
     * @param \core\event\user_enrolment_created $event
     * @return bool
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function add_program_member(\core\event\user_enrolment_created $event) {
        // Get instances and enrol user for each one.
        if (!$instances = self::get_enrol_program_instance($event)) {
            return false;
        }

        $user = \core_user::get_user($event->relateduserid);
        $enrolplugin = enrol_get_plugin('program');

        foreach ($instances as $instance) {
            if ($enrolplugin) {
                $enrolplugin->enrol_user($instance, $event->relateduserid);
            }

            if (enrol_program_use_hosts()) {
                enrol_program_add_program_member_to_host($instance, $user->email);
            }
        }

        return true;
    }

    /**
     * Delete member to program observer.
     *
     * @param \core\event\user_enrolment_deleted $event
     * @return bool
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function delete_program_member(\core\event\user_enrolment_deleted $event) {
        // Get instances and unenrol user for each one.
        if (!$instances = self::get_enrol_program_instance($event)) {
            return false;
        }

        $user = \core_user::get_user($event->relateduserid);

        $enrolplugin = enrol_get_plugin('program');
        if (!$enrolplugin) {
            return true;
        }

        foreach ($instances as $instance) {
            $enrolplugin->unenrol_user($instance, $event->relateduserid);

            if (enrol_program_use_hosts()) {
                enrol_program_delete_program_member_to_host($instance, $user->email);
            }
        }

        return true;
    }

    /**
     * Assigned role to program observer.
     *
     * @param \core\event\role_assigned $event
     * @return bool
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function assigned_role_program_member(\core\event\role_assigned $event) {
        // Get instances and add role to user for each one.
        if (!$instances = self::get_enrol_program_instance($event)) {
            return false;
        }

        $dbi = \enrol_program\database_interface::get_instance();
        $editingteacher = $dbi->get_role_by_shortname('editingteacher');
        $teacher = $dbi->get_role_by_shortname('teacher');

        $roleid = $event->objectid != $editingteacher->id ?
            $event->objectid :
            $teacher->id;
        $user = \core_user::get_user($event->relateduserid);

        foreach ($instances as $instance) {
            $courseid = $instance->courseid;
            $context = \context_course::instance($courseid);
            role_assign($roleid, $event->relateduserid, $context->id);

            if (enrol_program_use_hosts()) {
                enrol_program_add_program_member_to_host($instance, $user->email, $roleid);
            }
        }

        return true;
    }

    /**
     * Check if event is good for return enrol program list.
     *
     * @param \core\event\base $event
     * @return array|false
     * @throws \dml_exception
     */
    private static function get_enrol_program_instance(\core\event\base $event): bool|array {
        // Get user.
        $user = \core_user::get_user($event->relateduserid);
        if (!$user) {
            return false;
        }

        if (!isset($event->courseid)) {
            return false;
        }

        $dbi = \enrol_program\database_interface::get_instance();
        $courseid = $event->courseid;

        // Get instances and unenrol user for each one.
        return $dbi->get_enrol_program_link_to_course($courseid);
    }

}

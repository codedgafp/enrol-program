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

namespace enrol_program;

/**
 * Database interface for enrol_program.
 *
 * @package    enrol_program
 * @copyright  2024 Edunao SAS (contact@edunao.com)
 * @author     Florent Drousset <florent.drousset@edunao.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class database_interface {

    /**
     * @var \moodle_database
     */
    protected \moodle_database $db;

    /**
     * @var self
     */
    protected static $instance;

    /**
     * Constructor
     */
    public function __construct() {
        global $DB;

        $this->db = $DB;
    }

    /**
     * Create a singleton
     *
     * @return \enrol_program\database_interface
     */
    public static function get_instance(): \enrol_program\database_interface {

        if (!(self::$instance instanceof self)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Insert enrol instance.
     *
     * @param \stdClass $instance
     * @return bool|int
     * @throws \dml_exception
     */
    public function insert_enrol_instance(\stdClass $instance): bool|int {
        return $this->db->insert_record('enrol', $instance);
    }

    /**
     * Update enrol instance.
     *
     * @param \stdClass $instance
     * @return bool
     * @throws \dml_exception
     */
    public function update_enrol_instance(\stdClass $instance): bool {
        return $this->db->update_record('enrol', $instance);
    }

    /**
     * Get users enrolment.
     *
     * @param int $courseid
     * @return array
     * @throws \dml_exception
     */
    public function get_program_enrolments(int $courseid): array {
        $sql = "SELECT ue.userid
                FROM {user_enrolments} ue
                JOIN {enrol} e ON ue.enrolid = e.id
                WHERE e.courseid = ?";
        return $this->db->get_records_sql($sql, [$courseid]);
    }

    /**
     * Check if user is enrolled to instance.
     *
     * @param int $userid
     * @param int $enrolid
     * @return bool
     * @throws \dml_exception
     */
    public function user_is_enrolled(int $userid, int $enrolid): bool {
        return $this->db->record_exists('user_enrolments', ['userid' => $userid, 'enrolid' => $enrolid]);
    }

    /**
     * Get role by id.
     *
     * @param int $roleid
     * @return false|\stdClass
     * @throws \dml_exception
     */
    public function get_role_by_id(int $roleid): false|\stdClass {
        return $this->db->get_record('role', ['id' => $roleid]);
    }

    /**
     * Get role by shortname
     *
     * @param string $shortname
     * @return false|\stdClass
     * @throws \dml_exception
     */
    public function get_role_by_shortname(string $shortname): bool|\stdClass {
        return $this->db->get_record('role', ['shortname' => $shortname]);
    }

    /**
     * Get course by id.
     *
     * @param int $courseid
     * @return false|\stdClass
     * @throws \dml_exception
     */
    public function get_course_by_id(int $courseid): false|\stdClass {
        return $this->db->get_record('course', ['id' => $courseid]);
    }

    /**
     * Check if course has an enrol instance program.
     *
     * @return bool
     * @var int $courseid
     */
    public function check_if_course_has_enrol_program(int $courseid): bool {
        return $this->db->record_exists('enrol', ['courseid' => $courseid, 'enrol' => 'program']);
    }

    /**
     * Get enrol program list link to course.
     *
     * @param int $courseid
     * @param null|string $hostname
     * @return array
     * @throws \dml_exception
     */
    public function get_enrol_program_link_to_course(int $courseid, ?string $hostname = null) {
        return $this->db->get_records('enrol', [
            'enrol' => 'program',
            'customint1' => $courseid,
            'customchar1' => $hostname,
        ]);
    }

    /**
     * Get enrol program instance data if exist.
     *
     * @param int $courseid
     * @param null|string $hostname
     * @return false|\stdClass
     * @throws \dml_exception
     */
    public function get_enrol_program_instance(int $courseid, int $courseidlink, ?string $hostname = null): bool|\stdClass {
        return $this->db->get_record('enrol', [
            'enrol' => 'program',
            'courseid' => $courseid,
            'customint1' => $courseidlink,
            'customchar1' => $hostname,
        ]);
    }

    /**
     * Get all program enrol instance for this course by user.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     * @throws \dml_exception
     */
    public function get_course_enrol_instance_by_user_id(int $courseid, int $userid): array {
        return $this->db->get_records_sql('
            SELECT e.*
            FROM {enrol} e
            JOIN {course} c ON c.id = e.courseid
            JOIN {user_enrolments} ue ON ue.enrolid = e.id
            WHERE e.enrol = \'program\' AND
                c.id = :courseid AND
                ue.userid = :userid
        ', ['courseid' => $courseid, 'userid' => $userid]);
    }

    /**
     * Get user roles to course link
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass[]
     * @throws \dml_exception
     */
    public function get_user_roles_to_link_course(int $courseid, int $userid): array {
        $context = \context_course::instance($courseid);
        return $this->db->get_records_sql(
            'SELECT ra.*
            FROM {role_assignments} ra
            WHERE ra.contextid = :contextid AND
                ra.userid = :userid AND
                ra.component <> \'enrol_program\'',
            [
                'contextid' => $context->id,
                'userid' => $userid,
            ]
        );
    }
}

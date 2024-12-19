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
 * Program enrolment plugin.
 *
 * @package    enrol_program
 * @copyright  2024 Edunao SAS (contact@edunao.com)
 * @author     Florent Drousset <florent.drousset@edunao.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/enrol/program/classes/database_interface.php');

/**
 * Program enrolment plugin.
 *
 * @package    enrol_program
 * @copyright  2024 Edunao SAS (contact@edunao.com)
 * @author     Florent Drousset <florent.drousset@edunao.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_program_plugin extends \enrol_plugin {
    /**
     * @var \enrol_program\database_interface
     */
    protected \enrol_program\database_interface $dbi;

    /**
     * Construct.
     * Gets a singleton of database_interface.
     */
    public function __construct() {
        $this->dbi = \enrol_program\database_interface::get_instance();
    }

    /**
     * Check delete enrol instance capability.
     *
     * @param stdClass $instance
     * @return bool
     * @throws \coding_exception
     */
    public function can_delete_instance($instance): bool {
        $context = \context_course::instance($instance->courseid);
        return has_capability('enrol/program:config', $context);
    }

    /**
     * Returns localised name of enrol instance.
     *
     * @param \stdClass $instance (null is accepted too)
     * @return string
     * @throws \coding_exception
     */
    public function get_instance_name($instance): string {
        if (empty($instance)) {
            return get_string('pluginname', 'enrol_program');
        }

        $name = get_string('pluginname', 'enrol_program') . ' (' . $instance->customint1 . ')';

        if (!is_null($instance->customchar1)) {
            $name .= ' (' . $instance->customchar1 . ')';
        }

        return $name;
    }

    /**
     * Given a courseid this function returns true if the user is able to enrol or configure programs.
     * AND there are programs that the user can view.
     *
     * @param int $courseid
     * @return bool
     * @throws \coding_exception
     */
    public function can_add_instance($courseid): bool {
        $coursecontext = \context_course::instance($courseid);

        return has_capability('moodle/course:enrolconfig', $coursecontext) &&
               has_capability('enrol/program:config', $coursecontext);
    }

    /**
     * Add new instance of enrol plugin.
     *
     * @param object $course
     * @param ?array $fields instance fields
     * @return int id of new instance, null if can not be created
     * @throws \dml_exception
     */
    public function add_instance($course, ?array $fields = null): int {
        $instance = new \stdClass();
        $instance->courseid = $course->id;
        $instance->enrol = 'program';
        $instance->status = ENROL_INSTANCE_ENABLED;
        $instance->customint1 = $fields['customint1']; // Id of the "parent" course (the program).

        // Host link.
        if (isset($fields['customchar1'])) {
            $instance->customchar1 = $fields['customchar1'];
        }

        $instance->timecreated = time();
        $instance->timemodified = $instance->timecreated;
        $instance->id = $this->dbi->insert_enrol_instance($instance);

        $formateur = $this->dbi->get_role_by_shortname('editingteacher');
        $formateurnonediteur = $this->dbi->get_role_by_shortname('teacher');

        if (!empty($instance->customint1) && $instance->customint1 != $course->id && !isset($instance->customchar1)) {
            // Get all users enrolled in "parent" (program) course.
            $programenrolments = $this->dbi->get_program_enrolments($instance->customint1);

            // For each user enrolled in program course, enrol them in the "sub" course.
            foreach ($programenrolments as $enrolment) {
                // Check if user is enrolled.
                if ($this->dbi->user_is_enrolled($enrolment->userid, $instance->id)) {
                    continue;
                }

                // Add all role to program enrol.
                $userroles = $this->dbi->get_user_roles_to_link_course($instance->customint1, $enrolment->userid);
                $user = \core_user::get_user($enrolment->userid);
                foreach ($userroles as $userrole) {
                    $roleid = $userrole->roleid != $formateur->id ?
                        $userrole->roleid :
                        $formateurnonediteur->id;

                    $this->enrol_user($instance, $enrolment->userid, $roleid);

                    if (enrol_program_use_hosts()) {
                        enrol_program_add_program_member_to_host($instance, $user->email, $roleid);
                    }
                }
            }
        }

        return $instance->id;
    }

    /**
     * Update instance of enrol plugin.
     *
     * @param \stdClass $instance
     * @param \stdClass $data modified instance fields
     * @return boolean
     */
    public function update_instance($instance, $data): bool {
        $instance->status = $data->status;
        $instance->customint1 = $data->customint1; // ID of the "parent" (program) course.
        $instance->timemodified = time();

        return $this->dbi->update_enrol_instance($instance);
    }

    /**
     *  Does this plugin allow manual unenrolment of a specific user?
     *  All plugins allowing this must implement 'enrol/xxx:unenrol' capability
     *
     *  This is useful especially for synchronisation plugins that
     *  do suspend instead of full unenrolment.
     *
     * @param \stdClass $instance course enrol instance
     * @param \stdClass $ue record from user_enrolments table
     *
     * @return bool - true means user with 'enrol/xxx:unenrol' may unenrol this user, false means
     * nobody may touch this user enrolment.
     */
    public function allow_unenrol_user(\stdClass $instance, \stdClass $ue): bool {
        return true;
    }

    /**
     * Check show enrol instance capability.
     *
     * @param \stdClass $instance
     * @return bool
     * @throws \coding_exception
     */
    public function can_hide_show_instance($instance): bool {
        $context = \context_course::instance($instance->courseid);
        return has_capability('enrol/program:config', $context);
    }

    /**
     * Return an array of valid options for the status.
     *
     * @return array
     */
    protected function get_status_options(): array {
        $options = [
            ENROL_INSTANCE_ENABLED => get_string('yes'),
            ENROL_INSTANCE_DISABLED => get_string('no'),
        ];
        return $options;
    }

    /**
     * Use standard UI.
     *
     * @return boolean
     */
    public function use_standard_editing_ui(): bool {
        return true;
    }

    /**
     * Add elements to the edit instance form.
     *
     * @param \stdClass $instance
     * @param \MoodleQuickForm $mform
     * @param \context $coursecontext
     * @return bool
     */
    public function edit_instance_form($instance, \MoodleQuickForm $mform, $coursecontext) {
        $options = $this->get_status_options();
        $mform->addElement('select', 'status', get_string('status', 'enrol_program'), $options);

        // Field of the "parent" (program) course id.
        $mform->addElement('text', 'customint1', get_string('parentcourseid', 'enrol_program'));
        $mform->setType('customint1', PARAM_INT);
        $mform->addRule('customint1', get_string('required'), 'required', null, 'client');

        if ($instance->id) {
            $mform->setDefault('customint1', $instance->customint1);
        }
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname" => value) of submitted data
     * @param array $files array of uploaded files "element_name" => tmp_file_path
     * @param object $instance The instance loaded from the DB
     * @param \context $context The context of the instance we are editing
     * @return array of "element_name" => "error_description" if there are errors,
     *         or an empty array if everything is OK.
     * @return void
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = [];

        if (empty($data['customint1'])) {
            $errors['customint1'] = get_string('required');
        } else if (!is_numeric($data['customint1'])) {
            $errors['customint1'] = get_string('invalidcourseid', 'error');
        }

        return $errors;
    }

    /**
     * Delete course enrol plugin instance, unenrol all users.
     *
     * @param object $instance
     * @return void
     */
    public function delete_instance($instance) {
        parent::delete_instance($instance);

        if (enrol_program_use_hosts()) {
            enrol_program_delete_program_to_host($instance);
        }
    }
}

/**
 * Add member to enrol program request to host.
 *
 * @param \stdClass $instance
 * @param string $useremail
 * @param null|int $roleid
 * @return void
 * @throws \dml_exception
 * @package enrol_program
 */
function enrol_program_add_program_member_to_host(\stdClass $instance, string $useremail, ?int $roleid = null): void {
    global $CFG;
    require_once($CFG->dirroot . '/local/magistere_events_client/classes/events_processing.php');
    require_once($CFG->dirroot . '/course/format/link/lib.php');

    // Get course link data.
    if (!$course = format_link_get_course_by_id($instance->courseid)) {
        return;
    }

    // Set request data.
    $eventdata = [
        'courseid' => $instance->customint1,
        'courseidlink' => $course->courseidlink,
        'platform' => $course->platform,
        'email' => $useremail,
    ];

    // Add role if exist.
    if (!is_null($roleid)) {
        $dbi = \enrol_program\database_interface::get_instance();
        $role = $dbi->get_role_by_id($roleid);
        $eventdata['rolename'] = $role->shortname;
    }

    // Instantiate an events processing object.
    $eventprocessor = new \local_magistere_events_client\events_processing();
    $eventprocessor->set_event(
        \local_magistere_events_client_observer::EVENT_TYPE_ADD_PROGRAM_MEMBER,
        $eventdata
    );
}

/**
 * Delete member to enrol program request to host.
 *
 * @param \stdClass $instance
 * @param string $useremail
 * @return void
 * @throws \dml_exception
 * @package enrol_program
 */
function enrol_program_delete_program_member_to_host(\stdClass $instance, string $useremail): void {
    global $CFG;
    require_once($CFG->dirroot . '/local/magistere_events_client/classes/events_processing.php');
    require_once($CFG->dirroot . '/course/format/link/lib.php');

    // Get course link data.
    if (!$course = format_link_get_course_by_id($instance->courseid)) {
        return;
    }

    // Set request data.
    $eventdata = [
        'courseid' => $instance->customint1,
        'courseidlink' => $course->courseidlink,
        'platform' => $course->platform,
        'email' => $useremail,
    ];

    // Instantiate an events processing object.
    $eventprocessor = new \local_magistere_events_client\events_processing();
    $eventprocessor->set_event(
        \local_magistere_events_client_observer::EVENT_TYPE_DELETE_PROGRAM_MEMBER,
        $eventdata
    );
}

/**
 * Delete enrol program request to host.
 *
 * @param \stdClass $instance
 * @return void
 * @throws dml_exception
 * @package enrol_program
 */
function enrol_program_delete_program_to_host(\stdClass $instance): void {
    global $CFG;
    require_once($CFG->dirroot . '/local/magistere_events_client/classes/events_processing.php');
    require_once($CFG->dirroot . '/course/format/link/lib.php');

    // Get course link data.
    if (!$course = format_link_get_course_by_id($instance->courseid)) {
        return;
    }

    // Set request data.
    $eventdata = [
        'courseid' => $instance->customint1,
        'courseidlink' => $course->courseidlink,
        'platform' => $course->platform,
    ];

    // Instantiate an events processing object.
    $eventprocessor = new \local_magistere_events_client\events_processing();
    $eventprocessor->set_event(
        \local_magistere_events_client_observer::EVENT_TYPE_DELETE_PROGRAM,
        $eventdata
    );
}

/**
 * Check if the plateform uses hosts system
 *
 * @return bool
 */
function enrol_program_use_hosts() {
    global $CFG;
    $file = $CFG->dirroot . '/local/magistere_common/api/host.php';
    return file_exists($file);
}

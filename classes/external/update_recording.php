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

namespace mod_bigbluebuttonbn\external;

use coding_exception;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\local\bigbluebutton\recordings\recording;
use mod_bigbluebuttonbn\local\bigbluebutton\recordings\recording_action;
use mod_bigbluebuttonbn\local\bigbluebutton\recordings\recording_helper;

/**
 * External service to update the details of one recording.
 *
 * @package   mod_bigbluebuttonbn
 * @category  external
 * @copyright 2018 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_recording extends external_api {
    /**
     * Updates a recording
     *
     * @param int $bigbluebuttonbnid the bigbluebuttonbn instance id, either the same as the one set in the recording or a new
     * instance to import the recording into.
     * @param int $recordingid
     * @param string $action
     * @param string|null $additionaloptions
     * @return array (empty array for now)
     * @throws coding_exception
     */
    public static function execute(
        int $bigbluebuttonbnid,
        int $recordingid,
        string $action,
        string $additionaloptions = null
    ): array {
        // Validate the bigbluebuttonbnid ID.
        [
            'bigbluebuttonbnid' => $bigbluebuttonbnid,
            'recordingid' => $recordingid,
            'action' => $action,
            'additionaloptions' => $additionaloptions,
        ] = self::validate_parameters(self::execute_parameters(), [
            'bigbluebuttonbnid' => $bigbluebuttonbnid,
            'recordingid' => $recordingid,
            'action' => $action,
            'additionaloptions' => $additionaloptions,
        ]);

        switch ($action) {
            case 'delete':
            case 'edit':
            case 'protect':
            case 'publish':
            case 'unprotect':
            case 'unpublish':
            case 'import':
                break;
            default:
                throw new coding_exception("Unknown action '{$action}'");
        }

        // Fetch the session, features, and profile.
        $recording = new recording($recordingid);

        // Check both the recording instance context and the bbb context.
        $instance = instance::get_from_instanceid($recording->get('bigbluebuttonbnid'));
        $recordingcontext = $instance->get_context();
        // Validate that the user has access to this activity and to manage recordings.
        self::validate_context($recordingcontext);
        require_capability('mod/bigbluebuttonbn:managerecordings', $recordingcontext);

        if ($bigbluebuttonbnid) {
            $instance = instance::get_from_instanceid($bigbluebuttonbnid);
            $recordingcontext = $instance->get_context();
            self::validate_context($recordingcontext);
            require_capability('mod/bigbluebuttonbn:managerecordings', $recordingcontext);
        }
        // Specific action such as import, delete, publish, unpublish, edit,....
        if (method_exists(recording_action::class, "$action")) {
            forward_static_call(
                array('\mod_bigbluebuttonbn\local\bigbluebutton\recordings\recording_action',
                    "$action"),
                $recording,
                $instance
            );
        }
        return [];
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'bigbluebuttonbnid' => new external_value(PARAM_INT, 'bigbluebuttonbn instance id, this might be a different one
            from the one set in recordingid in case of importing', VALUE_OPTIONAL),
            'recordingid' => new external_value(PARAM_INT, 'The moodle internal recording ID'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'The action to perform'),
            'additionaloptions' => new external_value(PARAM_RAW, 'Additional options', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Describe the return structure of the external service.
     *
     * @return external_single_structure
     * @since Moodle 3.0
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([]);
    }
}

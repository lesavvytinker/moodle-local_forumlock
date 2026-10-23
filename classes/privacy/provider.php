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

namespace local_forumlock\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;


/**
 * Privacy provider for local_forumlock.
 *
 * Only personal data stored is "who scheduled this lock and when" - the
 * discussion content itself belongs to mod_forum, not this plugin.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes the personal data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_forumlock_schedule', [
            'discussionid' => 'privacy:metadata:local_forumlock_schedule:discussionid',
            'createdby'    => 'privacy:metadata:local_forumlock_schedule:createdby',
            'openat'       => 'privacy:metadata:local_forumlock_schedule:openat',
            'lockat'       => 'privacy:metadata:local_forumlock_schedule:lockat',
            'timecreated'  => 'privacy:metadata:local_forumlock_schedule:timecreated',
            'timemodified' => 'privacy:metadata:local_forumlock_schedule:timemodified',
        ], 'privacy:metadata:local_forumlock_schedule');

        return $collection;
    }

    /**
     * Finds the contexts holding data created by this user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {local_forumlock_schedule} s
                  JOIN {forum} f ON f.id = s.forumid
                  JOIN {course_modules} cm ON cm.instance = f.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE s.createdby = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'userid'       => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Finds the users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT s.createdby
                  FROM {local_forumlock_schedule} s
                  JOIN {forum} f ON f.id = s.forumid
                  JOIN {course_modules} cm ON cm.instance = f.id AND cm.id = :cmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
                 WHERE s.createdby <> 0";
        $userlist->add_from_sql('createdby', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Exports the user's data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('forum', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $rows = $DB->get_records(
                'local_forumlock_schedule',
                ['forumid' => $cm->instance, 'createdby' => $contextlist->get_user()->id]
            );
            if ($rows) {
                $schedules = [];
                foreach ($rows as $row) {
                    $schedules[] = (object) [
                        'discussionid' => $row->discussionid,
                        'status'       => $row->status,
                        'openat'       => $row->openat ? transform::datetime($row->openat) : null,
                        'lockat'       => transform::datetime($row->lockat),
                        'timecreated'  => transform::datetime($row->timecreated),
                    ];
                }
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_forumlock')],
                    (object) ['schedules' => $schedules]
                );
            }
        }
    }

    /**
     * Deletes all user data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('forum', $context->instanceid);
        if ($cm) {
            $DB->delete_records('local_forumlock_schedule', ['forumid' => $cm->instance]);
        }
    }

    /**
     * Erases one user's data for the approved contexts.
     *
     * The schedule itself (what locks when) isn't personal data and other
     * users rely on it - deleting the row would silently cancel a lock or
     * leave an open-gated discussion closed for good. So the user is
     * anonymised out of the row (createdby set to 0) rather than the row
     * being deleted.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('forum', $context->instanceid);
            if ($cm) {
                $DB->set_field('local_forumlock_schedule', 'createdby', 0, [
                    'forumid'   => $cm->instance,
                    'createdby' => $contextlist->get_user()->id,
                ]);
            }
        }
    }

    /**
     * Erases several users' data in a context (anonymised, as above).
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('forum', $context->instanceid);
        if (!$cm) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            $DB->set_field('local_forumlock_schedule', 'createdby', 0, ['forumid' => $cm->instance, 'createdby' => $userid]);
        }
    }
}

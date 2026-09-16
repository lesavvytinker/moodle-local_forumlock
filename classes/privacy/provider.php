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
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

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

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_forumlock_schedule', [
            'discussionid' => 'privacy:metadata:local_forumlock_schedule:discussionid',
            'createdby'    => 'privacy:metadata:local_forumlock_schedule:createdby',
            'lockat'       => 'privacy:metadata:local_forumlock_schedule:lockat',
            'timecreated'  => 'privacy:metadata:local_forumlock_schedule:timecreated',
        ], 'privacy:metadata:local_forumlock_schedule');

        return $collection;
    }

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

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT s.createdby
                  FROM {local_forumlock_schedule} s
                  JOIN {forum} f ON f.id = s.forumid
                  JOIN {course_modules} cm ON cm.instance = f.id AND cm.id = :cmid";
        $userlist->add_from_sql('createdby', $sql, ['cmid' => $context->instanceid]);
    }

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
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_forumlock')],
                    (object) ['schedules' => array_values($rows)]
                );
            }
        }
    }

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

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('forum', $context->instanceid);
            if ($cm) {
                $DB->delete_records('local_forumlock_schedule', [
                    'forumid'   => $cm->instance,
                    'createdby' => $contextlist->get_user()->id,
                ]);
            }
        }
    }

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
            $DB->delete_records('local_forumlock_schedule', ['forumid' => $cm->instance, 'createdby' => $userid]);
        }
    }
}

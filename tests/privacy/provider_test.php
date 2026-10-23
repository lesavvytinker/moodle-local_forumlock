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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider tests for local_forumlock.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumlock\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Creates a course, a forum, two users and one schedule row each.
     *
     * @return \stdClass
     */
    private function create_fixture(): \stdClass {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $rowids = [];
        foreach ([[1, $user1], [2, $user2]] as [$discussionid, $user]) {
            $rowids[$user->id] = $DB->insert_record('local_forumlock_schedule', (object) [
                'discussionid' => $discussionid,
                'forumid'      => $forum->id,
                'courseid'     => $course->id,
                'openat'       => 0,
                'lockat'       => time() + DAYSECS,
                'createdby'    => $user->id,
                'timecreated'  => time(),
                'timemodified' => time(),
                'status'       => 'pending_lock',
            ]);
        }

        return (object) [
            'user1'   => $user1,
            'user2'   => $user2,
            'context' => \context_module::instance($forum->cmid),
            'rowids'  => $rowids,
        ];
    }

    /**
     * Get contexts for userid finds the forum.
     */
    public function test_get_contexts_for_userid_finds_the_forum(): void {
        $fixture = $this->create_fixture();

        $contextlist = provider::get_contexts_for_userid($fixture->user1->id);
        $this->assertSame([(int) $fixture->context->id], array_map('intval', $contextlist->get_contextids()));

        $nodata = $this->getDataGenerator()->create_user();
        $this->assertEmpty(provider::get_contexts_for_userid($nodata->id)->get_contextids());
    }

    /**
     * Get users in context lists schedule creators.
     */
    public function test_get_users_in_context_lists_schedule_creators(): void {
        $fixture = $this->create_fixture();

        $userlist = new userlist($fixture->context, 'local_forumlock');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing(
            [(int) $fixture->user1->id, (int) $fixture->user2->id],
            array_map('intval', $userlist->get_userids())
        );
    }

    /**
     * Export user data writes something for the user.
     */
    public function test_export_user_data_writes_something_for_the_user(): void {
        $fixture = $this->create_fixture();

        $contextlist = new approved_contextlist($fixture->user1, 'local_forumlock', [$fixture->context->id]);
        provider::export_user_data($contextlist);

        $this->assertTrue(writer::with_context($fixture->context)->has_any_data());
    }

    /**
     * Delete data for user anonymises without removing the schedule.
     */
    public function test_delete_data_for_user_anonymises_without_removing_the_schedule(): void {
        global $DB;
        $fixture = $this->create_fixture();

        $contextlist = new approved_contextlist($fixture->user1, 'local_forumlock', [$fixture->context->id]);
        provider::delete_data_for_user($contextlist);

        // The schedule still exists, so the lock still happens, but nobody owns it any more.
        $row1 = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->rowids[$fixture->user1->id]]);
        $this->assertNotFalse($row1);
        $this->assertEquals(0, $row1->createdby);

        // The other user's row is untouched.
        $row2 = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->rowids[$fixture->user2->id]]);
        $this->assertEquals($fixture->user2->id, $row2->createdby);
    }

    /**
     * Delete data for users anonymises only the listed users.
     */
    public function test_delete_data_for_users_anonymises_only_the_listed_users(): void {
        global $DB;
        $fixture = $this->create_fixture();

        $userlist = new approved_userlist($fixture->context, 'local_forumlock', [$fixture->user2->id]);
        provider::delete_data_for_users($userlist);

        $row1 = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->rowids[$fixture->user1->id]]);
        $row2 = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->rowids[$fixture->user2->id]]);
        $this->assertEquals($fixture->user1->id, $row1->createdby);
        $this->assertEquals(0, $row2->createdby);
    }

    /**
     * Delete data for all users in context removes the schedules.
     */
    public function test_delete_data_for_all_users_in_context_removes_the_schedules(): void {
        global $DB;
        $fixture = $this->create_fixture();

        provider::delete_data_for_all_users_in_context($fixture->context);

        $this->assertSame(0, $DB->count_records('local_forumlock_schedule'));
    }
}

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

namespace local_forumlock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * Unit tests for local_forumlock\observer::store_schedule().
 *
 * Deliberately calls store_schedule() directly with explicit $lockat/$openat
 * arguments rather than going through discussion_created()/post_updated(),
 * which read optional_param() off the real request - see the refactor note
 * in classes/observer.php.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumlock\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Creates a course, a forum, a user and one discussion to schedule
     * against. Returns them as a plain object for convenience.
     *
     * @return \stdClass
     */
    private function create_fixture(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $forum = $forumgenerator->create_instance(['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();

        $discussion = $forumgenerator->create_discussion([
            'course'  => $course->id,
            'forum'   => $forum->id,
            'userid'  => $user->id,
        ]);

        return (object) [
            'course'     => $course,
            'forum'      => $forum,
            'user'       => $user,
            'discussion' => $discussion,
        ];
    }

    /**
     * Creates a pending lock schedule for a future lockat with no open gate.
     */
    public function test_creates_a_pending_lock_schedule_for_a_future_lockat_with_no_open_gate(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();
        $lockat = time() + 3600;

        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            $lockat
        );

        $row = $DB->get_record('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertNotFalse($row);
        $this->assertSame('pending_lock', $row->status);
        $this->assertEquals($lockat, $row->lockat);
        $this->assertEquals(0, $row->openat);
        $this->assertEquals($fixture->user->id, $row->createdby);

        // No open-gating requested, so the discussion must remain open.
        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertEquals(0, $discussion->timelocked);
    }

    /**
     * Ignores a lockat in the past with no existing schedule.
     */
    public function test_ignores_a_lockat_in_the_past_with_no_existing_schedule(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();

        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            time() - 100
        );

        $count = $DB->count_records('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertSame(0, $count);
    }

    /**
     * Updates an existing pending schedule rather than duplicating.
     */
    public function test_updates_an_existing_pending_schedule_rather_than_duplicating(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();
        $first = time() + 3600;
        $second = time() + 7200;

        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            $first
        );
        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            $second
        );

        $rows = $DB->get_records('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals($second, $row->lockat);
    }

    /**
     * Clearing the lockat cancels an existing pending schedule.
     */
    public function test_clearing_the_lockat_cancels_an_existing_pending_schedule(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();

        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            time() + 3600
        );
        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            0
        );

        $row = $DB->get_record('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertNotFalse($row);
        $this->assertSame('cancelled', $row->status);
    }

    /**
     * Does not touch an already applied schedule.
     */
    public function test_does_not_touch_an_already_applied_schedule(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();

        $applied = (object) [
            'discussionid' => $fixture->discussion->id,
            'forumid'      => $fixture->forum->id,
            'courseid'     => $fixture->course->id,
            'openat'       => 0,
            'lockat'       => time() - 3600,
            'createdby'    => $fixture->user->id,
            'timecreated'  => time() - 3600,
            'timemodified' => time() - 3600,
            'status'       => 'applied',
        ];
        $appliedid = $DB->insert_record('local_forumlock_schedule', $applied);

        // A later edit that clears the (now-irrelevant) hidden field should
        // not reach back and cancel a schedule that has already fired.
        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            0
        );

        $row = $DB->get_record('local_forumlock_schedule', ['id' => $appliedid]);
        $this->assertSame('applied', $row->status);

        $pendingcount = $DB->count_records('local_forumlock_schedule', [
            'discussionid' => $fixture->discussion->id,
            'status'       => 'pending_lock',
        ]);
        $this->assertSame(0, $pendingcount);
    }

    /**
     * Post updated ignores edits to a reply not the first post.
     */
    public function test_post_updated_ignores_edits_to_a_reply_not_the_first_post(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();

        // Add a reply (parent != 0) to the discussion.
        $reply = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post([
            'discussion' => $fixture->discussion->id,
            'parent'     => $fixture->discussion->firstpost,
            'userid'     => $fixture->user->id,
        ]);

        $_GET['local_forumlock_lockat'] = (string) (time() + 3600);

        $event = \mod_forum\event\post_updated::create([
            'objectid' => $reply->id,
            'context'  => \context_module::instance($fixture->forum->cmid),
            'other'    => [
                'discussionid' => $fixture->discussion->id,
                'forumid'      => $fixture->forum->id,
                'forumtype'    => $fixture->forum->type,
            ],
        ]);

        observer::post_updated($event);

        unset($_GET['local_forumlock_lockat']);

        $count = $DB->count_records('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertSame(0, $count, 'Editing a reply must never create a schedule - only editing the first post should.');
    }

    // Open-gate ("lock until") behaviour.

    /**
     * Open gated schedule is pending open and locks the discussion immediately.
     */
    public function test_open_gated_schedule_is_pending_open_and_locks_the_discussion_immediately(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();
        $openat = time() + 3600;
        $lockat = time() + 7200;

        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            $lockat,
            $openat
        );

        $row = $DB->get_record('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertSame('pending_open', $row->status);
        $this->assertEquals($openat, $row->openat);
        $this->assertEquals($lockat, $row->lockat);

        // Closed immediately, even though nothing has "applied" from the
        // cron task's perspective yet - the open-gate itself closes it.
        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertGreaterThan(0, $discussion->timelocked);
    }

    /**
     * An openat on or after lockat is ignored.
     */
    public function test_an_openat_on_or_after_lockat_is_ignored(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();
        $lockat = time() + 3600;
        $openat = $lockat + 100; // After lockat - invalid combination.

        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            $lockat,
            $openat
        );

        $row = $DB->get_record('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertSame('pending_lock', $row->status, 'An invalid openat should be dropped, not create a contradictory window.');
        $this->assertEquals(0, $row->openat);

        // No open-gating actually took effect, so the discussion stays open.
        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertEquals(0, $discussion->timelocked);
    }

    /**
     * Removing the open gate reopens a discussion closed early.
     */
    public function test_removing_the_open_gate_reopens_a_discussion_closed_early(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture();
        $openat = time() + 3600;
        $lockat = time() + 7200;

        // First: schedule with an open gate - closes the discussion immediately.
        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            $lockat,
            $openat
        );

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertGreaterThan(0, $discussion->timelocked);

        // Then: edit to remove the open gate (openat = 0) while keeping the lock time.
        observer::store_schedule(
            $fixture->discussion->id,
            $fixture->forum->id,
            $fixture->course->id,
            $fixture->user->id,
            $lockat,
            0
        );

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertEquals(0, $discussion->timelocked, 'Removing the gate should reopen it immediately, not wait for cron.');

        $row = $DB->get_record('local_forumlock_schedule', ['discussionid' => $fixture->discussion->id]);
        $this->assertSame('pending_lock', $row->status);
        $this->assertEquals(0, $row->openat);
    }
}

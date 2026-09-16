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

namespace local_forumlock\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/forum/lib.php');

/**
 * Unit tests for local_forumlock\task\apply_locks (both the open phase and
 * the lock phase).
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumlock\task\apply_locks
 */
final class apply_locks_test extends \advanced_testcase {

    /**
     * Creates a course, forum and discussion, and a schedule row against it.
     *
     * @param string $status
     * @param int    $lockat
     * @param int    $openat
     * @return \stdClass with ->discussion and ->scheduleid
     */
    private function create_fixture_with_schedule(string $status, int $lockat, int $openat = 0): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $forum = $forumgenerator->create_instance(['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();

        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum'  => $forum->id,
            'userid' => $user->id,
        ]);

        $scheduleid = $DB->insert_record('local_forumlock_schedule', (object) [
            'discussionid' => $discussion->id,
            'forumid'      => $forum->id,
            'courseid'     => $course->id,
            'openat'       => $openat,
            'lockat'       => $lockat,
            'createdby'    => $user->id,
            'timecreated'  => time(),
            'timemodified' => time(),
            'status'       => $status,
        ]);

        return (object) [
            'course'     => $course,
            'forum'      => $forum,
            'discussion' => $discussion,
            'scheduleid' => $scheduleid,
        ];
    }

    public function test_locks_a_discussion_whose_time_has_come(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture_with_schedule('pending_lock', time() - 10);

        $task = new apply_locks();
        $task->execute();

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertGreaterThan(0, $discussion->timelocked);

        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('applied', $schedule->status);
    }

    public function test_leaves_a_not_yet_due_schedule_untouched(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture_with_schedule('pending_lock', time() + 3600);

        $task = new apply_locks();
        $task->execute();

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertEquals(0, $discussion->timelocked);

        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('pending_lock', $schedule->status);
    }

    public function test_marks_a_schedule_skipped_if_its_discussion_no_longer_exists(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture_with_schedule('pending_lock', time() - 10);
        $DB->delete_records('forum_discussions', ['id' => $fixture->discussion->id]);

        $task = new apply_locks();
        $task->execute();

        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('skipped', $schedule->status);
    }

    public function test_does_not_relock_a_discussion_that_is_already_locked(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture_with_schedule('pending_lock', time() - 10);
        $originallockedat = time() - 5000;
        $DB->set_field('forum_discussions', 'timelocked', $originallockedat, ['id' => $fixture->discussion->id]);

        $task = new apply_locks();
        $task->execute();

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        // Untouched - the task should not overwrite an existing lock time.
        $this->assertEquals($originallockedat, $discussion->timelocked);

        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('applied', $schedule->status);
    }

    public function test_ignores_cancelled_and_skipped_schedules(): void {
        $this->resetAfterTest();
        global $DB;

        $cancelled = $this->create_fixture_with_schedule('cancelled', time() - 10);
        $skipped = $this->create_fixture_with_schedule('skipped', time() - 10);

        $task = new apply_locks();
        $task->execute();

        $cancelleddiscussion = $DB->get_record('forum_discussions', ['id' => $cancelled->discussion->id]);
        $skippeddiscussion = $DB->get_record('forum_discussions', ['id' => $skipped->discussion->id]);

        $this->assertEquals(0, $cancelleddiscussion->timelocked);
        $this->assertEquals(0, $skippeddiscussion->timelocked);
    }

    // --- Open-gate ("lock until") phase ---

    public function test_opens_a_discussion_whose_open_gate_has_arrived(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture_with_schedule('pending_open', time() + 3600, time() - 10);
        // Simulate the observer having closed it immediately at creation time.
        $DB->set_field('forum_discussions', 'timelocked', time() - 100, ['id' => $fixture->discussion->id]);

        $task = new apply_locks();
        $task->execute();

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertEquals(0, $discussion->timelocked, 'Discussion should be reopened once its open-gate time arrives.');

        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('pending_lock', $schedule->status, 'Should move into the lock-waiting phase, not skip straight to applied.');
    }

    public function test_leaves_a_not_yet_due_open_gate_untouched(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture_with_schedule('pending_open', time() + 7200, time() + 3600);
        $DB->set_field('forum_discussions', 'timelocked', time() - 100, ['id' => $fixture->discussion->id]);

        $task = new apply_locks();
        $task->execute();

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertGreaterThan(0, $discussion->timelocked, 'Should remain closed until its open-gate time arrives.');

        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('pending_open', $schedule->status);
    }

    public function test_full_open_then_lock_cycle_across_two_task_runs(): void {
        $this->resetAfterTest();
        global $DB;

        $fixture = $this->create_fixture_with_schedule('pending_open', time() + 10, time() - 10);
        $DB->set_field('forum_discussions', 'timelocked', time() - 100, ['id' => $fixture->discussion->id]);

        $task = new apply_locks();
        $task->execute(); // Should open it and flip to pending_lock.

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertEquals(0, $discussion->timelocked);
        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('pending_lock', $schedule->status);

        // Move lockat into the past to simulate time passing, then run again.
        $DB->set_field('local_forumlock_schedule', 'lockat', time() - 10, ['id' => $fixture->scheduleid]);
        $task->execute();

        $discussion = $DB->get_record('forum_discussions', ['id' => $fixture->discussion->id]);
        $this->assertGreaterThan(0, $discussion->timelocked);
        $schedule = $DB->get_record('local_forumlock_schedule', ['id' => $fixture->scheduleid]);
        $this->assertSame('applied', $schedule->status);
    }
}

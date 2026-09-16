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

/**
 * Reads the "local_forumlock_lockat" hidden field that our injected JS adds
 * to the standard forum post form, and stores it as a schedule row.
 *
 * The hidden field is NOT part of mod_forum's own moodleform, so it never
 * shows up in $mform->get_data(). We read it straight off the raw request
 * instead, inside the event that fires once forum has finished saving.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * A brand new discussion was created.
     *
     * @param \mod_forum\event\discussion_created $event
     */
    public static function discussion_created(\mod_forum\event\discussion_created $event): void {
        $lockat = optional_param('local_forumlock_lockat', 0, PARAM_INT);
        $openat = optional_param('local_forumlock_openat', 0, PARAM_INT);
        self::store_schedule($event->objectid, $event->other['forumid'] ?? null, $event->courseid,
            $event->userid, $lockat, $openat);
    }

    /**
     * An existing post was edited. We only care if it's the discussion's
     * first (root) post, since that's the only point our field is shown.
     *
     * @param \mod_forum\event\post_updated $event
     */
    public static function post_updated(\mod_forum\event\post_updated $event): void {
        global $DB;

        $post = $DB->get_record('forum_posts', ['id' => $event->objectid]);
        if (!$post || !empty($post->parent)) {
            // Not the discussion's first post - our field isn't shown when editing a reply.
            return;
        }

        $lockat = optional_param('local_forumlock_lockat', 0, PARAM_INT);
        $openat = optional_param('local_forumlock_openat', 0, PARAM_INT);
        self::store_schedule($post->discussion, $event->other['forumid'] ?? null, $event->courseid,
            $event->userid, $lockat, $openat);
    }

    /**
     * Common logic: upsert (or cancel) a schedule row for this discussion
     * given already-extracted open/lock timestamps. Public and takes them
     * as plain arguments (rather than reading optional_param() internally)
     * specifically so it can be unit tested without faking superglobals -
     * see tests/observer_test.php.
     *
     * @param int      $discussionid
     * @param int|null $forumid
     * @param int      $courseid
     * @param int      $userid
     * @param int      $lockat 0 means "no lock requested / cleared"
     * @param int      $openat 0 means "no open-gating - postable immediately until lockat"
     */
    public static function store_schedule(
        int $discussionid,
        ?int $forumid,
        int $courseid,
        int $userid,
        int $lockat,
        int $openat = 0
    ): void {
        global $DB;

        if (empty($forumid)) {
            $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid]);
            $forumid = $discussion ? $discussion->forum : 0;
        }

        $existing = $DB->get_record_select(
            'local_forumlock_schedule',
            "discussionid = :discussionid AND status IN ('pending_open', 'pending_lock')",
            ['discussionid' => $discussionid]
        );

        if (empty($lockat) || $lockat <= time()) {
            // Field left blank, or a time in the past - nothing to schedule.
            // If a pending schedule already existed, treat this as the
            // teacher clearing it and cancel it - reopening the discussion
            // first if it had been closed early for an open-gate.
            if ($existing) {
                if ($existing->status === 'pending_open') {
                    \local_forumlock\lock_manager::unlock($discussionid);
                }
                $DB->set_field('local_forumlock_schedule', 'status', 'cancelled', ['id' => $existing->id]);
            }
            return;
        }

        // An open-gate only makes sense strictly before the lock time;
        // ignore it otherwise rather than creating a contradictory window.
        if ($openat > 0 && $openat >= $lockat) {
            $openat = 0;
        }

        $newstatus = ($openat > time()) ? 'pending_open' : 'pending_lock';

        if ($existing) {
            // If a gate that was closing the discussion early is being
            // removed or has already passed, reopen it now rather than
            // waiting for the cron task to notice - the task only opens
            // schedules that are still 'pending_open'.
            if ($existing->status === 'pending_open' && $newstatus !== 'pending_open') {
                \local_forumlock\lock_manager::unlock($discussionid);
            }

            $existing->openat = $openat;
            $existing->lockat = $lockat;
            $existing->status = $newstatus;
            $existing->timemodified = time();
            $DB->update_record('local_forumlock_schedule', $existing);
        } else {
            $record = (object) [
                'discussionid' => $discussionid,
                'forumid'      => $forumid,
                'courseid'     => $courseid,
                'openat'       => $openat,
                'lockat'       => $lockat,
                'createdby'    => $userid,
                'timecreated'  => time(),
                'timemodified' => time(),
                'status'       => $newstatus,
            ];
            $DB->insert_record('local_forumlock_schedule', $record);
        }

        if ($newstatus === 'pending_open') {
            // Close it right away so nobody can post before the window
            // opens - the cron task's job is only to open it later, not to
            // perform this initial close.
            \local_forumlock\lock_manager::lock($discussionid, time());
        }
    }
}

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

use local_forumlock\lock_manager;


/**
 * Cron task: runs two independent passes each time it fires.
 *
 * Phase 1 (open): schedules with an open-gate ("lock until") whose openat
 * has arrived get unlocked, and move from pending_open to pending_lock.
 *
 * Phase 2 (lock): schedules in pending_lock whose lockat has arrived get
 * locked, and move to applied. This covers both plain schedules (no open
 * gating at all) and ones that just transitioned out of phase 1 above.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_locks extends \core\task\scheduled_task {
    /**
     * Name of this scheduled task, shown in the admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskapplylocks', 'local_forumlock');
    }

    /**
     * Opens then locks any discussions whose scheduled time has arrived.
     */
    public function execute() {
        $this->run_open_phase();
        $this->run_lock_phase();
    }

    /**
     * Phase 1: open any discussion whose open-gate time has arrived.
     */
    private function run_open_phase(): void {
        global $DB;

        $now = time();
        $due = $DB->get_records_select(
            'local_forumlock_schedule',
            'status = :status AND openat > 0 AND openat <= :now',
            ['status' => 'pending_open', 'now' => $now]
        );

        mtrace('local_forumlock: found ' . count($due) . ' schedule(s) due to open.');

        foreach ($due as $row) {
            try {
                $discussion = $DB->get_record('forum_discussions', ['id' => $row->discussionid]);

                if (!$discussion) {
                    mtrace('local_forumlock: discussion ' . $row->discussionid . ' no longer exists, skipping.');
                    $DB->set_field('local_forumlock_schedule', 'status', 'skipped', ['id' => $row->id]);
                    continue;
                }

                mtrace('local_forumlock: opening discussion ' . $row->discussionid .
                    ' (forum ' . $discussion->forum . '), was gated until ' . userdate($row->openat));
                lock_manager::unlock($row->discussionid);

                $DB->set_field('local_forumlock_schedule', 'status', 'pending_lock', ['id' => $row->id]);
                $DB->set_field('local_forumlock_schedule', 'timemodified', $now, ['id' => $row->id]);
            } catch (\Throwable $e) {
                $detail = $this->format_exception($e);
                mtrace('local_forumlock: FAILED to open discussion ' . $row->discussionid . ': ' . $detail);
            }
        }
    }

    /**
     * Phase 2: lock any discussion in pending_lock whose lockat has arrived.
     */
    private function run_lock_phase(): void {
        global $DB;

        $now = time();
        $due = $DB->get_records_select(
            'local_forumlock_schedule',
            'status = :status AND lockat <= :now',
            ['status' => 'pending_lock', 'now' => $now]
        );

        mtrace('local_forumlock: found ' . count($due) . ' schedule(s) due for locking.');

        foreach ($due as $row) {
            try {
                $discussion = $DB->get_record('forum_discussions', ['id' => $row->discussionid]);

                if (!$discussion) {
                    mtrace('local_forumlock: discussion ' . $row->discussionid . ' no longer exists, skipping.');
                    $DB->set_field('local_forumlock_schedule', 'status', 'skipped', ['id' => $row->id]);
                    continue;
                }

                if (empty($discussion->timelocked)) {
                    mtrace('local_forumlock: locking discussion ' . $row->discussionid .
                        ' (forum ' . $discussion->forum . '), scheduled for ' . userdate($row->lockat));
                    lock_manager::lock($row->discussionid, $now);
                } else {
                    mtrace('local_forumlock: discussion ' . $row->discussionid .
                        ' already has timelocked=' . $discussion->timelocked . ', leaving as-is.');
                }

                $DB->set_field('local_forumlock_schedule', 'status', 'applied', ['id' => $row->id]);
                $DB->set_field('local_forumlock_schedule', 'timemodified', $now, ['id' => $row->id]);
            } catch (\Throwable $e) {
                $detail = $this->format_exception($e);
                mtrace('local_forumlock: FAILED to lock discussion ' . $row->discussionid . ': ' . $detail);
            }
        }
    }

    /**
     * Includes the DB driver's debuginfo (if present) alongside the
     * exception message, since the generic message alone hides the actual
     * cause of a dml_write_exception.
     *
     * @param \Throwable $e
     * @return string
     */
    private function format_exception(\Throwable $e): string {
        $detail = $e->getMessage();
        if (property_exists($e, 'debuginfo') && !empty($e->debuginfo)) {
            $detail .= ' | debuginfo: ' . $e->debuginfo;
        }
        return get_class($e) . ' - ' . $detail;
    }
}

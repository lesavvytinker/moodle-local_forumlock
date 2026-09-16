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
 * Writes forum_discussions.timelocked directly (confirmed to be the real
 * column - see apply_locks docblock history) and purges the caches that
 * would otherwise show stale discussion state.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lock_manager {

    /**
     * Lock a discussion as of the given timestamp.
     *
     * @param int $discussionid
     * @param int $timestamp
     */
    public static function lock(int $discussionid, int $timestamp): void {
        global $DB;
        $DB->set_field('forum_discussions', 'timelocked', $timestamp, ['id' => $discussionid]);
        self::purge_caches();
    }

    /**
     * Unlock a discussion (open it for replies again).
     *
     * @param int $discussionid
     */
    public static function unlock(int $discussionid): void {
        global $DB;
        $DB->set_field('forum_discussions', 'timelocked', 0, ['id' => $discussionid]);
        self::purge_caches();
    }

    /**
     * Best-effort cache purge - failure here doesn't undo the DB write
     * above, it just means the change may take a little longer to show up
     * everywhere.
     */
    private static function purge_caches(): void {
        try {
            \cache_helper::purge_by_event('changesinforumdiscussion');
        } catch (\Throwable $e) {
            // Non-critical.
        }
    }
}

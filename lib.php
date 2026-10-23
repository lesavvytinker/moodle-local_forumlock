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
 * Library callbacks for local_forumlock.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Works out what the "Lock discussion" section on post.php should be
 * prefilled with, so editing a post doesn't silently wipe out an existing
 * schedule the observer would otherwise read as "cleared."
 *
 * Only fires when post.php's "edit" param is a real first-post edit -
 * new discussions and replies have nothing to prefill from.
 *
 * @return array{enabled: bool, datetime: string, status: string, readonly: bool,
 *               openenabled: bool, opendatetime: string}
 */
function local_forumlock_get_edit_prefill(): array {
    global $DB;

    $none = [
        'enabled' => false, 'datetime' => '', 'status' => '', 'readonly' => false,
        'openenabled' => false, 'opendatetime' => '',
    ];

    $editid = optional_param('edit', 0, PARAM_INT);
    if (empty($editid)) {
        return $none;
    }

    $post = $DB->get_record('forum_posts', ['id' => $editid]);
    if (!$post || !empty($post->parent)) {
        // Not editing a discussion's first post - our section only applies there.
        return $none;
    }

    // Prefer an in-progress schedule if one exists; otherwise show the most
    // recent applied one, purely informationally.
    $schedule = $DB->get_record_select(
        'local_forumlock_schedule',
        "discussionid = :discussionid AND status IN ('pending_open', 'pending_lock')",
        ['discussionid' => $post->discussion]
    );

    if (!$schedule) {
        $schedule = $DB->get_record(
            'local_forumlock_schedule',
            ['discussionid' => $post->discussion, 'status' => 'applied'],
            'id, openat, lockat, status',
            IGNORE_MULTIPLE
        );
    }

    if (!$schedule) {
        return $none;
    }

    $tz = \core_date::get_user_timezone_object();

    $lockdt = new \DateTime('@' . $schedule->lockat);
    $lockdt->setTimezone($tz);

    $result = [
        'enabled'      => true,
        'datetime'     => $lockdt->format('Y-m-d\TH:i'),
        'status'       => $schedule->status,
        // Already-applied locks are shown read-only: editing the time here
        // wouldn't do anything to a lock that's already happened, and we
        // don't want a resubmitted edit to accidentally create a stray new
        // pending schedule.
        'readonly'     => ($schedule->status === 'applied'),
        'openenabled'  => false,
        'opendatetime' => '',
    ];

    if (!empty($schedule->openat)) {
        $opendt = new \DateTime('@' . $schedule->openat);
        $opendt->setTimezone($tz);
        $result['openenabled'] = true;
        $result['opendatetime'] = $opendt->format('Y-m-d\TH:i');
    }

    return $result;
}

/**
 * Looks up the current schedule for a single discussion, for display on
 * discuss.php. Prefers an in-progress schedule if one exists, otherwise
 * the most recent applied one.
 *
 * @param int $discussionid
 * @return array{status: string, message: string}|null
 */
function local_forumlock_get_discussion_lock_info(int $discussionid): ?array {
    global $DB;

    $schedule = $DB->get_record_select(
        'local_forumlock_schedule',
        "discussionid = :discussionid AND status IN ('pending_open', 'pending_lock')",
        ['discussionid' => $discussionid]
    );

    if (!$schedule) {
        $schedule = $DB->get_record(
            'local_forumlock_schedule',
            ['discussionid' => $discussionid, 'status' => 'applied'],
            'id, openat, lockat, status',
            IGNORE_MULTIPLE
        );
    }

    if (!$schedule) {
        return null;
    }

    $lockdisplay = userdate($schedule->lockat, '%d %b %Y, %I:%M %p');

    if ($schedule->status === 'applied') {
        $message = get_string('bannerlocked', 'local_forumlock', $lockdisplay);
    } else if ($schedule->status === 'pending_open') {
        $message = get_string('bannerpendingopen', 'local_forumlock', (object) [
            'open' => userdate($schedule->openat, '%d %b %Y, %I:%M %p'),
            'lock' => $lockdisplay,
        ]);
    } else {
        $message = get_string('bannerscheduled', 'local_forumlock', $lockdisplay);
    }

    return ['status' => $schedule->status, 'message' => $message];
}

/**
 * Works out whether the current mod/forum/post.php request is for a
 * discussion's own first/root post (a brand-new discussion, or editing
 * that first post) as opposed to a reply (a new reply, or editing an
 * existing reply).
 *
 * The lock/open schedule is a property of the discussion as a whole, so
 * the "Lock discussion" section only makes sense on the root post. This
 * mirrors the same parent-post check already used server-side in
 * \local_forumlock\observer::post_updated() and in
 * local_forumlock_get_edit_prefill(), so all three stay in agreement
 * about what counts as "the discussion's post" - without this check the
 * section was showing up on replies too, where a lock/open time has no
 * meaning.
 *
 * post.php identifies what it's doing via one of a few params:
 * - forum=X : starting a brand-new discussion in forum X.
 * - reply=X : replying to post X (X is a post id, not a discussion id).
 * - edit=X  : editing existing post X (could be the root post or a reply).
 *
 * @return bool true if this is a new discussion or an edit of a root post.
 */
function local_forumlock_postform_is_root_post(): bool {
    global $DB;

    // Replying to an existing post is never the discussion's root post.
    $replyto = optional_param('reply', 0, PARAM_INT);
    if (!empty($replyto)) {
        return false;
    }

    // Editing an existing post: only the root post (no parent) counts.
    $editid = optional_param('edit', 0, PARAM_INT);
    if (!empty($editid)) {
        $post = $DB->get_record('forum_posts', ['id' => $editid], 'id, parent');
        if (!$post) {
            // Can't tell - fail closed and don't show the section.
            return false;
        }
        return empty($post->parent);
    }

    // Neither reply= nor edit= is set: this is a brand-new discussion
    // (post.php?forum=X), which is always the root post.
    $forumid = optional_param('forum', 0, PARAM_INT);
    return !empty($forumid);
}

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

defined('MOODLE_INTERNAL') || die();

/**
 * Reads a JS file from our js/ folder and returns its content, or null if
 * it can't be read. We print JS inline rather than via <script src="...">
 * because some nginx/YunoHost configurations only route specific
 * extensions/paths through to Moodle, and a plugin's js/ subfolder can fall
 * outside that allow-list even though Moodle's own PHP files in the same
 * plugin are served fine.
 *
 * @param string $filename e.g. 'postform.js'
 * @return string|null
 */
function local_forumlock_read_js(string $filename): ?string {
    $jsfile = __DIR__ . '/js/' . $filename;
    if (!is_readable($jsfile)) {
        debugging('local_forumlock: ' . $filename . ' not found on disk at ' . $jsfile, DEBUG_DEVELOPER);
        return null;
    }
    return file_get_contents($jsfile);
}

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
    $schedule = $DB->get_record_select('local_forumlock_schedule',
        "discussionid = :discussionid AND status IN ('pending_open', 'pending_lock')",
        ['discussionid' => $post->discussion]);

    if (!$schedule) {
        $schedule = $DB->get_record('local_forumlock_schedule',
            ['discussionid' => $post->discussion, 'status' => 'applied'],
            'id, openat, lockat, status', IGNORE_MULTIPLE);
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

    $schedule = $DB->get_record_select('local_forumlock_schedule',
        "discussionid = :discussionid AND status IN ('pending_open', 'pending_lock')",
        ['discussionid' => $discussionid]);

    if (!$schedule) {
        $schedule = $DB->get_record('local_forumlock_schedule',
            ['discussionid' => $discussionid, 'status' => 'applied'],
            'id, openat, lockat, status', IGNORE_MULTIPLE);
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
 * Injects our JS on three forum pages:
 *  - post.php: adds the "Lock discussion" section to the post form -
 *    only for users who hold local/forumlock:managelocks (the scheduling
 *    UI itself stays teacher-only).
 *  - view.php: adds an availability column to the discussion list.
 *  - discuss.php: adds a banner showing this discussion's current status.
 *    Both of these are shown to anyone who can view the forum at all
 *    (students included) - the whole point of the open-gate feature is
 *    for them to see upcoming/current windows, not just teachers.
 * Kept deliberately narrow so it never touches other pages or other roles.
 */
function local_forumlock_before_standard_top_of_body_html(): string {
    global $PAGE;

    $path = $PAGE->url ? $PAGE->url->out_omit_querystring() : '';
    $output = '';

    if (strpos($path, '/mod/forum/post.php') !== false) {
        if (has_capability('local/forumlock:managelocks', $PAGE->context)) {
            $js = local_forumlock_read_js('postform.js');
            if ($js !== null) {
                $bootstrap = 'window.localForumlockEdit = ' .
                    json_encode(local_forumlock_get_edit_prefill()) . ';' .
                    'window.localForumlockStrings = ' . json_encode([
                        'header'    => get_string('lockdiscussionheader', 'local_forumlock'),
                        'enable'    => get_string('enablelockcheckbox', 'local_forumlock'),
                        'lockat'    => get_string('lockatlabel', 'local_forumlock'),
                        'help'      => get_string('lockhelptext', 'local_forumlock'),
                        'readonly'  => get_string('alreadylockednote', 'local_forumlock'),
                        'openenable' => get_string('enableopencheckbox', 'local_forumlock'),
                        'openat'    => get_string('openatlabel', 'local_forumlock'),
                        'openhelp'  => get_string('openhelptext', 'local_forumlock'),
                        'openaftererror' => get_string('openafterlockerror', 'local_forumlock'),
                    ]) . ';';
                $output .= \html_writer::tag('script', $bootstrap);
                $output .= \html_writer::tag('script', $js);
            }
        }
    } else if (strpos($path, '/mod/forum/discuss.php') !== false) {
        if (has_capability('mod/forum:viewdiscussion', $PAGE->context)) {
            $discussionid = optional_param('d', 0, PARAM_INT);
            $info = $discussionid ? local_forumlock_get_discussion_lock_info($discussionid) : null;
            if ($info) {
                $js = local_forumlock_read_js('discussbanner.js');
                if ($js !== null) {
                    $bootstrap = 'window.localForumlockBanner = ' . json_encode($info) . ';';
                    $output .= \html_writer::tag('script', $bootstrap);
                    $output .= \html_writer::tag('script', $js);
                }
            }
        }
    } else if (strpos($path, '/mod/forum/view.php') !== false) {
        $cm = null;
        if ($PAGE->context && $PAGE->context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('forum', $PAGE->context->instanceid, 0, false, IGNORE_MISSING);
        }

        if ($cm && $cm->modname === 'forum' && has_capability('mod/forum:viewdiscussion', $PAGE->context)) {
            $js = local_forumlock_read_js('discussionlist.js');
            if ($js !== null) {
                $bootstrap = 'window.localForumlock = ' . json_encode([
                    'forumid' => (int) $cm->instance,
                    'ajaxurl' => (new \moodle_url('/local/forumlock/ajax.php'))->out(false),
                    'sesskey' => sesskey(),
                    'strings' => [
                        'header'         => get_string('lockscolumnheader', 'local_forumlock'),
                        'noneplaceholder' => get_string('noneplaceholder', 'local_forumlock'),
                    ],
                ]) . ';';
                $output .= \html_writer::tag('script', $bootstrap);
                $output .= \html_writer::tag('script', $js);
            }
        }
    }

    return $output;
}

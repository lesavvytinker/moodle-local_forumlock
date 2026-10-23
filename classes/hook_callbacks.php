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

use core\hook\output\before_standard_top_of_body_html_generation;

/**
 * Hook callbacks for local_forumlock.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Loads our AMD modules on three forum pages:
     *  - post.php: adds the "Lock discussion" section to the post form -
     *    only for users who hold local/forumlock:managelocks (the scheduling
     *    UI itself stays teacher-only), and only on a discussion's root post.
     *  - view.php: adds an availability column to the discussion list.
     *  - discuss.php: adds a banner showing this discussion's current status.
     *    Both of these are shown to anyone who can view the forum at all
     *    (students included) - the whole point of the open-gate feature is
     *    for them to see upcoming/current windows, not just teachers.
     * Kept deliberately narrow so it never touches other pages or other roles.
     *
     * @param before_standard_top_of_body_html_generation $hook
     */
    public static function before_standard_top_of_body_html_generation(
        before_standard_top_of_body_html_generation $hook
    ): void {
        global $CFG, $PAGE;

        require_once($CFG->dirroot . '/local/forumlock/lib.php');

        $path = $PAGE->has_set_url() ? $PAGE->url->out_omit_querystring() : '';

        if (strpos($path, '/mod/forum/post.php') !== false) {
            if (
                has_capability('local/forumlock:managelocks', $PAGE->context)
                    && local_forumlock_postform_is_root_post()
            ) {
                $strings = [
                    'header'    => get_string('lockdiscussionheader', 'local_forumlock'),
                    'enable'    => get_string('enablelockcheckbox', 'local_forumlock'),
                    'lockat'    => get_string('lockatlabel', 'local_forumlock'),
                    'help'      => get_string('lockhelptext', 'local_forumlock'),
                    'readonly'  => get_string('alreadylockednote', 'local_forumlock'),
                    'openenable' => get_string('enableopencheckbox', 'local_forumlock'),
                    'openat'    => get_string('openatlabel', 'local_forumlock'),
                    'openhelp'  => get_string('openhelptext', 'local_forumlock'),
                    'openaftererror' => get_string('openafterlockerror', 'local_forumlock'),
                ];
                $PAGE->requires->js_call_amd('local_forumlock/postform', 'init', [
                    local_forumlock_get_edit_prefill(),
                    $strings,
                ]);
            }
        } else if (strpos($path, '/mod/forum/discuss.php') !== false) {
            if (has_capability('mod/forum:viewdiscussion', $PAGE->context)) {
                $discussionid = optional_param('d', 0, PARAM_INT);
                $info = $discussionid ? local_forumlock_get_discussion_lock_info($discussionid) : null;
                if ($info) {
                    $PAGE->requires->js_call_amd('local_forumlock/discussbanner', 'init', [$info]);
                }
            }
        } else if (strpos($path, '/mod/forum/view.php') !== false) {
            $cm = null;
            if ($PAGE->context && $PAGE->context->contextlevel == CONTEXT_MODULE) {
                $cm = get_coursemodule_from_id('forum', $PAGE->context->instanceid, 0, false, IGNORE_MISSING);
            }

            if ($cm && $cm->modname === 'forum' && has_capability('mod/forum:viewdiscussion', $PAGE->context)) {
                $PAGE->requires->js_call_amd('local_forumlock/discussionlist', 'init', [[
                    'forumid' => (int) $cm->instance,
                    'ajaxurl' => (new \moodle_url('/local/forumlock/ajax.php'))->out(false),
                    'sesskey' => sesskey(),
                    'strings' => [
                        'header'          => get_string('lockscolumnheader', 'local_forumlock'),
                        'noneplaceholder' => get_string('noneplaceholder', 'local_forumlock'),
                    ],
                ]]);
            }
        }
    }
}

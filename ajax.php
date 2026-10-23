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
 * Returns lock/open schedules for a given forum as JSON, so the
 * discussion-list JS can annotate rows with an availability column.
 *
 * Deliberately a plain script rather than the full webservice/external API
 * for simplicity - it's read-only, capability-checked, sesskey-checked,
 * and only ever returns data already visible to anyone who can view the
 * forum (students included, deliberately - the point of the open-gate
 * feature is for them to see upcoming/current windows too).
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login(null, false);
require_sesskey();

$forumid = required_param('forumid', PARAM_INT);

$forum = $DB->get_record('forum', ['id' => $forumid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_capability('mod/forum:viewdiscussion', $context);

$schedules = $DB->get_records_select(
    'local_forumlock_schedule',
    'forumid = :forumid AND status IN (:pendingopen, :pendinglock, :applied)',
    ['forumid' => $forumid, 'pendingopen' => 'pending_open', 'pendinglock' => 'pending_lock', 'applied' => 'applied'],
    '',
    'discussionid, openat, lockat, status'
);

$out = [];
foreach ($schedules as $row) {
    if ($row->status === 'applied') {
        $label = get_string('columnlockedvalue', 'local_forumlock', userdate($row->lockat, '%d %b %Y, %I:%M %p'));
    } else if ($row->status === 'pending_open') {
        $label = get_string('columnopeningvalue', 'local_forumlock', (object) [
            'open' => userdate($row->openat, '%d %b %Y, %I:%M %p'),
            'lock' => userdate($row->lockat, '%d %b %Y, %I:%M %p'),
        ]);
    } else {
        $label = get_string('columnlockingvalue', 'local_forumlock', userdate($row->lockat, '%d %b %Y, %I:%M %p'));
    }

    $out[] = [
        'discussionid' => (int) $row->discussionid,
        'lockat'       => (int) $row->lockat,
        'status'       => $row->status,
        'label'        => $label,
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out);

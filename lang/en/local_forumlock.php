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
 * Language strings for local_forumlock.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Discussion lock scheduler';
$string['forumlock:managelocks'] = 'Schedule a discussion to lock at a specific time';
$string['taskapplylocks'] = 'Apply scheduled discussion locks';

// Post form section (postform.js).
$string['lockdiscussionheader'] = 'Lock discussion';
$string['enablelockcheckbox'] = 'Lock this discussion at a set time';
$string['lockatlabel'] = 'Lock at';
$string['lockhelptext'] = 'Students will still be able to read the discussion after this time, but will no longer be able to reply. Minutes are limited to :00, :15, :30 and :45, since that\'s how often this site checks for locks that are due.';
$string['alreadylockednote'] = 'This discussion is already locked as of the time above. To change it, unlock the discussion manually first, then set a new schedule.';
$string['enableopencheckbox'] = 'Also delay when replies open until a set time';
$string['openatlabel'] = 'Opens at';
$string['openhelptext'] = 'The discussion is visible the whole time, but students can\'t reply until this time. Must be earlier than the lock time above.';
$string['openafterlockerror'] = 'The opening time must be earlier than the locking time.';

// Discussion list column (discussionlist.js / ajax.php).
$string['lockscolumnheader'] = 'Availability';
$string['columnlockedvalue'] = 'Locked {$a}';
$string['columnlockingvalue'] = 'Locks {$a}';
$string['columnopeningvalue'] = 'Opens for replies {$a->open}
Locks {$a->lock}';
$string['noneplaceholder'] = '—';

// Discussion view banner (discussbanner.js).
$string['bannerlocked'] = 'This discussion locked on {$a}.';
$string['bannerscheduled'] = 'This discussion is scheduled to lock on {$a}.';
$string['bannerpendingopen'] = 'Opens for replies {$a->open}
Locks {$a->lock}';

$string['privacy:metadata:local_forumlock_schedule'] = 'Details of a scheduled discussion lock.';
$string['privacy:metadata:local_forumlock_schedule:discussionid'] = 'The discussion being scheduled for locking.';
$string['privacy:metadata:local_forumlock_schedule:createdby'] = 'The user who scheduled the lock.';
$string['privacy:metadata:local_forumlock_schedule:lockat'] = 'The time the discussion is scheduled to lock.';
$string['privacy:metadata:local_forumlock_schedule:timecreated'] = 'The time the schedule was created.';

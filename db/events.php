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
 * Event observers for local_forumlock.
 *
 * We listen for the two moments a discussion's "first post" is written:
 * creation of a new discussion, and editing of an existing first post
 * (in case a teacher adds/changes the lock time after the fact).
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_forum\event\discussion_created',
        'callback'  => '\local_forumlock\observer::discussion_created',
    ],
    [
        'eventname' => '\mod_forum\event\post_updated',
        'callback'  => '\local_forumlock\observer::post_updated',
    ],
];

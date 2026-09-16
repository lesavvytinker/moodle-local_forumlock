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
 * Upgrade steps for local_forumlock.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_forumlock_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091500) {

        $table = new xmldb_table('local_forumlock_schedule');
        $field = new xmldb_field('openat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'courseid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('status_openat', XMLDB_INDEX_NOTUNIQUE, ['status', 'openat']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Old lifecycle only had 'pending' (meaning "waiting to lock, no
        // open-gating"). Rename it to 'pending_lock' so it fits the new
        // three-phase status set without changing behaviour for existing
        // schedules - they all have openat=0 already via the field default.
        $DB->set_field('local_forumlock_schedule', 'status', 'pending_lock', ['status' => 'pending']);

        upgrade_plugin_savepoint(true, 2026091500, 'local', 'forumlock');
    }

    return true;
}

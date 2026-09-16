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
 * Version details for local_forumlock.
 *
 * @package    local_forumlock
 * @copyright  2026 Harvey / Equip English
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_forumlock';
$plugin->version   = 2026091504;      // YYYYMMDDXX. Bump on every release, even docs-only, per Marketplace rules.
$plugin->requires  = 2024100700;      // Moodle 4.5+. CONFIRM this against your exact Moodle 5.x build number before submitting.
$plugin->maturity  = MATURITY_BETA;   // Tested live on one Postgres site; not yet peer-reviewed or tested on MySQL.
$plugin->release   = '1.1.1';         // Fix: availability column/banner are now visible to students, not just teachers.

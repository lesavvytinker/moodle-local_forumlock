# local_forumlock

Schedule an individual forum **discussion** (not the whole forum activity)
to lock at a specific date/time — so students can still read it afterward,
but can no longer post to it. Fills a real gap: Moodle can auto-lock a
discussion after a period of *inactivity*, and a teacher can lock one
*manually*, but there's no native way to say "lock this one at 5pm Friday."

## Features

- A "Lock discussion" section on the forum post form (new discussion or
  editing an existing first post), styled to match the native "Display
  period" section, with a date picker and hour/minute dropdowns (minutes
  limited to :00/:15/:30/:45 to match realistic cron granularity).
- An optional "opens at" gate (lock until): the discussion is closed the
  moment it's created, visible in the list the whole time, and only becomes
  repliable once the open time arrives - then locks again at the lock time.
  Useful for Q&A-style forums where you want future topics visible in
  advance without letting students answer early.
- A "Locks at"/availability column on the forum's discussion list,
  positioned before "Started by", visible to anyone who can view the forum
  (students included), showing the current phase (opening/locking/locked)
  and time - both dates together during the "opening soon" phase. Only
  appears at all if the forum has something scheduled/locked.
- A banner on a discussion's own page, visible to students too, showing
  its current lock status.
- Editing a discussion that already has a schedule shows that schedule
  rather than a blank, unticked box.

## How it works

1. A Moodle hook callback (`db/hooks.php` -> `classes/hook_callbacks.php`)
   loads small AMD modules (`amd/src/`, built into `amd/build/`) on three
   `mod/forum` pages only (`post.php`, `view.php`, `discuss.php`). The
   scheduling UI on `post.php` is only loaded for users who hold
   `local/forumlock:managelocks` in that forum's context.
2. The post-form JS stores the chosen lock time (and, optionally, an open
   time) as Unix timestamps in two hidden fields (`local_forumlock_lockat`,
   `local_forumlock_openat`) that ride along in the normal form POST -
   mod_forum's own moodleform ignores fields it doesn't know about.
3. `classes/observer.php` listens for `\mod_forum\event\discussion_created`
   and `\mod_forum\event\post_updated`, reads those hidden fields off the
   raw request, and upserts a row in `local_forumlock_schedule`. A schedule
   moves through up to three states: `pending_open` (waiting to open, if an
   open-gate was set) → `pending_lock` (waiting to lock) → `applied`
   (locked). If an open-gate is set, the observer closes the discussion
   immediately via `classes/lock_manager.php`, rather than waiting for cron.
4. `classes/task/apply_locks.php` runs two passes each time it fires (on a
   configurable schedule, default every 5 minutes, bounded in practice by
   how often your server's system cron actually invokes
   `admin/cli/cron.php`): first opening any due `pending_open` schedules,
   then locking any due `pending_lock` schedules.
5. `ajax.php` is a small sesskey-checked, capability-checked JSON endpoint
   the discussion-list script calls to populate the "Locks at" column.

## Before submitting to Moodle Marketplace

- **Confirm `$plugin->requires` in `version.php`** against your actual
  Moodle build number — currently set as a placeholder for Moodle 4.5+.
- **Test on MySQL, not just PostgreSQL.** All DB access goes through the
  standard Moodle DML API (no raw SQL), so it should be fine, but this
  hasn't been verified on MySQL yet, and the Marketplace checklist
  explicitly calls out cross-DB testing.
- **PHPUnit coverage exists** for the observer's upsert/cancel logic and
  the apply_locks task (`tests/observer_test.php`,
  `tests/task/apply_locks_test.php`) - run via
  `vendor/bin/phpunit --testsuite=local_forumlock_testsuite` from your
  Moodle root once `phpunit --configure` (or `admin/tool/phpunit/cli/init.php`)
  has been run for your site. No Behat scenarios yet for the JS-driven
  form flow.
- **Run it through `moodle-plugin-ci`** (phpcs with the Moodle standard,
  PHP lint, mustache lint) if you have it set up — this plugin hasn't been
  run through automated style checking yet.
- Not yet built: a way to view/cancel a pending scheduled lock other than
  re-editing the first post with the checkbox off, and a dedicated
  management page listing all pending locks across a forum.
- The old Moodle Plugins directory has closed to new submissions; this
  goes through Moodle Marketplace instead — check their current submission
  process at moodle.com/marketplace, since specifics may differ from the
  legacy directory checklist this README otherwise follows.

## Capability

`local/forumlock:managelocks` — granted to editingteacher/teacher/manager
by default, mirroring `mod/forum:canoverridediscussionlock`.

## Privacy

The only personal data stored is who scheduled a lock (`createdby` on
`local_forumlock_schedule`). On a user-erasure request the user is
anonymised out of those rows (`createdby` set to 0) rather than the rows
being deleted, because the schedule itself isn't personal data and other
users rely on it - deleting it would silently cancel a lock, or leave a
gated discussion closed for good. Export, user-list and context-list
requests are all supported; see `classes/privacy/provider.php` and
`tests/privacy/provider_test.php`.

# local_forumlock

Schedule an individual forum **discussion** (not the whole forum activity)
to lock at a specific date/time — so students can still read it afterward,
but can no longer post to it. Fills a real gap: Moodle can auto-lock a
discussion after a period of *inactivity*, and a teacher can lock one
*manually*, but there's no native way to say "lock this one at 5pm Friday."

## Using it

1. Open the forum and click **Add discussion topic** (or edit the first post of an existing discussion).
2. Click **Advanced** to expand the extra options. This is easy to miss, but the lock settings sit in here, just below **Display period**.
3. Under **Lock discussion**, tick **Lock this discussion at a set time** and choose the date and time.
4. Optional: tick **Also delay when replies open until a set time** and choose when replies should open. It must be earlier than the lock time.
5. Post as normal. The discussion locks at the time you chose (Moodle's cron must be running).

Students see a banner on the discussion and an availability column on the
forum's discussion list showing when it opens and locks.

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

## Requirements

Moodle 4.5 or later (uses the hooks API). Needs Moodle's cron to be running.

# Changelog

## 1.3.1 (2026-10-06)
- Privacy: declare every user-linked field, fix the users-in-context query,
  anonymise (rather than delete) schedule rows on a user-erasure request,
  export timestamps in readable form, add provider tests.

## 1.3.0 (2026-10-06)
- Front-end moved to AMD modules (`amd/src`, built to `amd/build`) loaded
  through Moodle's hooks API instead of inline script injection.
- Bootstrap 5 compatibility for the fallback date selects.
- Moodle coding-standards clean-up across the plugin.

## 1.2.0
- Lock/open date fields clone the native Display period selects so they
  match the theme; "Lock discussion" only appears on a discussion's first
  post, never on replies.

## Earlier
- Scheduled per-discussion lock, optional open-gate ("lock until"),
  discussion-list column, discussion page banner, scheduled task, tests.

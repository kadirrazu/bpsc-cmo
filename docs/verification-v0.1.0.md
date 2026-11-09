# Starter verification — 8 November 2026

- Full automated test suite: **34 passed, 439 assertions** on PHP 8.3.6 with SQLite in-memory databases.
- Tests cover all four roles, authentication, inactive account/session protection, self-administration safeguards, own-profile and password changes, shared user/designation/unit administration, CRUD auditing, expert pool operations, public profile privacy, search/type filters, questionnaire types and validation, restricted evaluation visibility, immutable snapshots, duplicates, stale forms and future-date rejection.
- All Blade templates compile to valid PHP; route cache and view cache compile successfully.
- Production frontend build passed using the included npm lockfile; compiled assets included.
- Composer manifest validation passed. The inherited dependency audit identified affected Symfony components; compatible patch releases were applied. Composer reported no security advisories after the update.
- A fresh database migration plus seeding passed on SQLite. Live MySQL/WAMP testing was not performed in this environment.
- Browser visual QA could not run because the available environment blocks the browser's process socket. Responsive styling is included, but desktop/mobile visual appearance should be checked in the user's browser.
- QA data, test credentials, `.env`, installed dependencies, runtime databases, logs, compiled Blade caches and screenshots are excluded from the deliverable. No default user credentials or actual experts are shipped.

The older library-specific test suite is excluded because its circulation/catalog/member-registration modules are not part of this application. Shared profile regression tests were retained and adapted to the new four-role permission model; the full CMO suite above covers the delivered starter.

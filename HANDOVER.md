# Handover — unraid-plg-connections

Written 2026-09-15 after the phase 7 release.

## Repo state

- Branch `master`, HEAD `de09e84` (Fix: Apply hang, v2026.09.15.08), pushed to `origin` (Forgejo). Working tree clean apart from the regenerated screenshots.
- The git identity is set in the repo config (`git config user.name/email`) because the host has no global config. Without it, `ci/publish` fails at the commit step with exit 8.

## Running state

- Factory: v2026.09.15.08 deployed and verified on the test server 192.168.1.4 (`/var/log/plugins/unraid-connections.plg`). Post-deploy smoke (21 steps) and 56 Playwright tests green.
- Storefront (GitHub, Community Applications): still v2026.09.11.08 (version 1). Version 2 and 3 wait for the owner's go-ahead (Forgejo #9).
- Verify live: `php -q /usr/local/emhttp/plugins/unraid-connections/events.php --since=0 --limit=3` on the test server, or Tools → Connected Clients → Event log.

## Shipped this session

- Forgejo backlog set up: labels `type/*`, `status/*`, `priority/*`; epic #2 with features #3–#6 (closed, shipped), plus #7 (VM console clients), #8 (sign out all other web sessions), #9 (Storefront release, blocked on the owner). Issue #1 (terminal clients) labelled.
- Phase 7 (`docs/specs/EVENTS_AND_SUBSCRIPTIONS.md`): event ledger (`src/include/cc-ledger.php`), subscriptions with notify/syslog/script sinks (`src/include/cc-subs.php`), pull endpoint `src/events.php` (web and CLI), nchan channel `connections_events`, settings section `#cc-subs`, Event log panel `#cc-ledger`, smoke steps 20–21, PHPUnit `LedgerTest` and `SubsTest`, L4 tests.
- v2026.09.15.02 (Forgejo #10): `src/test.php` answers the Test buttons as JSON; no page reload, so the Unraid pop-up stays visible. The owner confirmed the pop-up works with a second tab open.
- v2026.09.15.03 (Forgejo #11): settings page redone per `docs/specs/SETTINGS_PAGE_DESIGN.md` (cards in the Tools-page look, one list of alerts, collapsed rule editor, `assets/cc-settings.css`). v2026.09.15.04 (#12): the visual review found link buttons turned orange on hover (webGUI `button:hover`); fixed in `cc.css`.
- v2026.09.15.05 (Forgejo #13): the three default alerts are seeded once into `subscriptions.json` (`cc_subs_seed`, ids `def00001`–`def00003`, the old `notify_*` switches decide their first state) and are ordinary rules; `notify_new_client/failed/ssh_public` left `settings.ini`; Apply posts to `save.php` (`cc_settings_apply()` in `include/cc-settings.php`), so a refresh never re-posts; the last-fired column polls `state.php`; the Send a test notification button is gone.
- The detections always raise their ledger event since v2026.09.15.05; the default rule decides the notification (the earlier switch-gating decision is reversed).

## Next logical step

1. Ask the owner about the Storefront push (#9): dry run `CI_LOCAL_EXEC=1 ci/storefront --plugin=connections`, then `--push` only on a go-ahead.
2. Then #7 (VM console clients) or #1 (terminal clients), whichever the owner prefers.

## Gotchas

- A multipart/form-data POST to a plugin endpoint through the webGUI never gets an answer (verified with a headless browser 2026-09-15). Send URL-encoded bodies only.

- The release-gate artifact carries a digest of the working tree. Any edit after the gate (even the tarball the publish regenerates) makes `ci/publish` fail with exit 36; re-run the gate right before the publish, or chain them in one command.
- Issue dependencies are turned off on the Forgejo repo (POST .../dependencies returns 404). The epic's task list carries the parent-child links.
- PHPStan in a one-off container needs `--memory-limit=512M` or more; the CI runner uses 1G.
- Workers can run PHPUnit outside the CI runner: mount `/mnt/appdata/runner_connections/vendor` read-only at `/work/vendor` in `aicli-ci/php:8.4-cli`.

## Read before editing

`CLAUDE.md`, `docs/specs/CONNECTED_CLIENTS.md` (delivery plan), `docs/specs/EVENTS_AND_SUBSCRIPTIONS.md`, `docs/specs/TEST_SUITE.md`, `tests/smoke.sh` header (exit codes).

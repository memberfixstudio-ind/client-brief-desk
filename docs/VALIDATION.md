# Validation record — 1.0.0

Executed on 13 November 2026. All client accounts, answers and screenshots are fictional fixtures on disposable local WordPress installations. Outbound mail and external HTTP were blocked. No client site was modified.

## Executed checks

| Environment | Result |
| --- | --- |
| WordPress 6.8.3 · PHP 8.2.29 · MariaDB 13.0.2 | 65 integration assertions and 29 privacy-edge assertions passed |
| WordPress 7.1.4 · PHP 8.3.36 · MariaDB 13.0.2 | 65 integration assertions and 29 privacy-edge assertions passed |
| Installable release ZIP on a fresh WordPress 7.1.4 site, PHP 8.3.36, MariaDB 13.0.2 | Activated successfully; the same 65 integration and 29 privacy-edge assertions passed against the extracted runtime |
| Chrome · actual WordPress staff/client pages · plain permalinks | Complete browser journey passed |
| Chrome · extracted release ZIP · pretty permalinks | The same complete browser journey passed |
| Two independent PHP/database processes, current runtime | **8 concurrency checks passed**: second connection observed waiting on the same row; first save 200, stale save 409, one committed save event |
| Browser layout and runtime | Staff workspace and client dialog fit a 390px viewport; no JavaScript runtime errors observed |

Integration checks exercise two-client isolation, private staff-note filtering, required questions, strict inputs, unauthorized actions, stale saves/reviews/deletions, immutable submitted answers, approval snapshots, revision history, rollback after failed history inserts/commit, pagination, scoped counts and private API response headers. JSON history is bounded to the latest 50 caller-visible events and explicitly reports whether more exist.

Privacy-edge tests delete a real fixture WordPress account and verify that remaining briefs trigger manual-review notices rather than a false erasure-success claim. They exercise database failures, known-user export/erasure while orphaned records exist, retained snapshots and explicit administrator cleanup. Email lookup cannot identify records after the corresponding account was deleted; no separate email mapping is stored.

The browser journey creates a brief from a preset, selects a real existing client account, saves a staff note, logs in as the assigned client, saves an incomplete draft, rejects incomplete submission, opens two tabs, rejects a stale save and preserves its draft, submits, requests changes, revises, approves and downloads JSON. It also checks anonymous gating, cookie requests without REST nonces, private-note isolation and the exact answer snapshot approved.

Browser testing found and fixed query construction on plain-permalink sites. Display names containing an ampersand are decoded for text-only rendering. Screenshots are captures of these working interfaces, not mockups.

## Reproduce

Use a disposable single-site installation with InnoDB and `define('MFS_TESTS', true);` in wp-config.php. These tests create users and records, and privacy tests delete their fixture users. Never run them on a live client site.

```sh
wp eval-file /path/to/client-brief-desk/tests/integration.php
wp eval-file /path/to/client-brief-desk/tests/privacy-edge.php
# From the WordPress root, with wp on PATH:
bash /path/to/client-brief-desk/tests/concurrency.sh
wp eval-file /path/to/client-brief-desk/tests/fixture.php > /tmp/brief-fixture.json
```

For browser tests, start that local WordPress site, make its published pages available to logged-in subscribers, and set:

```sh
CBD_BASE=http://127.0.0.1:9423 \
CBD_FIXTURE=/tmp/brief-fixture.json \
CBD_USER=admin CBD_PASSWORD=your-disposable-password \
CBD_ARTIFACT_DIR=/tmp/brief-browser \
node tests/browser.cjs
```

The concurrency test requires the test database user to inspect `INFORMATION_SCHEMA.INNODB_TRX` to verify a real lock wait; that privilege is not a plugin runtime requirement. It ran on the current PHP 8.3 environment, not the PHP 8.2 baseline.

Install Playwright only in the test environment, or set `CBD_PLAYWRIGHT` to an existing module path and `CBD_CHROME` to an installed Chrome executable. The plugin has no production JavaScript package dependency. Browser requests are restricted to the configured local origin.

## Remaining validation

The [hosted MySQL 8 run 43467212959](https://github.com/memberfixstudio-ind/client-brief-desk/actions/runs/43467212959) passed for both PHP 8.2/WordPress 6.8.3 and PHP 8.3/WordPress 7.1.4: 65 integration, 29 privacy-edge and 8 concurrency assertions per environment. The release ZIP runtime is byte-for-byte identical to the runtime tested by this passing run.

The initial hosted run exposed a test-harness timing issue: polling `INNODB_TRX` every 50 ms could keep MySQL's cached lock information stale. A forced-delay reproduction on MySQL 8.0.35 failed with the old observer and passed after polling every 200 ms. The test still requires an observed lock wait, one successful save, one 409 conflict and exactly one save event. Only the test observer and its diagnostics changed; no plugin runtime or release asset was replaced.

No external penetration test, screen-reader study, multisite validation, large-data benchmark or long-running customer deployment is claimed. Browser coverage uses Chrome and a standard WordPress theme; third-party themes, caching plugins, proxies and hosting configurations need staging validation. This release does not include uploads, emails, payment processing or legal signatures.

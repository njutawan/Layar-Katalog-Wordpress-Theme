# Tests

Harnesses for the Layar Katalog theme and core plugin. They are development tooling and are **not** shipped to end users.

There is no build step and no framework: each harness is a single file that runs the shipped source and prints `PASS`/`FAIL` per assertion, exiting non-zero on the first failing suite.

## Quick start

```bash
# JavaScript (no dependencies needed for these three)
node lint-js.mjs                       # syntax-check every .js the project ships
node js/service-worker.test.js         # service worker cache rules
node js/phpcs-regression.test.js       # the comparator that makes the standards job blocking

# Bash (no dependencies)
bash wp/guards.test.sh                 # the staging scripts refuse dangerous invocations

# JavaScript DOM tests (needs jsdom)
npm ci                                 # inside tests/; a lock file is committed
node js/player-dom-xss.test.js

# PHP — either of these
php php/security.test.php              # if you have PHP 8.2+
node php/run-phpwasm.mjs 8.2           # if you do not: runs a real PHP 8.2 in WebAssembly
node php/lint-phpwasm.mjs 8.2          # parse-check every project PHP file without PHP installed
```

`npm test` inside `tests/` runs the JavaScript lint plus all three suites through the WebAssembly PHP path.

## What each suite covers

| Suite | Assertions | Covers |
|---|---|---|
| `php/security.test.php` | 254 | Origin normalisation, CORS allowlist, CSP construction and its resistance to a hostile `lkc_content_security_policy` filter, strict CSP mode (nonce generation and stamping, `'unsafe-inline'` removal, and a filter being unable to put it back), the CSP report grouping and the allowlist snippet generated from it — including `eval`ing that snippet to prove it parses and adds the right hosts — the personalisation cache hint, both rate-limit buckets, the request-body ceiling, the audit allowlist and write path, the CSP violation collector, media URL transport and host policy, stale-subscription pruning, VAPID private-key encryption at rest, and the theme filters. |
| `js/service-worker.test.js` | 18 | What the service worker is allowed to put in Cache Storage, which requests it ignores, `notificationclick` navigation, and cache eviction on `activate`. |
| `js/player-dom-xss.test.js` | 22 | That a hostile episode title stays text, that `javascript:` and `data:` navigation targets are refused, and that switching video source keeps exactly one copy of each subtitle track. |
| `js/phpcs-regression.test.js` | 22 | The comparator behind the blocking coding-standards job: line-number drift is not a new violation, a repeated violation is counted per occurrence, warnings are not errors, a deleted file counts as debt removed, and bad input exits 2 instead of passing silently. |
| `wp/guards.test.sh` | 24 | The two staging scripts abort before touching anything when the target database is the live one, when the name contains shell metacharacters, when the drop was not confirmed by name, or when the plugin would be deactivated without `--activate`. Every case asserts no `mysql` command was issued. |
| `php/lint-phpwasm.mjs` | per file | Every `.php` under the project (excluding `vendor/`) parses. This is the check that found a fatal parse error in the plugin's main file — see finding F-15 in [`../docs/SECURITY-AUDIT.md`](../docs/SECURITY-AUDIT.md). |

## Staging scripts

Two checks cannot be made by a unit test, because they are about a running WordPress rather than about the code: that the plugin really activates, and that a backup really restores. `wp/` holds the scripts for both, and they are meant to be run by hand against a staging site.

```bash
# from the WordPress root
bash tests/wp/smoke-activate.sh                 # verify the current install
bash tests/wp/smoke-activate.sh --activate      # force a deactivate/activate cycle first
bash tests/wp/rehearse-restore.sh --target-db=lkc_drill_2026q4 --yes-drop=lkc_drill_2026q4
```

`smoke-activate.sh` checks activation, the four plugin tables, the `failure_count` migration, the six REST routes, the three cron events, that the stored VAPID key is an encrypted envelope the current salts can still decrypt, that `vendor/` is not web-reachable, that the security headers actually arrive, that the write rate limiter returns 429, and that `debug.log` stayed quiet about this plugin.

`rehearse-restore.sh` performs the quarterly drill from [`../docs/BACKUP-RESTORE.md`](../docs/BACKUP-RESTORE.md) and prints the log row for it. It drops and recreates a scratch database, so it refuses to run unless the target name is given twice — once as `--target-db`, once as `--yes-drop` — and it aborts outright if that name is the live database. `guards.test.sh` runs those refusals in CI with a stubbed `wp` and `mysql`.

Neither script is part of `npm test`; both need a real WordPress install.

## Differential mode

Both JavaScript suites accept `--previous`, which loads the version of the file from `git HEAD` instead of the working tree. That is what turns them into evidence rather than a green tick:

```bash
node js/player-dom-xss.test.js --previous     # 8 failures: live <img>, javascript: accepted, tracks duplicated
node js/player-dom-xss.test.js                # 22 passes
node js/service-worker.test.js --previous     # 9 failures: cached signed-in HTML, previews, 404s, opened evil.test
node js/service-worker.test.js                # 18 passes
```

## How the PHP suite runs without WordPress

`php/bootstrap.php` declares the WordPress functions the tested code actually calls — a real hook registry so `apply_filters` works, a `FakeWpdb` that records every write and answers the handful of reads, and transients, salts, and URL helpers. It then loads the shipped plugin files **unchanged**.

The theme's `functions.php` and the plugin's main file run a lot of WordPress registration code at load time, so `bootstrap.php` extracts the individual functions under test verbatim (brace-matched, then `eval`'d) rather than stubbing the whole registration surface. The assertions still run against the shipped source text: rename a function in the plugin and these tests break loudly.

## Why WebAssembly PHP

`run-phpwasm.mjs` and `lint-phpwasm.mjs` boot a real PHP 8.2 build compiled to WebAssembly (`@php-wasm/node`), so a contributor without PHP installed — and a reviewer without a staging site — can still run the full suite. CI uses a native PHP 8.2 install, which is faster; the WebAssembly path exists for local use and is byte-for-byte the same interpreter.

## Adding a test

1. Put PHP assertions in `php/security.test.php` using `t_section()` and `t_assert( $label, $condition, $detail )`. The third argument is printed on failure — always pass it for anything that can produce a surprising value.
2. If the code under test needs a WordPress function that is not stubbed yet, add the stub to `php/bootstrap.php` next to the others.
3. For JavaScript, copy the structure of an existing suite: load the shipped file into a sandbox, drive it, assert, then set `process.exitCode`.
4. Never assert on a mock of the thing you are testing. If a function has to be extracted to be testable, extract it in the plugin (that is why `lkc_is_allowed_origin()` and `lkc_rest_rate_limit_buckets()` exist) rather than reimplementing it in the test.

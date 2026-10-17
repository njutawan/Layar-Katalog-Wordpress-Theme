# Tests

Harnesses for the Layar Katalog theme and core plugin. They are development tooling and are **not** shipped to end users.

There is no build step and no framework: each harness is a single file that runs the shipped source and prints `PASS`/`FAIL` per assertion, exiting non-zero on the first failing suite.

## Quick start

```bash
# JavaScript (no dependencies needed for these four)
node lint-js.mjs                       # syntax-check every .js the project ships
node js/service-worker.test.js         # service worker cache rules
node js/csp-audit.test.js              # the CSP pre-flight auditor
node js/phpcs-regression.test.js       # the comparator that makes the standards job blocking

# Bash (no dependencies)
bash wp/guards.test.sh                 # the staging scripts refuse dangerous invocations
bash wp/simulated-install.test.sh      # and their checks work, against a simulated WordPress

# JavaScript DOM tests and the WebAssembly PHP tools (needs jsdom and @php-wasm/node)
npm ci                                 # inside tests/; a lock file is committed
node js/player-dom-xss.test.js
node js/csp-snippet-review.test.js     # the mu-plugin snippet reviewer
node js/csp-strict-roundtrip.test.js   # the strict header judged against the theme's own markup

# PHP — either of these
php php/security.test.php              # if you have PHP 8.2+
php php/activation.test.php            # what activation registers
node php/run-phpwasm.mjs 8.2                            # if you do not: real PHP 8.2 in WebAssembly
node php/run-phpwasm.mjs 8.2 tests/php/activation.test.php
node php/run-phpwasm.mjs 8.2 tests/php/csp-strict.test.php   # what strict mode really emits
node php/lint-phpwasm.mjs 8.2          # parse-check every project PHP file without PHP installed
```

`npm test` inside `tests/` runs the JavaScript lint and every suite above, using the WebAssembly path for PHP.

## What each suite covers

| Suite | Assertions | Covers |
|---|---|---|
| `php/security.test.php` | 254 | Origin normalisation, CORS allowlist, CSP construction and its resistance to a hostile `lkc_content_security_policy` filter, strict CSP mode (nonce generation and stamping, `'unsafe-inline'` removal, and a filter being unable to put it back), the CSP report grouping and the allowlist snippet generated from it — including `eval`ing that snippet to prove it parses and adds the right hosts — the personalisation cache hint, both rate-limit buckets, the request-body ceiling, the audit allowlist and write path, the CSP violation collector, media URL transport and host policy, stale-subscription pruning, VAPID private-key encryption at rest, and the theme filters. |
| `php/activation.test.php` | 81 | Everything activation registers, asserted against the shipped source rather than a running site: the callback-integrity scan (every `add_action`, `add_filter`, `add_shortcode`, `register_activation_hook` and every route/meta `callback` in the six registration files names a function that really exists — the defect class F-15 belonged to), the arguments `lkc_activate()` passes, both post types and the taxonomy, all 24 catalog meta keys with their `show_in_rest`, `sanitize_callback`, `auth_callback` and `single` flags, the six `lkc/v1` routes including the fact that reading a rating is public while writing one is capability-gated, and the front-end asset registrations (plugin-origin URL, `filemtime` cache-buster, footer, no plain `http://`). |
| `js/service-worker.test.js` | 18 | What the service worker is allowed to put in Cache Storage, which requests it ignores, `notificationclick` navigation, and cache eviction on `activate`. |
| `js/csp-audit.test.js` | 59 | The auditor that answers "would enforce mode break this page?": policy parsing, the `-elem`/`-attr` → parent → `default-src` fallback chain, host wildcards and ports, `'self'`, scheme sources, the CSP2 trap where a nonce makes the browser ignore `'unsafe-inline'`, the fact that no nonce can cover a `style=` attribute or an `onclick=` handler, the HTML scanner over a realistic page fixture, the generated allowlist snippet, and the CLI's exit codes. |
| `js/player-dom-xss.test.js` | 22 | That a hostile episode title stays text, that `javascript:` and `data:` navigation targets are refused, and that switching video source keeps exactly one copy of each subtitle track. |
| `js/csp-snippet-review.test.js` | 105 | The reviewer that judges a CSP snippet before it is pasted into `mu-plugins`: the static refusals (filesystem, network, shell, `eval`, superglobals, `$wpdb`, a closing tag, a wholesale `$policy =`), the structural ones (no `<?php`, no `add_filter`, a tag the plugin never applies, a missing `return $policy`), the diff of the header the plugin would really send, and one end-to-end run of all eight fixtures through a real PHP 8.2 — including a snippet that survives report-only mode and only shows its hand under `lkc_csp_strict`. |
| `php/csp-strict.test.php` | 45 | What strict mode really sends, read off the shipped source: the nonce exists, is stable within a request and uses only the URL-safe base64 alphabet; `'unsafe-inline'` and `'unsafe-eval'` are gone from `script-src` and `style-src` while every other directive survives; the nonce lands in both; `style-src-attr 'unsafe-inline'` is the single concession and `script-src-attr` is not loosened; `lkc_csp_strict_style_attributes` → `false` removes exactly that and nothing else; an allowlist snippet adds its host without dragging `'unsafe-inline'` back; the header is delivered report-only until `lkc_csp_mode` changes; no header at all is computed for `wp-admin`; and the theme's own markup — ad slot, administrator ad loader — comes out nonce'd with no inline style attribute anywhere. Emits the header and the document as JSON for the round trip below. |
| `js/csp-strict-roundtrip.test.js` | 20 | That header, judged against that document. Boots the sandbox, parses the emitted markup with jsdom and runs the CSP matcher from `js/csp-audit.mjs` over it: everything the theme and plugin print is allowed; the administrator's external ad loader is blocked until the generated snippet allowlists its host; an inline `onclick` handler is blocked in strict mode yet still runs under the default report-only header; a page-builder style attribute survives only because of the concession and is blocked the moment it is switched off. |
| `js/phpcs-regression.test.js` | 22 | The comparator behind the blocking coding-standards job: line-number drift is not a new violation, a repeated violation is counted per occurrence, warnings are not errors, a deleted file counts as debt removed, and bad input exits 2 instead of passing silently. |
| `wp/simulated-install.test.sh` | 118 | Both staging scripts, run end to end against a WordPress that is not there: a fake `wp`, `mysql`, `curl` and `php -l` answer every call they make. First healthy (the smoke test's own 37 checks all fire and the drill prints its log row), then degraded one thing at a time — a missing table, an unscheduled cron job, an unregistered route, a plaintext or orphaned VAPID key, no CSP header, no HSTS, a reachable `vendor/`, a rate limiter that never answers 429 or answers it too late, an inactive plugin or theme, a root `index.php` in the block theme, a file that does not parse, a fatal during the activation cycle, notices in `debug.log`, an incomplete restore, rotated salts, a backup missing salt definitions — asserting each time that the right check is blamed and no other one is. Also asserts the drill never points `mysql` at the live database, always uses the 0600 credentials file, and honours `--activate`, `--skip-http`, `--skip-rate-limit`, `--verify-only`, `--keep` and `--keep-dump`. |
| `wp/guards.test.sh` | 24 | The two staging scripts abort before touching anything when the target database is the live one, when the name contains shell metacharacters, when the drop was not confirmed by name, or when the plugin would be deactivated without `--activate`. Every case asserts no `mysql` command was issued. |
| `php/lint-phpwasm.mjs` | per file | Every `.php` under the project (excluding `vendor/`) parses. This is the check that found a fatal parse error in the plugin's main file — see finding F-15 in [`../docs/SECURITY-AUDIT.md`](../docs/SECURITY-AUDIT.md). |

## Auditing a page before switching the CSP to enforce

`js/csp-audit.mjs` is a tool as well as a tested module. The plugin ships its policy in report-only mode, and the admin screen at **Tools → Security Log → CSP** turns the violations real browsers reported into a ready-to-paste allowlist. That loop only sees pages somebody actually visited. This tool closes the other half: point it at a page and it tells you, before you change anything, which resources enforce mode would take away.

```bash
# against a live or staging URL, using the header that site really sends
node js/csp-audit.mjs --url=https://staging.example/ --url=https://staging.example/katalog/

# as if lkc_csp_strict were already on, which is the state you are moving towards
node js/csp-audit.mjs --url=https://staging.example/ --strict

# against a saved page: --document-url is what makes 'self' resolve
node js/csp-audit.mjs --html=page.html --document-url=https://staging.example/

# test a policy you are considering before you put it in a filter
node js/csp-audit.mjs --html=page.html --document-url=https://staging.example/ \
    --policy="default-src 'self'; script-src 'self' https://pagead2.googlesyndication.com"
```

It exits 0 when nothing would be blocked and 1 when something would, so it can gate a deploy. `--format=json` gives the same report as data; `--quiet` prints only the pages that would break.

What it reports, and what each line means:

- **Blocked hosts, grouped by directive**, with the line number in the markup. These go into `lkc_content_security_policy` — the tool prints the filter for you, one line per host, in the same shape the admin screen generates.
- **Inline `<script>` and `<style>` elements.** Not allowlistable, and the report says so: `lkc_csp_strict` nonces them, which is the fix.
- **`style="…"` attributes and `onclick=` handlers.** No nonce can cover an attribute — this is exactly why finding F-17 moved an inline style into the stylesheet. Move the handler into a script, or accept `'unsafe-hashes'` plus the hash the tool prints.
- **Plain `http://` resources**, which an HTTPS page loses to mixed-content blocking before the policy is even consulted.
- **`javascript:` and `data:` URLs in markup**, flagged whatever the policy says.

It is a scanner, not a browser: it cannot see what a third-party script injects at runtime, and a page it clears can still produce violations. Use it to shorten the report-only phase and to check a policy change in advance, and treat the CSP tab as the authority.

## Reviewing a snippet before it goes into mu-plugins

Step 3 of switching the CSP to enforce mode is "paste the generated filter into `wp-content/mu-plugins/` and check every line". `csp-snippet-review.mjs` does that check, and it does it by running the snippet:

```bash
node csp-snippet-review.mjs wp-content/mu-plugins/csp-allowlist.php
node csp-snippet-review.mjs snippet-a.php snippet-b.php     # one PHP boot for all of them
node csp-snippet-review.mjs snippet.php --json              # the diff as data
```

It boots a real PHP 8.2 in WebAssembly, loads the plugin's own `security.php`, applies the snippet through the filter the plugin actually applies, and prints the header the site would then send — with the added and removed sources listed per directive. On top of that diff it reports:

| Verdict | Exit | Meaning |
|---|---|---|
| `SAFE TO PASTE` | 0 | It only adds sources, nothing was removed, and the header still builds. |
| `NEEDS A HUMAN DECISION` | 1 | Nothing is broken, but something is not a plain allowlist entry: a wildcard host, a plain-`http` source, a removed source, a closing `?>`, an `add_action`. |
| `DO NOT PASTE` | 2 | Broken or weakening. |

What it refuses outright, all of which have been seen in real snippets:

- **A filter registered on the wrong tag.** The admin screen generates `lkc_content_security_policy`; a typo produces a file that loads cleanly and does nothing at all, and the operator believes the allowlist is in place.
- **A missing `return $policy;`.** The filter returns `null`, the policy collapses from thirteen directives to zero, and the site sends **no CSP header at all** — a strictly worse state than before the snippet was added.
- **An unquoted keyword.** `$policy['style-src'] = array( 'self', '*' );` sends `style-src self *`, because in PHP the quotes belong inside the string. A browser then reads a host named `self` plus every host on earth.
- **A wildcard source** (`*`, `*://cdn.test`), which allows every host.
- **Anything that defeats strict mode.** A filter on `lkc_csp_directives` runs *after* `lkc_apply_csp_strict_mode()`, so it can put `'unsafe-inline'` back behind the nonce. The reviewer re-evaluates every snippet with `lkc_csp_strict` on and refuses one that survives that.
- **Constructs that have no business in a policy snippet**: filesystem, network, shell, `eval`, `include`, `base64_decode`, superglobals, `$wpdb`, `ini_set`, `extract()`, reflection.

The fixtures in `js/fixtures/snippets/` are one file per case and are stored with a `.php.txt` extension on purpose: CI runs `php -l` over every `*.php` in the repository, and one fixture is deliberately unparsable. Comments are blanked before scanning, so a docblock that says "never call `eval()` here" is not reported as a call to `eval()`.

The snippet is executed, so only run this on a file you would run. It executes inside the WebAssembly sandbox: no host filesystem beyond the mirrored repository, no database, no network.

## Strict mode without a browser

`lkc_csp_strict` is the switch that turns a report-only policy into a blocking one, and getting it wrong is not a test failure — it is a site whose scripts stop running. Two suites cover as much of that as can be covered without a browser, and they are deliberately wired together: the PHP one emits, the JavaScript one judges.

```bash
node php/run-phpwasm.mjs 8.2 tests/php/csp-strict.test.php   # 45 assertions: what the plugin emits
node js/csp-strict-roundtrip.test.js                         # 20 assertions: whether the markup survives it
```

The emitter boots the shipped plugin *and* theme in the WebAssembly sandbox, turns strict mode on, and prints one line of JSON: the header the plugin would really send (four variants — default, strict, strict with an allowlist snippet applied, strict with the style-attribute concession switched off) and a document built from the markup the theme really prints. That document is not a fixture somebody wrote to pass: it is the output of `layarkatalog_ad_slot_shortcode()` and `layarkatalog_output_global_ad_code()` with an administrator's ad code in the options table, plus two controls that the theme does not produce — a `style=` attribute and an `onclick=` handler, the way a page builder would leave them.

The round trip then does what a browser does, with the same matcher the pre-flight auditor uses: parse the document, work out what each element needs, and ask the header. Run it after touching `includes/security.php`, the theme's inline output, or the stamping helpers. What it cannot see is markup from plugins that are not in this repository, core's own block output on a real page, or a cache or proxy in front of the site — which is why step 2 of going live is still a report-only week on staging.

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

Neither script is part of `npm test`; both need a real WordPress install to do their actual job.

What *can* be tested without one is the scripts themselves, and `wp/simulated-install.test.sh` does that. It builds a simulated install in a temporary directory — a plugin and theme tree, a `debug.log`, two `wp-config.php` files whose salts can be made to disagree — and puts a fake `wp`, `mysql`, `curl` and `php` on the `PATH`. Every answer comes from a `SIM_*` variable with a healthy default, so each test degrades exactly one thing:

```bash
bash wp/simulated-install.test.sh          # 118 assertions, about ten seconds
```

The stubs encode what WP-CLI, `mysql` and `curl` are documented to output. That proves the scripts' logic — parsing, thresholds, ordering, which check blames which problem, what they refuse to touch — and it cannot prove a real site behaves like the simulation. Reading the two together: the guards say the scripts will not hurt anything, the simulation says they will actually notice something is wrong, and running them on staging is still the only thing that says the site is fine.

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

1. Put PHP assertions in `php/security.test.php` using `t_section()` and `t_assert( $label, $condition, $detail )`. The third argument is printed on failure — always pass it for anything that can produce a surprising value. Assertions about *what a hook or route registers* belong in `php/activation.test.php` instead; if you add a file that registers hooks, add its path to `t_registration_files()` there so the callback-integrity scan covers it.
2. If the code under test needs a WordPress function that is not stubbed yet, add the stub to `php/bootstrap.php` next to the others.
3. For JavaScript, copy the structure of an existing suite: load the shipped file into a sandbox, drive it, assert, then set `process.exitCode`.
4. Never assert on a mock of the thing you are testing. If a function has to be extracted to be testable, extract it in the plugin (that is why `lkc_is_allowed_origin()` and `lkc_rest_rate_limit_buckets()` exist) rather than reimplementing it in the test.

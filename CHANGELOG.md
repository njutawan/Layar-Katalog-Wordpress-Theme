# Changelog

## Theme 1.7.0 / Core Plugin 1.7.0

The items the security review could not close from inside the repository — dropping `'unsafe-inline'`, enforcing the policy, making the coding-standards job blocking, rehearsing a restore, and testing activation on a staging site — now have the code and the tooling that make them executable. Nothing changes for a site that does nothing: every part of this release is opt-in.

### Added

- **A strict Content-Security-Policy mode.** `add_filter( 'lkc_csp_strict', '__return_true' );` drops `'unsafe-inline'` and `'unsafe-eval'` from `script-src`, `style-src` and `default-src` and puts a per-request nonce in their place. The nonce is stamped on every inline `<script>` and `<style>` WordPress prints, through `wp_script_attributes` and `wp_style_attributes`, so core's own inline output — global styles, emoji settings, localised script data — is covered without any plugin having to know the policy exists. Strict mode is applied *after* user filters, so a filter cannot smuggle `'unsafe-inline'` back in.
- **Nonce support for administrator-supplied advertising code.** Ad code is echoed as raw markup, so it never passes through WordPress's script API and would be blocked outright by a strict policy. `layarkatalog_stamp_csp_nonce()` now adds the request nonce to its inline `<script>` and `<style>` tags, leaving external loaders untouched.
- **Tools → Log Keamanan → CSP writes the allowlist for you.** The tab turns the stored violation reports into a ready-to-paste `add_filter( 'lkc_content_security_policy', … )` snippet. Hosts the current policy already permits — including through the blanket `https:` on `img-src` and `media-src` — are skipped, the site's own host is annotated as an inline violation instead of being offered as a domain to allow, and the `inline`, `eval` and `data` keywords are translated into the fix that actually resolves them, since none of the three can be allowlisted. The generated snippet is executed by the test suite, so it is proven to parse and to do what it says.
- **The coding-standards CI job is blocking.** It scans this revision and its base with the same ruleset and fails only on violations the change introduces, so new and edited code has to be clean while the pre-existing debt is worked down at its own pace. `tests/phpcs-regression.mjs` matches violations by sniff name and message rather than by line number, so reformatting a file does not make everything below the edit look new, and it reports how much debt a change removed.
- **`tests/wp/smoke-activate.sh`** — activation smoke test for a staging site, covering what unit tests cannot: every shipped PHP file parsing under the server's own PHP, the plugin and theme being active, all four plugin tables existing, the `failure_count` migration having run, the six REST routes being registered, the three cron events being scheduled, the stored VAPID key being an encrypted envelope that the current salts can still decrypt, `vendor/` not being web-reachable, the security headers actually arriving on a front-end response, the write rate limiter returning 429, and `debug.log` staying quiet about this plugin.
- **`tests/wp/rehearse-restore.sh`** — automates the quarterly drill from [`docs/BACKUP-RESTORE.md`](docs/BACKUP-RESTORE.md): dump, restore into a scratch database, verify that every table, option, theme mod, cron hook and catalog row the project owns survived, check that the restored VAPID envelope is byte-identical to the live one, compare the salt lines of a backed-up `wp-config.php` against the live file, and print the log row for the runbook. It never writes to the live database.
- **`tests/wp/guards.test.sh`** — proves those two scripts refuse the dangerous invocations: restoring over the live database, a target name containing shell metacharacters, a drop that was not confirmed by name, and deactivating the plugin without `--activate`. Every case asserts that no `mysql` command was issued at all.

### Changed

- The hero column widths moved from inline `style="flex-basis:58%"` attributes in `templates/front-page.html` to `.wp-block-column.hero-copy` and `.wp-block-column.hero-side` rules in `style.css`. Inline style attributes cannot carry a nonce and hashes do not apply to them, so they were the last thing tying the front page to `'unsafe-inline'`. Rendering is unchanged, including the mobile stacking that core forces with `!important`, and the block editor keeps showing the same 58/42 split because the theme already loads this stylesheet with `add_editor_style( 'style.css' )`.
- The `wp_footer` script that gives `<main>` its `id` is now built as a string rather than printed from a template block, so the same nonce stamping reaches it.
- `lkc_get_content_security_policy()` was split into `lkc_get_csp_directives()`, which returns the directive array, and `lkc_stringify_csp_directives()`, which renders it. A new `lkc_csp_directives` filter runs last, for a final look at what will actually be sent. The value the existing filter sees and the header the site sends are unchanged.
- In strict mode every front-end response is marked personalised (`X-LK-Personalized: 1`), because HTML carrying a per-request nonce must not be served from a cache to the next visitor. The bundled service worker already refuses to store such responses.

### New filters

`lkc_csp_strict`, `lkc_csp_nonce`, `lkc_csp_directives`.

### Notes for site owners

- Strict mode and full-page caching are incompatible unless the cache varies on the nonce. Either keep HTML out of the cache while `lkc_csp_strict` is on, or supply a stable nonce through `lkc_csp_nonce` — derived, for example, from a cookie the cache already varies on.
- Turn strict mode on while `lkc_csp_mode` is still `report-only`, browse the whole site, and read the CSP tab. Switch to `enforce` only once a normal week of reports is empty apart from sources you deliberately refused.
- A third-party plugin that echoes a raw `<script>` tag will be blocked in strict mode: it does not go through WordPress's script API, so it never receives the nonce. Finding those is exactly what the report-only phase is for.

## Theme 1.6.4 / Core Plugin 1.6.0

Third and final remediation round from the 20-point security review in [`docs/SECURITY-AUDIT.md`](docs/SECURITY-AUDIT.md): the P2 items, plus the test harnesses that make the whole review enforceable.

### Fixed

- **Switching a video source duplicated the subtitle tracks.** Assigning `video.src` does not remove `<track>` children, so the "preserve subtitles" step appended a second copy of every track on each switch and the subtitle menu grew one entry at a time. The originals are now removed before being re-added. Found by the new DOM test suite.

### Security

- **Encrypted the VAPID private key at rest.** It was stored as plaintext in `wp_options`, so a database dump alone was enough to sign push notifications as the site. Values are now stored as an AES-256-GCM envelope keyed from the `auth` and `secure_auth` salts in `wp-config.php`. A dump without `wp-config.php` is no longer usable, and rotating the salts surfaces an admin notice instead of failing silently. Keys written by earlier releases are migrated on the next `admin_init` and remain readable throughout.
- **Refused plain-HTTP video and subtitle URLs.** On an HTTPS site the browser blocks them as mixed content anyway, so accepting them only produced a player that silently failed. Opt a genuinely non-TLS source back in with `lkc_allow_insecure_media_urls`, and pin media to your own storage or CDN with `lkc_allowed_media_hosts` (subdomains of a listed host are accepted, look-alike prefixes are not). Both sanitizers run on save, so URLs stored before this change keep working until an editor touches them.
- **Pruned push subscriptions that can never be delivered.** Delivery failures are now counted per subscription (`failure_count`, `last_failure_at`), and a daily cron deletes rows at 5 failures or 30 days of failing — configurable with `lkc_push_max_failures` and `lkc_push_stale_days`. Rows that have never failed are kept, so a subscriber waiting for the next episode is never dropped. Pruning is written to the audit log.
- **Blocked direct web access to `vendor/`.** `installed.json` and `composer.lock` were publicly readable and disclose exact dependency versions, which tells an attacker which advisories to try. Added an Apache deny rule inside `vendor/` (with the nginx equivalent documented) and `index.php` guards in every plugin and theme subdirectory so listings cannot be browsed.

### Added

- A test suite in [`tests/`](tests/README.md) and a GitHub Actions workflow that runs it on every push and pull request: `php -l` over every PHP file, `node --check` over every JavaScript file, 152 PHP assertions against the shipped security logic with WordPress stubbed, 18 service-worker cache-rule assertions, 22 player DOM assertions, `composer audit` over the bundled lock file, and an advisory PHPCS/WPCS report. The JavaScript suites accept `--previous` to run the same assertions against `git HEAD`, which is how the fixes stay demonstrable rather than merely claimed.
- PHP harnesses that run a real PHP 8.2 compiled to WebAssembly (`tests/php/run-phpwasm.mjs`, `tests/php/lint-phpwasm.mjs`), so the suite runs on a machine with no PHP installed.
- `phpcs.xml.dist` — WordPress standards ruleset scoped to the theme, plugin, and PHP tests.
- [`docs/BACKUP-RESTORE.md`](docs/BACKUP-RESTORE.md) — what to back up (all four plugin tables, the ad-code option, and the `wp-config.php` salts the VAPID key now depends on), how to restore, the five failure modes that actually bite, a post-restore verification checklist, and a quarterly rehearsal log.

### New filters

`lkc_allow_insecure_media_urls`, `lkc_allowed_media_hosts`, `lkc_push_max_failures`, `lkc_push_stale_days`.

### Notes for site owners

- If the admin shows a notice that the VAPID key cannot be decrypted, the salts in `wp-config.php` changed. Regenerate the keys under **Settings → Episode Notifications**; subscribers will need to opt in again.
- Backups taken from now on must include `wp-config.php` (or at least its salt lines) or the push keys cannot be restored.
- nginx users must add the documented `location` block; `.htaccess` is ignored there.

## Theme 1.6.3 / Core Plugin 1.5.0

Second remediation round from the 20-point security review in [`docs/SECURITY-AUDIT.md`](docs/SECURITY-AUDIT.md): the P1 items (CORS, service worker caching, Content-Security-Policy, input validation, audit trail).

### Fixed

- **Fatal parse error in the main plugin file.** In `lkc_register_meta()` a `foreach ( $title_meta as $meta_key => $args ) {` line had been replaced by `$episode_meta = array(`, which is a syntax error in PHP. The plugin could not be activated at all, and none of the title meta fields were ever registered with `show_in_rest`. Found by parsing every project file with PHP 8.2; the whole plugin and theme now parse cleanly.

### Security

- **Locked the REST CORS policy to an allowlist.** WordPress core reflects any `Origin` back as `Access-Control-Allow-Origin` together with `Access-Control-Allow-Credentials: true`, which let any website read credentialed responses from `lkc/v1` — including a signed-in user's ratings. Origins are now compared after normalisation against `lkc_allowed_cors_origins` (default: this site's own origin); everything else loses those headers entirely. The policy is applied on both `rest_pre_serve_request` and `rest_post_dispatch` so it holds across WordPress versions, and `Vary: Origin` is always sent so a shared cache cannot hand one origin's headers to another.
- **Added a Content-Security-Policy in report-only mode** with a collector. `default-src 'self'`, `object-src 'none'`, `frame-ancestors 'self'`, `base-uri 'self'`, `form-action 'self'`, plus `https:`/`data:`/`blob:` where the player and artwork need them. Reports land on the new anonymous `POST lkc/v1/csp-report` route, which folds them into one row per (directive, blocked source, document path) and stops creating rows at 500 fingerprints. Nothing is blocked yet: `'unsafe-inline'` is still required while the theme prints administrator-supplied ad code into `wp_head`. Read **Tools → Log Keamanan**, allowlist the real domains through `lkc_content_security_policy`, then switch to blocking with `lkc_csp_mode`.
- **Stopped the service worker from caching pages that must not be shared.** It previously wrote every same-origin navigation to Cache Storage and served it back offline, so a signed-in header, a post preview, or an error page could be handed to the next person using the device. Cache writes now require a successful, same-origin (`basic`) response that carries neither `X-LK-Personalized` nor `Cache-Control: private`/`no-store`, and preview and Customizer URLs are excluded outright. `activate` purges older `lk-*` caches so the personalised HTML already sitting in visitors' caches is discarded on upgrade, and `notificationclick` only opens same-origin URLs.
- **Added a security audit trail.** Setting changes, VAPID key rotation, subscription clearing, rejected anonymous subscriptions, and advertising-code edits are written to a new `wp_lkc_audit_log` table and shown under **Tools → Log Keamanan**. The client address and user agent are stored as salted HMAC-SHA256 hashes rather than raw values, and only the affected ad slot names are recorded, never the ad code. Recorded actions are controlled by the `lkc_audit_log_actions` allowlist; volume-heavy events stay off by default. A daily cron prunes audit entries after 90 days and unseen violation reports after 30.
- **Validated the anonymous push routes.** `/push/subscribe` and `/push/unsubscribe` now declare an `args` schema (endpoint, keys, content encoding) instead of reading unsanitised parameters, and rejections are written to the audit log so an abuse pattern is visible.
- **Capped REST request bodies.** Writes to `lkc/v1` larger than `lkc_rest_max_body_bytes` (8 KB by default) are answered with `413` before WordPress parses them. The web server remains the authoritative limit.
- Exempted the CSP report route from rate limiting: browsers post one report per blocked resource, so a single page view can legitimately produce a burst. The route is bounded by its own row ceiling instead.
- The theme now adds `fonts.googleapis.com` and `fonts.gstatic.com` to the policy automatically, but only when a Google Font is actually selected in the Customizer.

### Added

- `includes/audit.php` — audit log table, violation-report collector, retention cron, and the **Tools → Log Keamanan** screen with Audit and CSP tabs.
- `X-LK-Personalized: 1` response header, sent on signed-in and preview responses as the service worker's "do not cache" signal.
- New helpers: `lkc_client_ip()`, `lkc_is_allowed_origin()`, `lkc_normalize_origin()`, `lkc_get_content_security_policy()`, `lkc_get_content_security_policy_header()`, `lkc_is_personalized_response()`, `lkc_rest_rate_limit_buckets()`, `lkc_is_audited_action()`, `lkc_security_table_exists()`.

### New filters

`lkc_content_security_policy`, `lkc_csp_mode`, `lkc_allowed_cors_origins`, `lkc_rest_max_body_bytes`, `lkc_audit_log_actions`, `lkc_csp_report_max_rows`, `lkc_audit_log_retention_days`, `lkc_csp_report_retention_days`.

## Theme 1.6.2 / Core Plugin 1.4.1

Follow-up to the 20-point security review in [`docs/SECURITY-AUDIT.md`](docs/SECURITY-AUDIT.md).

### Security

- Fixed a DOM-based XSS in the autoplay overlay. The next episode's title was read with `textContent` and then interpolated into `innerHTML`, so an author-level account could run script in every visitor's browser, including administrators. The overlay is now built with DOM APIs and `textContent`, and only `http(s)` targets are navigated to.
- Stopped leaking push subscription endpoints into the debug log. Guzzle exception messages embed the full request URL, and that URL is a per-browser secret. Failures now record only the stage, exception class, HTTP status, and an 8-character hash prefix.
- Added baseline front-end security headers: `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`, and HSTS when the site is served over HTTPS. Filterable through `lkc_security_headers`. Content-Security-Policy is intentionally not enforced yet because the theme prints administrator-supplied ad code into `wp_head`; roll it out in report-only mode first.
- Added per-visitor rate limiting for every `lkc/v1` REST route, which WordPress core does not do. Defaults are 60 reads and 10 writes per minute, answered with `429` and `Retry-After`. Configure with `lkc_rest_rate_limits`; sites behind a proxy must map the real client address with `lkc_rate_limit_ip`. Anonymous visitors are keyed by a salted hash of their IP, so no raw address is stored.
- Capped stored push subscriptions at 5000 (`lkc_push_max_subscriptions`) so the anonymous subscribe endpoint cannot grow the table, and with it outgoing push traffic and cron work, without bound. Existing subscribers can always refresh their keys.
- Capped the catalog search `page` parameter at 500 to prevent deep `OFFSET` queries from anonymous callers.
- Coerced the stored resume position to a number before it reaches the DOM, so nothing read from localStorage is interpolated as markup.

### Fixed

- Defined the missing `$version` variable in the theme's Google Fonts loader. It raised a PHP notice and disabled asset cache-busting for the font loader script.
- Registered `player.js` with a file-time version instead of a hard-coded string, so player fixes reach browsers immediately after an upgrade.
- Read the episode title from the navigation `<strong>` element instead of the whole link, which previously prefixed the overlay title with the "Episode berikutnya" label.

## Theme 1.6.1 / Core Plugin 1.4.0

- Added browser-local watchlist and episode-page history.
- Added logged-in account ratings on a separate 1–10 scale from editorial ratings.
- Added self-hosted VAPID Web Push for new-episode broadcasts to explicitly opted-in subscriptions.
- Added responsive mobile search row at widths up to 400 px and raised player source controls to 44 px minimum height.
- Updated plugin runtime requirement to PHP 8.2+ and bundled the locked Web Push dependencies.
- Added privacy summaries and account/title rating cleanup.

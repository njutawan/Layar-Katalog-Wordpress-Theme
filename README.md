# Layar Katalog for WordPress

An original Full Site Editing block theme and companion plugin for editorial film, animation, and series catalogs. The project includes catalog/episode content types, a native HTML5 player, local engagement features, and optional self-hosted Web Push.

**Tema / Theme:** 1.7.0 · **Plugin Core:** 1.7.0 · **License:** GPL-2.0-or-later

> The theme's design and assets are original to Layar Katalog. No film/video media is bundled. Add only media you own or are authorized to publish.

## Features

- Catalog and episode post types, genre taxonomy, metadata, archive filters, search, episode navigation, and related-title blocks.
- Responsive block theme for catalog, episode, search, and editorial pages; theme settings include configurable advertising slots.
- HTML5 video player for direct MP4/WebM sources only.
- Browser-local watchlist and episode-page history; no account required and no cross-device sync.
- Account-based user ratings, one rating per WordPress account per title, on a 1–10 scale; kept separate from editorial ratings.
- Optional VAPID Web Push: each new published episode is broadcast to every active, explicitly opted-in browser subscription, independently of watchlists.

## Requirements

- WordPress 6.3 or newer.
- PHP 8.2 or newer to use the companion plugin (the plugin bundles its Composer dependencies under `vendor/`).
- OpenSSL, cURL, and mbstring for Web Push; HTTPS and working WP-Cron are required for push delivery.
- GMP or BCMath is optional and can speed up cryptographic calculations.

The plugin ZIP/source already contains its Composer runtime dependencies; a production WordPress host does not need Composer. Developers changing plugin dependencies should use the checked-in `composer.json` and `composer.lock`.

## Install from this repository

Copy the two directories into the matching WordPress content directories:

```sh
cp -R plugins/layar-katalog-core /path/to/wordpress/wp-content/plugins/
cp -R themes/layar-katalog /path/to/wordpress/wp-content/themes/
```

Then activate **Layar Katalog Core** and the **Layar Katalog** theme from WordPress Admin. Save **Settings → Permalinks** once after activation. Add your own genres, catalog titles, episodes, and featured images.

To use the browser-local pages, create WordPress pages containing `[lk_watchlist]` and/or `[lk_viewing_history]`. The episode archive includes the push opt-in control after an administrator configures VAPID in **Settings → Notifikasi Episode**. Never commit real VAPID private keys or production subscription data to this repository.

## Privacy notes

- Watchlist and viewing-history records stay in that browser's local storage and can be removed by clearing the site's browser data.
- Ratings are associated with a WordPress account and title; the plugin deletes them when the account or title is deleted.
- Push endpoint and encryption-key data are stored by the site only after a visitor opts in. Visitors can unsubscribe through the control or revoke browser permission. New-episode notifications are broadcast to all active subscriptions, not targeted from the watchlist.

## Security

The project was reviewed against a 20-point control list (rate limiting, CORS, CSRF, SSRF, path traversal, input/output validation, admin routes, secrets, audit logging, webhook verification, payment/price integrity, IDOR, log hygiene, source maps, dependencies, backups, headers, and pre-release testing). Findings, evidence with `file:line` references, and the remaining roadmap are in [`docs/SECURITY-AUDIT.md`](docs/SECURITY-AUDIT.md).

Applied in Core Plugin 1.7.0 / Theme 1.7.0 (P3) — the work that turns the remaining site-specific items into something a site owner can actually run:

- **A strict Content-Security-Policy mode.** `add_filter( 'lkc_csp_strict', '__return_true' );` drops `'unsafe-inline'` and `'unsafe-eval'` and replaces them with a per-request nonce, stamped on every inline `<script>` and `<style>` WordPress prints — including core's own global styles, emoji settings and localised script data, via `wp_script_attributes`/`wp_style_attributes`, and including administrator-supplied advertising code, which is echoed as raw markup and never reaches WordPress's script API. It is applied after user filters, so a filter cannot put `'unsafe-inline'` back. Strict mode marks every front-end response personalised, because HTML carrying a per-request nonce must not be served from a page cache to the next visitor.
- **The CSP tab writes the allowlist.** **Tools → Log Keamanan → CSP** turns the stored violation reports into a ready-to-paste `lkc_content_security_policy` filter, skipping hosts the policy already permits and translating the `inline`, `eval` and `data` keywords into the fix that actually resolves them, since none of the three is a domain.
- **Inline style attributes are gone from the front page.** The hero column widths moved from `style="flex-basis:58%"` in `templates/front-page.html` to CSS classes, because a style attribute cannot carry a nonce. Rendering is unchanged, including the mobile stacking core forces with `!important`.
- **The coding-standards CI job is blocking**, but only for violations a change introduces: PHPCS runs against this revision and its base with the same ruleset and the two reports are compared (`tests/phpcs-regression.mjs`), matching violations by sniff name and message rather than line number so reformatting a file does not make everything below the edit look new.
- **Staging scripts** for the two checks a unit test cannot make: `tests/wp/smoke-activate.sh` (activation, tables, migration, routes, cron, encrypted VAPID key that still decrypts, `vendor/` unreachable, headers arriving, rate limiter returning 429, quiet `debug.log`) and `tests/wp/rehearse-restore.sh` (the quarterly drill from the runbook, which never writes to the live database). `tests/wp/guards.test.sh` runs their destructive-command refusals in CI with a stubbed `wp` and `mysql`.
- **An activation suite that runs anywhere** (`tests/php/activation.test.php`, 81 assertions): what activation actually registers — both post types, the taxonomy, all 24 catalog meta keys with their REST exposure, sanitiser and capability gate, the six `lkc/v1` routes and their permission callbacks (reading a rating is public, writing one is not), the four table installs, the cron jobs and the front-end assets. It opens with a scan of the six registration files that fails if any `add_action`, `add_filter`, `add_shortcode` or route callback names a function that does not exist, which is the defect class `php -l` cannot see.
- **A reviewer for the snippet you are about to paste into `mu-plugins`** (`tests/csp-snippet-review.mjs`, 105 assertions): it runs the file in a real PHP 8.2 sandbox with the plugin's own policy builder and prints the header the site would then send, with every added and removed source. It refuses the failures that look like success — a filter registered on a misspelt tag, a missing `return $policy;` that collapses the policy to zero directives and sends no CSP at all, an unquoted keyword that becomes `style-src self *`, a wildcard source, and anything that still contains `'unsafe-inline'` once `lkc_csp_strict` is on.
- **A CSP pre-flight auditor** (`tests/js/csp-audit.mjs`, 59 assertions): point it at a URL or a saved page and it reports what `lkc_csp_mode` → `'enforce'` would break, using real browser matching rules (directive fallback chains, host wildcards, ports, `'self'`, and the CSP2 rule that a nonce makes `'unsafe-inline'` ignored). `--strict` evaluates as if `lkc_csp_strict` were already on, blocked inline markup comes with its hash, and the blocked hosts come back as a ready-to-paste `lkc_content_security_policy` filter. Exit code 1 means the page would break.

Applied in Core Plugin 1.6.0 / Theme 1.6.4 (P2):

- The VAPID private key is stored as an AES-256-GCM envelope keyed from the `wp-config.php` salts instead of plaintext, so a database dump on its own is no longer enough to sign push notifications. Keys from earlier releases are migrated automatically.
- Plain-HTTP video and subtitle URLs are refused (`lkc_allow_insecure_media_urls`), and media can be pinned to specific hosts (`lkc_allowed_media_hosts`).
- Push subscriptions that keep failing are counted and pruned by a daily cron (`lkc_push_max_failures`, `lkc_push_stale_days`), recorded in the audit log.
- `vendor/` is denied to web requests and every plugin/theme subdirectory carries an `index.php` guard, ending the public disclosure of exact dependency versions.
- A test suite in [`tests/`](tests/README.md) and a CI workflow now gate every push: `php -l` and `node --check` over the whole project, 152 PHP assertions on the security logic, 18 service-worker and 22 player DOM assertions, `composer audit`, and an advisory PHPCS/WPCS report.
- [`docs/BACKUP-RESTORE.md`](docs/BACKUP-RESTORE.md) documents what to back up, how to restore it, and how to rehearse the restore quarterly.

Applied in Core Plugin 1.5.0 / Theme 1.6.3 (P1):

- The WordPress default CORS reflection on `lkc/v1` is replaced with an origin allowlist (`lkc_allowed_cors_origins`, default: this site only), applied on both `rest_pre_serve_request` and `rest_post_dispatch`, always with `Vary: Origin`.
- A Content-Security-Policy is sent in report-only mode, with an anonymous collector at `POST lkc/v1/csp-report` that aggregates violations and stops at 500 fingerprints. Read them under **Tools → Log Keamanan**, allowlist the real domains via `lkc_content_security_policy`, then switch to blocking with `lkc_csp_mode`. The theme adds the Google Fonts origins automatically when a font is selected.
- The service worker no longer caches personalised HTML, previews, `private`/`no-store` responses, opaque responses, or error pages; it purges older `lk-*` caches on activate and only opens same-origin URLs from a notification click.
- A security audit trail records push-setting changes, VAPID rotation, subscription clearing, rejected anonymous subscriptions, and ad-code edits (slot names only). Client IP and user agent are stored as salted hashes. Retention: 90/30 days via a daily cron.
- The anonymous push routes declare an `args` schema, and REST write bodies are capped at 8 KB (`lkc_rest_max_body_bytes`) with a `413` answer.
- Fixed a fatal PHP parse error in `layar-katalog-core.php` that had prevented the plugin from being activated at all.

Applied in Core Plugin 1.4.1 / Theme 1.6.2 (P0):

- DOM XSS fix in the autoplay overlay, verified with a differential jsdom test against the previous revision.
- Push failures no longer write subscription endpoints to the debug log.
- Baseline front-end security headers (`nosniff`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, HSTS over HTTPS), filterable via `lkc_security_headers`.
- Per-visitor rate limiting on all `lkc/v1` REST routes (`lkc_rest_rate_limits`, `lkc_rate_limit_ip`) plus a subscription ceiling (`lkc_push_max_subscriptions`).

Nothing is left that can be done from inside this repository. What remains is execution on a live site: running `tests/wp/smoke-activate.sh` against staging, pre-checking each template with `node tests/js/csp-audit.mjs --url=<site> --strict`, reviewing the pasted snippet with `node tests/csp-snippet-review.mjs <file>`, switching on `lkc_csp_strict` in report-only mode for a normal week, pasting the snippet the CSP tab generates, re-running the auditor, and only then setting `lkc_csp_mode` to `'enforce'`; performing the first quarterly restore drill with `tests/wp/rehearse-restore.sh` and logging it in [`docs/BACKUP-RESTORE.md`](docs/BACKUP-RESTORE.md); and cross-browser plus live push-delivery testing. The pre-live operational checklist — `WP_DEBUG_DISPLAY` off, `DISALLOW_FILE_EDIT` on, 2FA for every administrator, `debug.log` not web-reachable, `lkc_rate_limit_ip` configured behind a CDN, and dropping `includeSubDomains` from HSTS if any subdomain still serves plain HTTP — is at the end of [`docs/SECURITY-AUDIT.md`](docs/SECURITY-AUDIT.md).

Two operational notes for site owners: `debug.log` must never be reachable over HTTP, and the VAPID private key lives in the WordPress database, so a database backup is key material and must be protected accordingly.

## Validation status

Source syntax checks, focused isolated smoke tests, Composer advisory audit, and package integrity checks have been run, and the harnesses now live in the repository under [`tests/`](tests/README.md) with a CI workflow running them on every push and pull request. Every PHP file in the project parses under a real PHP 8.2 runtime — through a native install in CI, and through a WebAssembly build for contributors without PHP — which is how a fatal parse error in the main plugin file was found and fixed. The security logic is covered by **254 assertions** executed against the shipped source with WordPress stubbed out: origin normalisation and the CORS allowlist, CSP construction and its resistance to a hostile filter, strict CSP mode (nonce generation and stamping, removal of `'unsafe-inline'`, and a filter being unable to put it back), the CSP report grouping and the allowlist snippet built from it, the personalisation cache hint, both rate-limit buckets, the body-size guard, the audit allowlist and write path, the CSP report collector, the media transport and host policy, stale-subscription pruning, VAPID key encryption at rest, and the theme filters. That last group matters more than it sounds: the suite `eval`s the snippet the admin screen generates and then calls the resulting filter, so the PHP handed to a site owner to paste into `mu-plugins` is proven to parse and to add the right hosts to the right directives. Four further suites cover registration and the tooling itself — **81 assertions** on what activation registers, including a scan that every callback named by the six registration files really exists; **59 assertions** on the CSP auditor, which is what makes "would enforce mode break this page?" answerable before a switch is thrown on a live site; **105 assertions** on the mu-plugin snippet reviewer, which loads the generated PHP into a sandbox and diffs the header it produces; **22 assertions** on the PHPCS comparator that makes the standards job blocking; and **24 assertions** on the destructive-command guards in the staging scripts, each one checking that no `mysql` command was issued at all. The **18** service-worker assertions and **22** player assertions both run differentially against `git HEAD` with `--previous`: the pre-fix service worker failed 9 of 18 (it cached signed-in HTML, previews, 404/500 pages and opaque responses, and opened a cross-origin notification URL), and the pre-fix player failed 8 of 22 (a hostile episode title became a live `<img>` element, `javascript:` and `data:` targets were accepted, and subtitle tracks doubled on every source switch). The DOM test suite is also what found that track-duplication bug. A mocked push send exercised signing/encryption without contacting an external push service. Every `composer.lock` package was re-checked against the GitHub Advisory Database on 2026-10-14 with no open advisories, and CI now repeats that check on every change. Full WordPress activation, cross-browser visual testing, and live HTTPS/WP-Cron/push-provider delivery still need to be run on a staging site; `tests/wp/smoke-activate.sh` and `tests/wp/rehearse-restore.sh` now automate the mechanical part of both, and neither has been executed against a real install yet. See [`docs/RESPONSIVE-CHECK.md`](docs/RESPONSIVE-CHECK.md) for the source-level viewport review.

## Contributing

Issues and pull requests are welcome. Please avoid submitting copyrighted media, live VAPID keys, database exports, personal data, or credentials. See [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).

## Lisensi

Kode proyek dilisensikan di bawah **GNU GPL versi 2 atau versi yang lebih baru (GPL-2.0-or-later)**. Komponen Composer pihak ketiga memiliki lisensi masing-masing; lihat `THIRD-PARTY-NOTICES.md` dan file lisensi di dalam `plugins/layar-katalog-core/vendor/`.

## Bahasa Indonesia — ringkasan

Layar Katalog adalah tema blok orisinal beserta plugin pendamping untuk katalog film, animasi, dan serial. Plugin menyediakan katalog/episode, genre, pencarian dan filter, player HTML5 MP4/WebM, rating akun 1–10, watchlist/riwayat lokal, serta notifikasi Web Push VAPID opsional. Watchlist/riwayat tersimpan di browser; push dikirim ke semua browser yang sudah opt-in, bukan berdasarkan watchlist.

Persyaratan gabungan: WordPress 6.3+, PHP 8.2+, dan HTTPS/WP-Cron untuk Web Push. Salin `themes/layar-katalog` ke `wp-content/themes/` serta `plugins/layar-katalog-core` ke `wp-content/plugins/`, aktifkan keduanya, lalu simpan ulang Permalink. Lihat dokumentasi plugin untuk pengaturan rinci. Proyek berlisensi GPL-2.0-or-later.
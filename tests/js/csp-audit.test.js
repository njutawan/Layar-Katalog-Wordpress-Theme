/**
 * Tests for the static CSP auditor that supports steps 2 and 3 of going live:
 * deciding whether enforce mode would break a page, and turning what it finds
 * into an allowlist.
 *
 * Run with: node tests/js/csp-audit.test.js
 */

import nodeAssert from 'node:assert/strict';

const { equal, deepEqual, ok, match, throws } = nodeAssert;
import { readFileSync, writeFileSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import {
	parsePolicy,
	effectiveDirective,
	hostMatches,
	parseSourceExpression,
	sourceMatchesUrl,
	normaliseUrl,
	evaluate,
	scanHtml,
	auditDocument,
	cspHash,
	suggestFilterSnippet,
	renderReport,
	shippedDefaultPolicy,
	parseArgs,
} from './csp-audit.mjs';

let passed = 0;
let failed = 0;

/**
 * Record one assertion.
 *
 * @param {string} label  What is being asserted.
 * @param {boolean} ok    Whether it held.
 * @param {string} detail Extra context printed on failure.
 */
function assert(label, ok, detail = '') {
	if (ok) {
		passed += 1;
		process.stdout.write(`PASS  ${label}\n`);
	} else {
		failed += 1;
		process.stdout.write(`FAIL  ${label}${detail ? `\n        ${detail}` : ''}\n`);
	}
}

/** Print a section heading. */
function section(label) {
	process.stdout.write(`\n--- ${label} ---\n`);
}

/**
 * Run a block of node:assert comparisons as one assertion.
 *
 * The comparisons themselves throw with a useful diff, which is turned into the
 * failure detail here so the whole file keeps the PASS/FAIL reporting style of
 * the other suites.
 *
 * @param {string} label What is being asserted.
 * @param {Function} fn  Body using node:assert/strict.
 */
function t(label, fn) {
	try {
		fn();
		assert(label, true);
	} catch (error) {
		assert(label, false, error.message.split('\n').slice(0, 8).join('\n'));
	}
}

const here = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(here, 'fixtures', 'csp-sample.html');
const fixture = readFileSync(fixturePath, 'utf8');
const docUrl = 'https://katalog.test/katalog/';

section('policy parsing');

t('directives are split, lower-cased and de-duplicated', () => {
	const policy = parsePolicy("DEFAULT-SRC 'self'; script-src 'self' https://a.test;; script-src 'self' https://b.test");
	equal(policy.get('default-src').length, 1);
	deepEqual(policy.get('script-src'), ['\'self\'', 'https://a.test'], 'the first script-src wins, as in a browser');
	equal(policy.size, 2);
});

t('an empty header parses to an empty policy', () => {
	equal(parsePolicy('').size, 0);
	equal(parsePolicy('   ;  ; ').size, 0);
});

t('script-src-attr falls back to script-src before default-src', () => {
	const policy = parsePolicy("default-src 'none'; script-src 'self' 'unsafe-inline'");
	const effective = effectiveDirective(policy, 'script-src-attr');
	equal(effective.directive, 'script-src');
	ok(effective.sources.includes("'unsafe-inline'"));
});

t('style-src-elem falls back through style-src to default-src', () => {
	const twoStep = effectiveDirective(parsePolicy("default-src 'none'; style-src 'self'"), 'style-src-elem');
	equal(twoStep.directive, 'style-src');
	const oneStep = effectiveDirective(parsePolicy("default-src 'self'"), 'style-src-elem');
	equal(oneStep.directive, 'default-src');
});

t('frame-src falls back to child-src', () => {
	const effective = effectiveDirective(parsePolicy("child-src 'self' https://embed.test"), 'frame-src');
	equal(effective.directive, 'child-src');
	ok(effective.sources.includes('https://embed.test'));
});

t('a directive with nothing to fall back to is unrestricted', () => {
	const effective = effectiveDirective(parsePolicy("script-src 'self'"), 'media-src');
	equal(effective.sources, null);
});

t("the shipped default policy is the one lkc_get_csp_directives() sends", () => {
	const policy = parsePolicy(shippedDefaultPolicy());
	equal(policy.size, 12);
	deepEqual(policy.get('object-src'), ["'none'"]);
	deepEqual(policy.get('img-src'), ["'self'", 'data:', 'https:']);
	ok(policy.get('script-src').includes("'unsafe-inline'"), 'report-only mode still allows inline');
});

section('source expression matching');

t('a bare host matches itself only, a wildcard covers the apex too', () => {
	equal(hostMatches('example.com', 'example.com'), true);
	equal(hostMatches('example.com', 'cdn.example.com'), false, 'subdomains need an explicit wildcard');
	equal(hostMatches('*.example.com', 'example.com'), true);
	equal(hostMatches('*.example.com', 'cdn.example.com'), true);
	equal(hostMatches('*.example.com', 'evil-example.com'), false, 'a suffix match is not a domain match');
	equal(hostMatches('*', 'anything.test'), true);
});

t('*:8080 is any host on that port, not a host named 8080', () => {
	deepEqual(parseSourceExpression('*:8080'), { kind: 'host', scheme: null, host: '*', port: '8080' });
	deepEqual(parseSourceExpression('*.example.com:443'), { kind: 'host', scheme: null, host: '*.example.com', port: '443' });
	deepEqual(parseSourceExpression('https://cdn.test'), { kind: 'host', scheme: 'https:', host: 'cdn.test', port: '' });
	deepEqual(parseSourceExpression('data:'), { kind: 'scheme', scheme: 'data:', host: '', port: '' });
});

t('the matcher agrees with browser behaviour on a matrix of sources', () => {
	const cases = [
		['*.example.com', 'https://cdn.example.com/a.js', true],
		['*.example.com', 'https://example.com/a.js', true],
		['*.example.com', 'https://evil-example.com/a.js', false],
		['example.com', 'https://cdn.example.com/a.js', false],
		['example.com', 'http://example.com/a.js', true, 'a scheme-less source matches any scheme'],
		['https:', 'http://cdn.test/a.js', false],
		['https:', 'https://cdn.test/a.js', true],
		['data:', 'data:image/png;base64,AAAA', true],
		['blob:', 'blob:https://katalog.test/x', true],
		["'self'", 'https://katalog.test/wp/x.js', true],
		["'self'", 'https://other.test/x.js', false],
		['*', 'https://anything.test/x.js', true],
		['example.com:443', 'https://example.com/x.js', true],
		['example.com:8443', 'https://example.com/x.js', false],
		['wss://push.test', 'wss://push.test/', true],
		['wss://push.test', 'https://push.test/', false],
		['*:8080', 'https://h.test:8080/a', true],
		['*:8080', 'https://h.test/a', false],
		["'none'", 'https://x.test/a', false],
	];

	for (const [source, url, expected, why] of cases) {
		equal(sourceMatchesUrl(source, normaliseUrl(url, docUrl), docUrl), expected, `${source} vs ${url}${why ? ` (${why})` : ''}`);
	}
});

t('a relative URL resolves against the page before matching', () => {
	const url = normaliseUrl('/wp-content/plugins/layar-katalog-core/player.js', docUrl);
	equal(url.origin, 'https://katalog.test');
	equal(sourceMatchesUrl("'self'", url, docUrl), true);
});

t('an unparsable URL is reported rather than silently allowed', () => {
	equal(normaliseUrl('https://', docUrl), null);
});

section('inline resources');

/**
 * Shorthand for an inline resource descriptor.
 */
function inline(kind, directive, nonce) {
	return { kind, directive, url: null, inline: true, nonce };
}

t("inline script is allowed while 'unsafe-inline' is present", () => {
	const policy = parsePolicy("script-src 'self' 'unsafe-inline'");
	equal(evaluate(inline('inline-script', 'script-src'), policy, { documentUrl: docUrl }).allowed, true);
});

t('inline script is blocked in strict mode without a nonce', () => {
	const policy = parsePolicy("script-src 'self' 'unsafe-inline'");
	const verdict = evaluate(inline('inline-script', 'script-src'), policy, { documentUrl: docUrl, strict: true });
	equal(verdict.allowed, false);
	match(verdict.reason, /strict mode/);
});

t('inline script is allowed in strict mode when the nonce matches', () => {
	const policy = parsePolicy("script-src 'self' 'nonce-abc123'");
	equal(evaluate(inline('inline-script', 'script-src', 'abc123'), policy, { documentUrl: docUrl, strict: true }).allowed, true);
	equal(evaluate(inline('inline-script', 'script-src', 'other'), policy, { documentUrl: docUrl, strict: true }).allowed, false, 'a different nonce must not be accepted');
});

t("a nonce makes the browser ignore 'unsafe-inline', so unnonced inline breaks", () => {
	const policy = parsePolicy("script-src 'self' 'unsafe-inline' 'nonce-abc123'");
	const verdict = evaluate(inline('inline-script', 'script-src'), policy, { documentUrl: docUrl });
	equal(verdict.allowed, false);
	match(verdict.reason, /'unsafe-inline' is ignored/);
});

t('a hash source behaves the same way as a nonce', () => {
	const policy = parsePolicy("script-src 'self' 'sha256-AAAA'");
	equal(evaluate(inline('inline-script', 'script-src'), policy, { documentUrl: docUrl }).allowed, false);
});

t('an onclick handler is judged by script-src, not default-src', () => {
	const permissive = parsePolicy("default-src 'none'; script-src 'self' 'unsafe-inline'");
	equal(evaluate(inline('inline-handler', 'script-src-attr'), permissive, { documentUrl: docUrl }).allowed, true);
	equal(evaluate(inline('inline-handler', 'script-src-attr'), permissive, { documentUrl: docUrl, strict: true }).allowed, false);
});

t('a style attribute is judged by style-src and no nonce can save it', () => {
	const policy = parsePolicy("style-src 'self' 'nonce-abc123'");
	const verdict = evaluate({ ...inline('style-attribute', 'style-src-attr', 'abc123'), hash: cspHash('flex-basis:58%') }, policy, { documentUrl: docUrl });
	equal(verdict.allowed, false, 'a nonce on a style= attribute is meaningless to a browser');
	match(verdict.reason, /a nonce cannot cover an attribute/);
});

t("an attribute hash only counts when 'unsafe-hashes' is present", () => {
	const hash = cspHash('flex-basis:58%');
	const without = parsePolicy("style-src 'self' '" + hash + "'");
	const withKeyword = parsePolicy("style-src 'self' 'unsafe-hashes' '" + hash + "'");
	const resource = { kind: 'style-attribute', directive: 'style-src-attr', url: null, inline: true, hash };
	equal(evaluate(resource, without, { documentUrl: docUrl }).allowed, false);
	equal(evaluate(resource, withKeyword, { documentUrl: docUrl }).allowed, true);
});

t('a script hash works without any extra keyword', () => {
	const body = 'var a = 1;';
	const policy = parsePolicy("script-src 'self' '" + cspHash(body) + "'");
	equal(evaluate({ kind: 'inline-script', directive: 'script-src', url: null, inline: true, hash: cspHash(body) }, policy, { documentUrl: docUrl }).allowed, true);
	equal(evaluate({ kind: 'inline-script', directive: 'script-src', url: null, inline: true, hash: cspHash('var a = 2;') }, policy, { documentUrl: docUrl }).allowed, false);
});

t("object-src 'none' blocks a plugin or embed", () => {
	const policy = parsePolicy(shippedDefaultPolicy());
	const verdict = evaluate({ kind: 'object', directive: 'object-src', url: 'https://katalog.test/x.swf', inline: false }, policy, { documentUrl: docUrl });
	equal(verdict.allowed, false);
	match(verdict.reason, /'none'/);
});

section('html scanning');

const findings = scanHtml(fixture, docUrl);

/**
 * The findings of one kind.
 */
function kind(name) {
	return findings.filter((finding) => finding.kind === name);
}

t('external and inline scripts are both found', () => {
	equal(kind('script').length, 3, 'two external plus the plain http one');
	equal(kind('inline-script').length, 1);
	match(kind('inline-script')[0].note, /layarkatalogAds/);
});

t('a JSON-LD block is recognised as data, not as executable script', () => {
	equal(kind('data-block').length, 1);
	equal(kind('data-block')[0].directive, null);
});

t('stylesheets, style elements and style attributes are distinguished', () => {
	equal(kind('stylesheet').length, 2);
	equal(kind('inline-style').length, 1);
	equal(kind('style-attribute').length, 1);
	match(kind('style-attribute')[0].note, /flex-basis/);
});

t('inline event handlers are found', () => {
	equal(kind('inline-handler').length, 1);
	match(kind('inline-handler')[0].note, /onclick/i);
});

t('link relations map to the directive that governs them', () => {
	equal(kind('link:preconnect')[0].directive, 'connect-src');
	equal(kind('preload:font')[0].directive, 'font-src');
	equal(kind('manifest')[0].directive, 'manifest-src');
});

t('images include every srcset candidate', () => {
	equal(kind('image').length, 1);
	equal(kind('video-poster').length, 1, 'a video poster is governed by img-src');
	equal(kind('video-poster')[0].directive, 'img-src');
	equal(kind('image-srcset').length, 2);
	const cdn = kind('image-srcset').find((item) => /cdn\.contoh\.test/.test(item.url));
	ok(cdn, 'a srcset candidate on another host must be checked');
});

t('media elements and their sources are found', () => {
	const media = findings.filter((finding) => ['video', 'source', 'track'].includes(finding.kind));
	ok(media.length >= 3);
	ok(media.every((finding) => finding.directive === 'media-src'));
});

t('frames, forms and base are checked against their own directives', () => {
	equal(kind('iframe')[0].directive, 'frame-src');
	equal(kind('form')[0].directive, 'form-action');
	equal(kind('base')[0].directive, 'base-uri');
});

t('a javascript: URL is flagged on its own', () => {
	const dangerous = kind('dangerous-scheme');
	equal(dangerous.length, 1);
	match(dangerous[0].note, /javascript: URL in markup/);
});

t('inline findings carry a copy-pasteable hash', () => {
	for (const finding of [...kind('inline-script'), ...kind('inline-style'), ...kind('style-attribute'), ...kind('inline-handler')]) {
		match(finding.hash, /^sha256-[A-Za-z0-9+/]{43}=$/, finding.kind);
	}
	equal(kind('style-attribute')[0].hash, cspHash('flex-basis:58%'), 'the hash covers exactly the attribute value');
});

t('every finding carries a line number for the editor', () => {
	ok(findings.every((finding) => Number.isInteger(finding.line) && finding.line > 0));
	const ad = findings.find((finding) => /googlesyndication/.test(finding.url || ''));
	equal(ad.line, 21);
});

section('end-to-end audit of the sample page');

const loose = auditDocument(fixture, shippedDefaultPolicy(), { documentUrl: docUrl });
const strict = auditDocument(fixture, shippedDefaultPolicy(), { documentUrl: docUrl, strict: true });

t('report-only mode would block five external hosts', () => {
	equal(loose.counts.blocked, 5);
	const urls = loose.blocked.map((resource) => resource.url);
	ok(urls.some((url) => /googlesyndication/.test(url)), 'the ad script');
	ok(urls.some((url) => /fonts\.googleapis/.test(url)), 'the remote stylesheet');
	ok(urls.some((url) => /fonts\.gstatic/.test(url)), 'the preconnect');
	ok(urls.some((url) => /youtube-nocookie/.test(url)), 'the embed');
	ok(urls.some((url) => /^http:/.test(url)), 'the plain http script');
});

t('inline markup survives report-only mode but not strict mode', () => {
	equal(loose.blocked.filter((resource) => resource.inline).length, 0);
	const inlineBlocked = strict.blocked.filter((resource) => resource.inline).map((resource) => resource.kind).sort();
	deepEqual(inlineBlocked, ['inline-handler', 'inline-script', 'inline-style', 'style-attribute']);
	equal(strict.counts.blocked, 9);
});

t('same-origin resources are never reported as blocked', () => {
	const blockedUrls = strict.blocked.map((resource) => resource.url).filter(Boolean);
	ok(blockedUrls.every((url) => !url.startsWith('/')), 'a root-relative URL is same-origin');
	ok(blockedUrls.every((url) => !url.startsWith('https://katalog.test')));
});

t('the notes tell the operator what to do, not just what failed', () => {
	const notes = strict.notes.join('\n');
	match(notes, /nonce automatically/);
	match(notes, /A nonce cannot cover an attribute, so move the declaration into a stylesheet/);
	match(notes, /inline event handler/);
	match(notes, /addEventListener/, 'an onclick= attribute cannot be nonced, so the note must say what to do instead');
	match(notes, /mixed content/);
	match(loose.notes.join('\n'), /plain http/);
});

t('blocked hosts are grouped per directive with a reference count', () => {
	deepEqual(Object.keys(loose.allowlist).sort(), ['connect-src', 'frame-src', 'script-src', 'style-src']);
	equal(loose.allowlist['script-src']['pagead2.googlesyndication.com'], 1);
	equal(loose.allowlist['script-src']['cdn.lama.test'], 1);
	ok(!Object.values(loose.allowlist).some((hosts) => Object.keys(hosts).length === 0));
});

t('a page with nothing but same-origin resources passes cleanly', () => {
	const clean = auditDocument('<!DOCTYPE html><html><head><link rel="stylesheet" href="/style.css"></head><body><img src="/a.png"><script src="/b.js"></script></body></html>', shippedDefaultPolicy(), { documentUrl: docUrl });
	equal(clean.counts.blocked, 0);
	match(renderReport(clean), /nothing on this page would be blocked/);
});

t('allowlisting a host in the policy removes it from the report', () => {
	const narrowed = auditDocument(fixture, "default-src 'self'; script-src 'self' 'unsafe-inline' https://pagead2.googlesyndication.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https:; media-src 'self' https: blob:; font-src 'self' data:; connect-src 'self' https://fonts.gstatic.com; frame-src 'self' https://www.youtube-nocookie.com; object-src 'none'; base-uri 'self'; form-action 'self'", { documentUrl: docUrl });
	const urls = narrowed.blocked.map((resource) => resource.url).filter(Boolean);
	ok(!urls.some((url) => /googlesyndication|fonts\.|youtube-nocookie/.test(url)));
	ok(urls.some((url) => /^http:/.test(url)), 'a plain http source is still blocked: https: sources do not cover it');
});

t('the rendered report is readable and points at line numbers', () => {
	const text = renderReport(strict);
	match(text, /would be blocked: 9/);
	match(text, /line 26/);
	match(text, /style-src \(3\)/);
});

section('allowlist snippet generation');

t('the snippet is a filter on lkc_content_security_policy', () => {
	const snippet = suggestFilterSnippet([loose]);
	match(snippet, /^add_filter\( 'lkc_content_security_policy', function \( \$policy \) \{/);
	match(snippet, /return \$policy;\n\} \);$/);
});

t('every line of the snippet is well formed PHP', () => {
	const lines = suggestFilterSnippet([loose]).split('\n').filter((line) => line.includes('$policy['));
	equal(lines.length, 5);
	for (const line of lines) {
		match(line, /^\t\$policy\['[a-z-]+'\]\[\] = 'https:\/\/[a-z0-9.-]+'; \/\/ \d+ reference\(s\)$/, line);
	}
});

t('the snippet never tries to allowlist inline markup', () => {
	const snippet = suggestFilterSnippet([strict]);
	ok(!snippet.includes("'unsafe-inline'"));
	ok(!snippet.includes('nonce-'));
	match(snippet, /Drop any line for a domain you do not recognise/);
});

t('counts merge when several pages are audited together', () => {
	const other = auditDocument('<script src="https://pagead2.googlesyndication.com/x.js"></script><script src="https://pagead2.googlesyndication.com/y.js"></script>', shippedDefaultPolicy(), { documentUrl: docUrl });
	const snippet = suggestFilterSnippet([loose, other]);
	match(snippet, /'https:\/\/pagead2\.googlesyndication\.com'; \/\/ 3 reference\(s\)/);
});

t('a clean audit produces an explanatory snippet rather than an empty one', () => {
	const clean = auditDocument('<img src="/a.png">', shippedDefaultPolicy(), { documentUrl: docUrl });
	const snippet = suggestFilterSnippet([clean]);
	match(snippet, /No external host needs allowlisting/);
});

section('command line arguments');

t('flags are parsed, and --url and --html may repeat', () => {
	const options = parseArgs(['--url=https://a.test/', '--url=https://b.test/', '--html=page.html', '--policy=default-src \'self\'', '--strict', '--format=json', '--quiet']);
	deepEqual(options.urls, ['https://a.test/', 'https://b.test/']);
	deepEqual(options.files, ['page.html']);
	equal(options.policy, "default-src 'self'");
	equal(options.strict, true);
	equal(options.json, true);
	equal(options.quiet, true);
});

t('a bare argument is treated as a file and an unknown flag is rejected', () => {
	deepEqual(parseArgs(['page.html']).files, ['page.html']);
	throws(() => parseArgs(['--nope=1']), /unknown option/);
});

t('--policy-file and --help are recognised', () => {
	equal(parseArgs(['--policy-file=header.txt']).policyFile, 'header.txt');
	equal(parseArgs(['--help']).help, true);
});

t('a policy containing an equals sign survives argument splitting', () => {
	equal(parseArgs(['--policy=img-src data:=x']).policy, 'img-src data:=x');
});

section('command line');

const script = path.join(here, 'csp-audit.mjs');

/**
 * Run the auditor exactly as CI and an operator would.
 *
 * @param {string[]} args Argument list.
 * @param {string} input Optional stdin content.
 * @returns {{code: number, out: string, err: string}}
 */
function run(args, input) {
	const result = spawnSync(process.execPath, [script, ...args], { encoding: 'utf8', input });
	return { code: result.status, out: result.stdout || '', err: result.stderr || '' };
}

t('a page that would break exits 1 and names every blocking host', () => {
	const result = run(['--html=' + fixturePath, '--document-url=' + docUrl]);
	equal(result.code, 1);
	match(result.out, /pagead2\.googlesyndication\.com/);
	match(result.out, /fonts\.googleapis\.com/);
	match(result.out, /youtube-nocookie\.com/);
	match(result.out, /suggested filter/);
});

t('a page that would survive enforce mode exits 0', () => {
	const clean = path.join(here, 'fixtures', 'clean.html');
	writeFileSync(clean, '<!DOCTYPE html><html><body><img src="/a.png"><script src="/b.js"></script></body></html>');
	try {
		const result = run(['--html=' + clean, '--document-url=' + docUrl]);
		equal(result.code, 0, result.out);
		match(result.out, /0 resource\(s\) would be blocked/);
	} finally {
		rmSync(clean, { force: true });
	}
});

t('--strict finds the inline markup that report-only mode tolerates', () => {
	const loose = run(['--html=' + fixturePath, '--document-url=' + docUrl]);
	const strict = run(['--html=' + fixturePath, '--document-url=' + docUrl, '--strict']);
	equal(strict.code, 1);
	match(strict.out, /evaluated in strict mode/);
	ok(parseInt(/would be blocked: (\d+)/.exec(strict.out)[1], 10) > parseInt(/would be blocked: (\d+)/.exec(loose.out)[1], 10));
	match(strict.out, /style-attribute|style="\.\.\." attribute/);
});

t('--format=json carries the reports, the counts and the snippet', () => {
	const result = run(['--html=' + fixturePath, '--document-url=' + docUrl, '--format=json']);
	const payload = JSON.parse(result.out);
	equal(payload.reports.length, 1);
	ok(payload.blocked > 0);
	match(payload.snippet, /add_filter\( 'lkc_content_security_policy'/);
	ok(payload.reports[0].resources.length > 20);
});

t("--document-url is what makes 'self' resolve for a saved page", () => {
	const html = '<script src="https://katalog.test/wp/x.js"></script><img src="/a.png">';
	const wrong = run(["--policy=default-src 'self'", '--document-url=https://other.test/'], html);
	equal(wrong.code, 1, 'with the wrong document URL the absolute same-origin script is blocked');
	match(wrong.out, /katalog\.test/);
	const correct = run(["--policy=default-src 'self'", '--document-url=https://katalog.test/'], html);
	equal(correct.code, 0, correct.out);
});

t('piped markup is audited as stdin', () => {
	const result = run(["--policy=default-src 'self'; img-src 'self' https:", '--document-url=https://katalog.test/'], '<img src="https://katalog.test/a.png">');
	equal(result.code, 0, `${result.code} ${result.err}`);
	match(result.out, /stdin/);
});

t('a missing input file is a usage error, not a crash', () => {
	const result = run(['--html=/nope/missing.html']);
	equal(result.code, 2);
	match(result.err, /html file not found/);
});

t('an unknown flag is rejected with its name', () => {
	const result = run(['--html=' + fixturePath, '--nonsense=1']);
	equal(result.code, 2);
	match(result.err, /unknown option: --nonsense/);
});

t('no input at all prints the usage text', () => {
	const result = run([], '');
	equal(result.code, 2);
	match(result.out, /Static Content-Security-Policy auditor/);
	match(result.out, /--policy-file/);
	const help = run(['--help'], '');
	equal(help.code, 0);
});


process.stdout.write(`\n=================================\n${passed} passed, ${failed} failed\n`);
process.exit(failed > 0 ? 1 : 0);

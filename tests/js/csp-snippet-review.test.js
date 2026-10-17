#!/usr/bin/env node
/**
 * Tests for tests/csp-snippet-review.mjs, the tool that reviews a
 * Content-Security-Policy snippet before it is pasted into mu-plugins.
 *
 * The static half is tested without PHP at all. The dynamic half runs the real
 * snippets through a real PHP 8.2 in WebAssembly against the plugin's own
 * policy builder, which is the point of the tool: it must judge the snippet by
 * the header the site would actually send.
 *
 * Requires `npm install` inside tests/ first.
 *
 * Usage: node tests/js/csp-snippet-review.test.js
 */

import { spawnSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	scanSnippet,
	scanStructure,
	stripComments,
	dynamicFindings,
	verdict,
	renderReview,
	reviewFiles,
} from '../csp-snippet-review.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const script = path.resolve(here, '..', 'csp-snippet-review.mjs');
const fixtures = path.join(here, 'fixtures', 'snippets');

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
 * Read one fixture.
 *
 * @param {string} name Fixture basename without the extension.
 * @returns {string}
 */
function fixture(name) {
	return readFileSync(path.join(fixtures, `${name}.php.txt`), 'utf8');
}

/**
 * A sandbox result with the shipped defaults, overridden where a test needs it.
 *
 * @param {object} overrides Fields to replace.
 * @returns {object}
 */
function sandboxResult(overrides = {}) {
	return {
		parse: 'ok',
		parse_error: null,
		filters_added: { lkc_content_security_policy: ['closure'] },
		actions_added: {},
		before: { 'default-src': ["'self'"], 'script-src': ["'self'", "'unsafe-inline'"], 'report-uri': ['https://site.test/wp-json/lkc/v1/csp-report'] },
		after: { 'default-src': ["'self'"], 'script-src': ["'self'", "'unsafe-inline'", 'https://cdn.test'], 'report-uri': ['https://site.test/wp-json/lkc/v1/csp-report'] },
		added: { 'script-src': ['https://cdn.test'] },
		removed: {},
		directives_lost: [],
		directives_new: [],
		header_before: "default-src 'self'; script-src 'self' 'unsafe-inline'",
		header_after: "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.test",
		strict_after: { 'script-src': ["'self'", "'nonce-abc'"] },
		strict_added: {},
		strict_unsafe_inline: false,
		fatal: null,
		...overrides,
	};
}

/**
 * The severities in a finding list.
 *
 * @param {Array<object>} findings Findings.
 * @returns {string[]}
 */
function severities(findings) {
	return findings.map((finding) => finding.severity);
}

/**
 * Whether any finding explains itself with this text.
 *
 * @param {Array<object>} findings Findings.
 * @param {RegExp} pattern What to look for.
 * @returns {boolean}
 */
function explains(findings, pattern) {
	return findings.some((finding) => pattern.test(finding.why));
}

/* ================================================================== */
section('the fixtures themselves');

const names = readdirSync(fixtures).filter((file) => file.endsWith('.php.txt')).map((file) => file.replace('.php.txt', '')).sort();

assert('every case this tool exists for has a fixture', ['backdoor', 'defeats-strict', 'good', 'missing-return', 'needs-review', 'parse-error', 'typo-tag', 'unquoted-keyword'].every((name) => names.includes(name)), names.join(', '));
assert('fixtures are stored as .php.txt so the CI php -l job never sees the broken one', !readdirSync(fixtures).some((file) => file.endsWith('.php')), readdirSync(fixtures).join(', '));

/* ================================================================== */
section('static scan: what it refuses to run');

const backdoor = scanSnippet(fixture('backdoor'));

assert('filesystem writes are a block', explains(backdoor, /filesystem access/), JSON.stringify(backdoor));
assert('decoding an obfuscated payload is a block', explains(backdoor, /obfuscated payload/));
assert('reading request input is a block', explains(backdoor, /reads request input/));
assert('every finding carries the line it came from', backdoor.every((finding) => finding.line === 4), JSON.stringify(backdoor.map((finding) => finding.line)));
assert('all three findings are blocks', severities(backdoor).every((severity) => severity === 'block'));

const hostile = scanSnippet(`<?php
add_filter( 'lkc_content_security_policy', function ( $policy ) {
	eval( $_GET['x'] );
	$result = \`whoami\`;
	system( 'id' );
	wp_remote_get( 'https://evil.test/' );
	global $wpdb;
	include 'x.php';
	ini_set( 'display_errors', 1 );
	extract( array( 'a' => 1 ) );
	new ReflectionClass( 'X' );
	$policy['script-src'][] = '*://cdn.test';
	$policy = array();
	remove_filter( 'lkc_content_security_policy', 'other' );
	return $policy;
} );
`);

for (const why of [/dynamic code execution/, /shell execution via backticks/, /shell execution/, /network access/, /WordPress globals/, /loads another file/, /changes the PHP environment/, /creates variables from data/, /escaping class/, /wildcard scheme source/, /replaces the whole policy/, /unregisters other filters/]) {
	assert(`flags ${why.source}`, explains(hostile, why));
}
assert('nothing in that list is treated as harmless', severities(hostile).filter((severity) => severity === 'note').length === 0);

section('static scan: what it must not flag');

const clean = scanSnippet(fixture('good'));
const cleanStructure = scanStructure(fixture('good'));

assert('a plain allowlist has no static findings', clean.length === 0, JSON.stringify(clean));
assert('a plain allowlist has no structural findings', cleanStructure.length === 0, JSON.stringify(cleanStructure));
assert('a docblock is not code', scanSnippet("<?php\n/**\n * Never call file_put_contents() or eval() here.\n * system( 'x' ) and $_GET are equally harmless in a comment.\n */\nadd_filter( 'lkc_content_security_policy', function ( $policy ) {\n\treturn $policy;\n} );\n").length === 0);
assert('blanking a comment does not move the lines below it', (() => {
	const stripped = stripComments("<?php\n/* one\ntwo */\nfile_put_contents( 'x', 'y' );\n");
	return stripped.split('\n')[3].includes('file_put_contents');
})());
assert('a URL is not mistaken for a comment', stripComments("<?php\n$policy['script-src'][] = 'https://cdn.test/x.js';\n").includes('https://cdn.test'));
assert('a trailing comment is not code', scanSnippet("<?php\n$policy['script-src'][] = 'https://cdn.test'; // not system( 'x' )\n").length === 0);
assert('a host containing the word data is not a data: source', explains(scanSnippet("<?php\n$policy['img-src'][] = 'https://metadata.google.internal';\n"), /adds a data: source/) === false);

section('static scan: structure');

assert('a file without an opening tag is a block', explains(scanStructure('add_filter( \'lkc_content_security_policy\', function ( $policy ) { return $policy; } );'), /does not open with <\?php/));
assert('a file that never calls add_filter is a block', explains(scanStructure('<?php\nreturn 1;\n'), /never calls add_filter/));
assert('a file that filters an unknown tag is a block', explains(scanStructure(fixture('typo-tag')), /never applies/));
assert('a filter without a return is a block', explains(scanStructure(fixture('missing-return')), /return null/));
assert('the post-strict-mode filter is a legitimate target', scanStructure(fixture('defeats-strict')).filter((finding) => finding.severity === 'block').length === 0, JSON.stringify(scanStructure(fixture('defeats-strict'))));
assert('a closing PHP tag needs a second look', explains(scanSnippet(fixture('needs-review')), /closing PHP tag/));
assert('a very long snippet needs a second look', explains(scanStructure(`<?php\n${'// padding\n'.repeat(210)}add_filter( 'lkc_content_security_policy', function ( $policy ) { return $policy; } );\n`), /more than an allowlist needs/));

section('verdict');

assert('a block means do not paste', verdict([{ severity: 'block' }, { severity: 'note' }]) === 2);
assert('a review means a human decides', verdict([{ severity: 'review' }, { severity: 'note' }]) === 1);
assert('notes alone are a pass', verdict([{ severity: 'note' }]) === 0);
assert('no findings at all are a pass', verdict([]) === 0);
assert('the worst finding wins', verdict([{ severity: 'note' }, { severity: 'block' }, { severity: 'review' }]) === 2);

/* ================================================================== */
section('sandbox findings');

assert('a snippet that registers nothing is a block', explains(dynamicFindings(sandboxResult({ filters_added: {}, added: {}, after: sandboxResult().before, header_after: sandboxResult().header_before })), /registered no filter/));
assert('a snippet on a tag the plugin never applies is a block', explains(dynamicFindings(sandboxResult({ filters_added: { lkc_content_security_policies: ['closure'] }, added: {} })), /will never run/));
assert("a filter on lkc_csp_directives is allowed but flagged", explains(dynamicFindings(sandboxResult({ filters_added: { lkc_csp_directives: ['closure'] } })), /after strict mode/));
const emptied = sandboxResult({ added: {}, after: {}, header_after: '', directives_lost: ['default-src', 'script-src', 'report-uri'] });
const emptiedFindings = dynamicFindings(emptied);

assert('a snippet that empties the policy is one block, not thirteen', emptiedFindings.filter((finding) => /disappear|all 3 directives/.test(finding.why)).length === 1, JSON.stringify(emptiedFindings));
assert('an emptied policy is described as sending no CSP at all', explains(emptiedFindings, /no CSP is sent at all/));
assert('losing one directive is named', explains(dynamicFindings(sandboxResult({ directives_lost: ['report-uri'] })), /report-uri disappears/));
assert('an empty header is a block on its own', explains(dynamicFindings(sandboxResult({ header_after: '' })), /header is empty/));
assert('removing a source asks whether it was deliberate', explains(dynamicFindings(sandboxResult({ removed: { 'script-src': ["'unsafe-inline'"] } })), /overwriting instead of appending/));
assert('defeating strict mode is a block', explains(dynamicFindings(sandboxResult({ strict_unsafe_inline: true, strict_added: { 'script-src': ["'unsafe-inline'"] } })), /defeats strict mode/));
assert('a parse error is reported instead of a diff', explains(dynamicFindings(sandboxResult({ parse: 'error', parse_error: 'ParseError: Unclosed {' })), /does not parse/));
assert('a crashed sandbox is reported', explains(dynamicFindings(sandboxResult({ parse: 'crashed', fatal: { message: 'Allowed memory exhausted' } })), /stopped PHP/));
assert('an action hook registered alongside is a review', explains(dynamicFindings(sandboxResult({ actions_added: { init: ['my_late_policy_tweak'] } })), /hooks the action/));

section('sandbox findings: source expressions');

/**
 * The findings for one added source.
 *
 * @param {string} source Source expression.
 * @param {string} directive Directive it is added to.
 * @returns {Array<object>}
 */
function forSource(source, directive = 'script-src') {
	return dynamicFindings(sandboxResult({ added: { [directive]: [source] } }));
}

assert('a bare * is a block', explains(forSource('*'), /allows every host/) && severities(forSource('*')).includes('block'));
assert('a wildcard scheme is a block', explains(forSource('*://cdn.test'), /allows every host/));
assert('a host wildcard is a review, not a block', explains(forSource('*.cdn.test'), /every subdomain/) && !severities(forSource('*.cdn.test')).includes('block'));
assert('an unquoted keyword is a block', explains(forSource('self'), /without its single quotes/), JSON.stringify(forSource('self')));
assert("re-adding 'unsafe-inline' is a review", explains(forSource("'unsafe-inline'"), /undoing what strict mode removes/));
assert("re-adding 'unsafe-eval' is a review", explains(forSource("'unsafe-eval'"), /undoing what strict mode removes/));
assert('a plain http source is a review', explains(forSource('http://legacy.test/stream.mp4'), /plain-http/));
assert('an https host is only a note', severities(forSource('https://cdn.test')).every((severity) => severity === 'note'));
assert('a second-level wildcard host is a note', severities(forSource('https://pagead2.googlesyndication.com')).every((severity) => severity === 'note'));

section('rendering');

const rendered = renderReview({
	file: 'mu-plugins/csp-allowlist.php',
	bytes: 392,
	lines: 12,
	phpVersion: '8.2',
	findings: dynamicFindings(sandboxResult()),
	sandbox: sandboxResult(),
	status: 0,
});

assert('the report names the file', rendered.includes('mu-plugins/csp-allowlist.php'));
assert('the report shows the header the site would send', rendered.includes('https://cdn.test'), rendered);
assert('the report shows what the policy gained', rendered.includes('+ script-src: https://cdn.test'));
assert('the report ends with a verdict a person can act on', rendered.includes('SAFE TO PASTE'));

const wiped = renderReview({
	file: 'broken.php',
	bytes: 10,
	lines: 2,
	phpVersion: '8.2',
	findings: dynamicFindings(sandboxResult({ added: {}, after: {}, header_after: '', directives_lost: ['default-src', 'script-src', 'report-uri'] })),
	sandbox: sandboxResult({ added: {}, after: {}, header_after: '', directives_lost: ['default-src', 'script-src', 'report-uri'] }),
	status: 2,
});

assert('an emptied policy is not described as unchanged', !wiped.includes('identical to the shipped default'), wiped);
assert('an emptied policy says the header is empty', wiped.includes('the rendered header is empty') || wiped.includes('no CSP is sent at all'));

/* ================================================================== */
section('end to end: every fixture through a real PHP 8.2');

const files = names.map((name) => path.join(fixtures, `${name}.php.txt`));
const reviews = await reviewFiles(files);
const byName = new Map(reviews.map((review) => [path.basename(review.file).replace('.php.txt', ''), review]));

assert('one sandbox boot reviewed all eight fixtures', reviews.length === 8, String(reviews.length));
assert('a plain allowlist is safe to paste', byName.get('good').status === 0, JSON.stringify(byName.get('good').findings));
assert('a snippet needing judgement exits between safe and broken', byName.get('needs-review').status === 1, JSON.stringify(byName.get('needs-review').findings));
assert('nothing that needs judgement is also called broken', byName.get('needs-review').findings.every((finding) => finding.severity !== 'block'));

const good = byName.get('good');
assert('the allowlist really reaches the policy', Object.keys(good.sandbox.added).length === 5, JSON.stringify(good.sandbox.added));
assert('it adds five hosts and removes nothing', Object.values(good.sandbox.added).flat().length === 5 && Object.keys(good.sandbox.removed).length === 0);
assert('the report collector survives an allowlist edit', /report-uri https:\/\/example\.test\/wp-json\/lkc\/v1\/csp-report/.test(good.sandbox.header_after), good.sandbox.header_after);
assert("object-src stays 'none'", /object-src 'none'/.test(good.sandbox.header_after));
assert('strict mode still removes unsafe-inline with the snippet loaded', good.sandbox.strict_unsafe_inline === false);
assert('the baseline is the shipped thirteen-directive policy', Object.keys(good.sandbox.before).length === 13, JSON.stringify(Object.keys(good.sandbox.before)));

const missing = byName.get('missing-return');
assert('a filter without a return wipes the policy', Object.keys(missing.sandbox.after).length === 0, JSON.stringify(missing.sandbox.after));
assert('and therefore sends no header at all', missing.sandbox.header_after === '');
assert('which the tool refuses', missing.status === 2);

const typo = byName.get('typo-tag');
assert('a typo in the filter name registers on the wrong tag', Object.keys(typo.sandbox.filters_added).join() === 'lkc_content_security_policies', JSON.stringify(typo.sandbox.filters_added));
assert('and changes nothing', Object.keys(typo.sandbox.added).length === 0);
assert('so the header is identical to the default', typo.sandbox.header_after === typo.sandbox.header_before);

const strict = byName.get('defeats-strict');
assert('a filter applied after strict mode can put unsafe-inline back', strict.sandbox.strict_unsafe_inline === true, JSON.stringify(strict.sandbox.strict_added));
assert('and the tool calls that a block', explains(strict.findings, /defeats strict mode/) && strict.status === 2);
assert('in report-only mode the same snippet looks harmless', strict.sandbox.added['script-src'] === undefined || strict.sandbox.header_after.includes("'unsafe-inline'"), strict.sandbox.header_after);

const unparsable = byName.get('parse-error');
assert('a syntax error is reported as one', unparsable.sandbox.parse === 'error', JSON.stringify(unparsable.sandbox.parse));
assert('with the message PHP gave', /ParseError|syntax error/i.test(unparsable.sandbox.parse_error || ''), unparsable.sandbox.parse_error);
assert('and the other fixtures still got reviewed', byName.get('good').sandbox.parse === 'ok');

const unquoted = byName.get('unquoted-keyword');
assert('an unquoted keyword reaches the header verbatim', unquoted.sandbox.header_after.includes('style-src self'), unquoted.sandbox.header_after);
assert('which the tool refuses rather than shrugs at', explains(unquoted.findings, /without its single quotes/) && unquoted.status === 2);
assert('and a bare * alongside it is refused too', explains(unquoted.findings, /allows every host/));

const door = byName.get('backdoor');
assert('a snippet that writes files is refused', door.status === 2 && explains(door.findings, /filesystem access/));
assert('even though its policy change looks fine', door.sandbox.added['script-src'] !== undefined, JSON.stringify(door.sandbox.added));

/* ================================================================== */
section('command line');

/**
 * Run the reviewer as a child process.
 *
 * @param {string[]} args Argument list.
 * @returns {{code: number, out: string, err: string}}
 */
function run(args) {
	const result = spawnSync(process.execPath, [script, ...args], { encoding: 'utf8' });
	return { code: result.status, out: result.stdout || '', err: result.stderr || '' };
}

assert('a clean snippet exits 0', run([path.join(fixtures, 'good.php.txt')]).code === 0);
assert('a snippet needing judgement exits 1', run([path.join(fixtures, 'needs-review.php.txt')]).code === 1);
assert('a broken snippet exits 2', run([path.join(fixtures, 'missing-return.php.txt')]).code === 2);

const many = run(files);
assert('several files at once exit with the worst verdict', many.code === 2);
assert('and report every one of them', files.every((file) => many.out.includes(path.basename(file))), 'a fixture is missing from the output');

const asJson = run([path.join(fixtures, 'good.php.txt'), '--json']);
const payload = JSON.parse(asJson.out);
assert('--json carries the diff', payload.sandbox.added['script-src'].length === 1, asJson.out.slice(0, 200));
assert('--json carries the findings', Array.isArray(payload.findings) && payload.findings.length > 0);
assert('--json carries the verdict text', /SAFE TO PASTE/.test(payload.verdict));

assert('a missing file is a usage error', run(['/nope/missing.php']).code === 2 && /snippet not found/.test(run(['/nope/missing.php']).err));
const usage = run([]);
assert('no argument prints the usage text', usage.code === 2 && /Review a Content-Security-Policy snippet/.test(usage.out), usage.out.slice(0, 120));
assert('an unknown flag is rejected', run(['--nonsense']).code === 2 && /unknown option/.test(run(['--nonsense']).err));
assert('--help explains the exit codes', /0 safe to paste/.test(run(['--help']).out));

process.stdout.write(`\n=================================\n${passed} passed, ${failed} failed\n`);
process.exit(failed > 0 ? 1 : 0);

#!/usr/bin/env node
/**
 * Tests for tests/phpcs-regression.mjs, the script that makes the coding
 * standards job blocking without inheriting the repository's existing debt.
 *
 * Runs the script as a child process against generated PHPCS JSON reports and
 * asserts on both its exit code and its output, because CI acts on both.
 *
 * Usage: node tests/js/phpcs-regression.test.js
 */

import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const script = resolve(here, '../phpcs-regression.mjs');

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

const work = mkdtempSync(join(tmpdir(), 'lkc-phpcs-'));
const baseRoot = '/tmp/base';
const headRoot = '/tmp/head';

/**
 * Write a PHPCS-style JSON report and return its path.
 *
 * @param {string} name  File name inside the scratch directory.
 * @param {object} files Map of absolute path => array of message objects.
 * @returns {string} Absolute path of the written report.
 */
function report(name, files) {
	const payload = {
		totals: { errors: 0, warnings: 0, fixable: 0 },
		files: {},
	};

	for (const [path, messages] of Object.entries(files)) {
		payload.files[path] = { errors: messages.length, warnings: 0, messages };
		payload.totals.errors += messages.length;
	}

	const file = join(work, name);
	writeFileSync(file, JSON.stringify(payload, null, 2));
	return file;
}

/**
 * Build one PHPCS message object.
 *
 * @param {number} line    Line number.
 * @param {string} source  Sniff name.
 * @param {string} message Human-readable violation.
 * @returns {object}
 */
function violation(line, source, message) {
	return { message, source, severity: 5, fixable: false, type: 'ERROR', line, column: 1 };
}

/**
 * Run the regression script.
 *
 * @param {string} baseFile Base report path.
 * @param {string} headFile Head report path.
 * @returns {{code: number, out: string}} Exit code and combined output.
 */
function run(baseFile, headFile) {
	const result = spawnSync(process.execPath, [script, baseFile, baseRoot, headFile, headRoot], {
		encoding: 'utf8',
	});
	return { code: result.status, out: `${result.stdout || ''}${result.stderr || ''}` };
}

section('no change in standards debt');
{
	const base = report('clean-base.json', {
		[`${baseRoot}/themes/layar-katalog/functions.php`]: [
			violation(10, 'WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet', 'Use wp_enqueue_style().'),
			violation(44, 'Generic.Commenting.DocComment.MissingShort', 'Missing short description.'),
		],
	});
	const head = report('clean-head.json', {
		[`${headRoot}/themes/layar-katalog/functions.php`]: [
			violation(12, 'WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet', 'Use wp_enqueue_style().'),
			violation(46, 'Generic.Commenting.DocComment.MissingShort', 'Missing short description.'),
		],
	});
	const result = run(base, head);
	assert('passes when nothing new appears', result.code === 0, `exit ${result.code}\n${result.out}`);
	assert('line-number drift is not treated as new', !result.out.includes('NEW violations'), result.out);
	assert('reports both totals', result.out.includes('base: 2 error(s)') && result.out.includes('head: 2 error(s)'), result.out);
}

section('a new violation in an edited file');
{
	const base = report('regress-base.json', {
		[`${baseRoot}/plugins/layar-katalog-core/includes/audit.php`]: [
			violation(5, 'Squiz.Commenting.FileComment.Missing', 'Missing file comment.'),
		],
	});
	const head = report('regress-head.json', {
		[`${headRoot}/plugins/layar-katalog-core/includes/audit.php`]: [
			violation(5, 'Squiz.Commenting.FileComment.Missing', 'Missing file comment.'),
			violation(88, 'WordPress.Security.EscapeOutput.OutputNotEscaped', 'All output must be escaped.'),
		],
	});
	const result = run(base, head);
	assert('fails on a newly introduced violation', result.code === 1, `exit ${result.code}\n${result.out}`);
	assert('names the offending file', result.out.includes('plugins/layar-katalog-core/includes/audit.php'), result.out);
	assert('names the sniff', result.out.includes('WordPress.Security.EscapeOutput.OutputNotEscaped'), result.out);
	assert('does not re-report the pre-existing violation', !result.out.includes('Squiz.Commenting.FileComment.Missing:'), result.out);
	assert('counts exactly one new violation', result.out.includes('NEW violations (1)'), result.out);
}

section('a brand new file');
{
	const base = report('newfile-base.json', {});
	const head = report('newfile-head.json', {
		[`${headRoot}/plugins/layar-katalog-core/includes/security.php`]: [
			violation(3, 'Squiz.Commenting.FileComment.Missing', 'Missing file comment.'),
		],
	});
	const result = run(base, head);
	assert('fails when a new file has violations', result.code === 1, `exit ${result.code}\n${result.out}`);
	assert('marks the file as new', result.out.includes('(new file)'), result.out);
}

section('debt going down');
{
	const base = report('improve-base.json', {
		[`${baseRoot}/themes/layar-katalog/functions.php`]: [
			violation(20, 'Generic.WhiteSpace.DisallowTabIndent.TabsUsed', 'Tabs used for indentation.'),
			violation(21, 'Generic.WhiteSpace.DisallowTabIndent.TabsUsed', 'Tabs used for indentation.'),
		],
		[`${baseRoot}/plugins/layar-katalog-core/includes/legacy.php`]: [
			violation(4, 'Squiz.Commenting.FileComment.Missing', 'Missing file comment.'),
			violation(9, 'WordPress.WP.EnqueuedResources.NonEnqueuedScript', 'Use wp_enqueue_script().'),
		],
	});
	const head = report('improve-head.json', {
		[`${headRoot}/themes/layar-katalog/functions.php`]: [
			violation(20, 'Generic.WhiteSpace.DisallowTabIndent.TabsUsed', 'Tabs used for indentation.'),
		],
	});
	const result = run(base, head);
	assert('passes when violations are removed', result.code === 0, `exit ${result.code}\n${result.out}`);
	assert('counts the violation fixed in an edited file', result.out.includes('Resolved by this change: 3'), result.out);
	assert('counts violations in a deleted file as resolved', result.out.includes('base: 4 error(s) across 2 file(s)') && result.out.includes('head: 1 error(s) across 1 file(s)'), result.out);
	assert('does not claim any new violation', result.out.includes('No new violations'), result.out);
}

section('a repeated violation is counted per occurrence');
{
	const base = report('repeat-base.json', {
		[`${baseRoot}/themes/layar-katalog/functions.php`]: [
			violation(1, 'WordPress.Security.EscapeOutput.OutputNotEscaped', 'All output must be escaped.'),
		],
	});
	const head = report('repeat-head.json', {
		[`${headRoot}/themes/layar-katalog/functions.php`]: [
			violation(1, 'WordPress.Security.EscapeOutput.OutputNotEscaped', 'All output must be escaped.'),
			violation(9, 'WordPress.Security.EscapeOutput.OutputNotEscaped', 'All output must be escaped.'),
			violation(17, 'WordPress.Security.EscapeOutput.OutputNotEscaped', 'All output must be escaped.'),
		],
	});
	const result = run(base, head);
	assert('fails', result.code === 1, `exit ${result.code}\n${result.out}`);
	assert('reports only the two extra occurrences', result.out.includes('x2'), result.out);
	assert('does not inflate the new-violation count', result.out.includes('NEW violations (1)'), result.out);
}

section('warnings are not errors');
{
	const base = report('warn-base.json', {
		[`${baseRoot}/themes/layar-katalog/functions.php`]: [],
	});
	const headFiles = {
		[`${headRoot}/themes/layar-katalog/functions.php`]: [
			{ message: 'Commenting style.', source: 'Squiz.Commenting.InlineComment.InvalidEndChar', severity: 5, fixable: false, type: 'WARNING', line: 4, column: 1 },
		],
	};
	const head = report('warn-head.json', headFiles);
	const result = run(base, head);
	assert('a warning alone does not fail the build', result.code === 0, `exit ${result.code}\n${result.out}`);
}

section('bad input');
{
	const result = run(join(work, 'does-not-exist.json'), join(work, 'also-missing.json'));
	assert('a missing report exits 2', result.code === 2, `exit ${result.code}`);
	assert('a missing report says which file', result.out.includes('does-not-exist.json'), result.out);

	const broken = join(work, 'broken.json');
	writeFileSync(broken, '{ not json');
	const brokenResult = run(broken, broken);
	assert('malformed JSON exits 2', brokenResult.code === 2, `exit ${brokenResult.code}`);

	const usage = spawnSync(process.execPath, [script], { encoding: 'utf8' });
	assert('no arguments prints usage and exits 2', usage.status === 2 && usage.stderr.includes('usage:'), `${usage.status} ${usage.stderr}`);
}

rmSync(work, { recursive: true, force: true });

process.stdout.write(`\n=================================\n${passed} passed, ${failed} failed\n`);
process.exit(failed > 0 ? 1 : 0);

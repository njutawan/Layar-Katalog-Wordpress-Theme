#!/usr/bin/env node
/**
 * Fail only on coding-standards violations that a change actually introduces.
 *
 * This codebase predates the WordPress ruleset, so a plain blocking PHPCS run is
 * red from day one and therefore gets ignored. Comparing two reports instead
 * lets the check be blocking where it matters - new and edited code - while the
 * outstanding debt is worked down at its own pace.
 *
 * Violations are matched by their sniff name and message rather than by line
 * number, so reformatting a file does not make every violation below the edit
 * look new. A violation counts as new when it appears more often in the head
 * report than in the base report for the same file.
 *
 * Usage:
 *   node tests/phpcs-regression.mjs <base.json> <base-root> <head.json> <head-root>
 *
 * Both reports are `phpcs --report=json --warning-severity=0` output.
 */

import { readFileSync, existsSync } from 'node:fs';

const args = process.argv.slice(2);
if (args.length !== 4) {
	process.stderr.write('usage: node tests/phpcs-regression.mjs <base.json> <base-root> <head.json> <head-root>\n');
	process.exit(2);
}

const [baseFile, baseRoot, headFile, headRoot] = args;

/**
 * Read a PHPCS JSON report into a map of relative path => violation signatures.
 *
 * @param {string} file Report path.
 * @param {string} root Absolute tree root to strip from every reported path.
 * @returns {Map<string, Map<string, number>>} path => (signature => occurrences)
 */
function readReport(file, root) {
	if (!existsSync(file)) {
		process.stderr.write(`report not found: ${file}\n`);
		process.exit(2);
	}

	let report;
	try {
		report = JSON.parse(readFileSync(file, 'utf8'));
	} catch (error) {
		process.stderr.write(`${file} is not valid PHPCS JSON: ${error.message}\n`);
		process.exit(2);
	}

	const prefix = root.endsWith('/') ? root : `${root}/`;
	const byPath = new Map();

	for (const [absolute, entry] of Object.entries(report.files || {})) {
		const relative = absolute.startsWith(prefix) ? absolute.slice(prefix.length) : absolute;
		const signatures = new Map();

		for (const message of entry.messages || []) {
			if (message.type && message.type.toUpperCase() !== 'ERROR') {
				continue;
			}
			const signature = `${message.source || 'unknown'}\u0000${message.message || ''}`;
			signatures.set(signature, (signatures.get(signature) || 0) + 1);
		}

		byPath.set(relative, signatures);
	}

	return byPath;
}

/**
 * Total number of violations across a report.
 *
 * @param {Map<string, Map<string, number>>} report Report map.
 * @returns {number}
 */
function total(report) {
	let sum = 0;
	for (const signatures of report.values()) {
		for (const count of signatures.values()) {
			sum += count;
		}
	}
	return sum;
}

const base = readReport(baseFile, baseRoot);
const head = readReport(headFile, headRoot);

const regressions = [];
let fixed = 0;

for (const [path, signatures] of head) {
	const before = base.get(path) || new Map();
	const isNewFile = !base.has(path);

	for (const [signature, count] of signatures) {
		const previous = before.get(signature) || 0;
		const introduced = count - previous;

		if (introduced <= 0) {
			fixed += Math.max(0, -introduced);
			continue;
		}

		const [source, message] = signature.split('\u0000');
		regressions.push({ path, source, message, count: introduced, isNewFile });
	}
}

/* Violations that disappeared entirely are worth reporting too: it is the only
   signal that the outstanding debt is shrinking. */
for (const [path, signatures] of base) {
	const after = head.get(path);
	if (after) {
		continue;
	}
	for (const count of signatures.values()) {
		fixed += count;
	}
}

regressions.sort((a, b) => a.path.localeCompare(b.path) || a.source.localeCompare(b.source));

process.stdout.write('Coding-standards regression check\n');
process.stdout.write(`  base: ${total(base)} error(s) across ${base.size} file(s)\n`);
process.stdout.write(`  head: ${total(head)} error(s) across ${head.size} file(s)\n\n`);

if (fixed > 0) {
	process.stdout.write(`Resolved by this change: ${fixed}\n\n`);
}

if (regressions.length === 0) {
	process.stdout.write('No new violations. Outstanding debt is unchanged or lower.\n');
	process.exit(0);
}

process.stdout.write(`NEW violations (${regressions.length}):\n`);
let lastPath = null;
for (const item of regressions) {
	if (item.path !== lastPath) {
		process.stdout.write(`\n  ${item.path}${item.isNewFile ? '  (new file)' : ''}\n`);
		lastPath = item.path;
	}
	const times = item.count > 1 ? `  x${item.count}` : '';
	process.stdout.write(`    ${item.source}: ${item.message}${times}\n`);
}

process.stdout.write('\nFix these, or - for a deliberate, documented exception - add a phpcs:ignore comment explaining why.\n');
process.exit(1);

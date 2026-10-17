#!/usr/bin/env node
/**
 * Review a Content-Security-Policy snippet before it goes into mu-plugins.
 *
 * Step 3 of going live asks somebody to paste generated PHP into a production
 * site and "check every line". This does that check, mechanically:
 *
 *   1. it parses the file with a real PHP 8.2 (WebAssembly, no install needed);
 *   2. it loads the plugin's own security.php and the snippet into a sandbox,
 *      registers whatever the snippet registers, and diffs the resulting policy
 *      against the shipped default - per directive, per source, added and
 *      removed;
 *   3. it renders the header with the plugin's own builder, so a source that
 *      would be dropped for containing a semicolon shows up as a difference;
 *   4. it repeats the diff with lkc_csp_strict on, to confirm the snippet cannot
 *      reintroduce 'unsafe-inline' behind the nonce;
 *   5. it flags constructs that have no business being in a policy snippet:
 *      filesystem, network, shell, superglobals, base64_decode, a wholesale
 *      `$policy =` reassignment, remove_filter, add_action, a closing PHP tag,
 *      or a filter registered on a tag the plugin never applies.
 *
 * The snippet is executed, so only run this on a file you would run. It runs
 * inside the WebAssembly sandbox: no host filesystem beyond the mirrored
 * repository, no database, no network.
 *
 * Usage:
 *   node tests/csp-snippet-review.mjs path/to/snippet.php
 *   node tests/csp-snippet-review.mjs path/to/snippet.php --strict --json
 *
 * Exit status: 0 safe to paste, 1 needs a human decision, 2 broken or blocked.
 */

import { readFileSync, existsSync } from 'node:fs';
import { argv, exit, stdout, stderr } from 'node:process';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const mirror = '/tmp/repo';

/**
 * Files the sandbox needs: the harness bootstrap plus every shipped file it
 * loads. This is the same list the WebAssembly test runner mirrors, minus the
 * test files themselves, so the snippet is evaluated against the real policy
 * builder rather than a copy of it.
 */
const NEEDED = [
	'tests/php/bootstrap.php',
	'plugins/layar-katalog-core/includes/security.php',
	'plugins/layar-katalog-core/includes/audit.php',
	'plugins/layar-katalog-core/includes/web-push.php',
	'plugins/layar-katalog-core/includes/engagement.php',
	'plugins/layar-katalog-core/layar-katalog-core.php',
	'themes/layar-katalog/functions.php',
];

/** The filter the plugin actually applies. Anything else is a typo that silently does nothing. */
const EXPECTED_TAG = 'lkc_content_security_policy';

/** Runs after strict mode, so it can undo it - allowed, but always worth a second look. */
const POST_STRICT_TAG = 'lkc_csp_directives';

/** The tags a snippet may register on and still be heard. */
const APPLIED_TAGS = [EXPECTED_TAG, POST_STRICT_TAG];

/** Ceiling for a file that is supposed to be a policy snippet. */
const MAX_BYTES = 65536;

/* ------------------------------------------------------------------ *
 * Static scan
 * ------------------------------------------------------------------ */

/**
 * Patterns that have no place in a CSP snippet, with the severity each carries.
 *
 * `block` means the file should not be pasted at all; `review` means a human has
 * to say whether it is intentional.
 */
const PATTERNS = [
	{ severity: 'block', pattern: /\b(?:eval|create_function|assert)\s*\(/, why: 'dynamic code execution' },
	{ severity: 'block', pattern: /`[^`]*`/, why: 'shell execution via backticks' },
	{ severity: 'block', pattern: /\b(?:system|exec|passthru|shell_exec|popen|proc_open|pcntl_\w+)\s*\(/, why: 'shell execution' },
	{ severity: 'block', pattern: /\b(?:file_put_contents|fwrite|fputs|fopen|unlink|rmdir|mkdir|chmod|rename|copy|move_uploaded_file)\s*\(/, why: 'filesystem access' },
	{ severity: 'block', pattern: /\b(?:include|include_once|require|require_once)\b/, why: 'loads another file' },
	{ severity: 'block', pattern: /\b(?:curl_\w+|fsockopen|stream_socket_client|wp_remote_\w+|wp_safe_remote_\w+|file_get_contents)\s*\(/, why: 'network access' },
	{ severity: 'block', pattern: /\$(?:wpdb|GLOBALS)\b/, why: 'reaches into WordPress globals' },
	{ severity: 'block', pattern: /\b(?:base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin|convert_uudecode)\s*\(/, why: 'decodes an obfuscated payload' },
	{ severity: 'block', pattern: /\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV)\b/, why: 'reads request input' },
	{ severity: 'block', pattern: /\b(?:ini_set|ini_alter|putenv|error_reporting|set_error_handler|register_shutdown_function)\s*\(/, why: 'changes the PHP environment' },
	{ severity: 'block', pattern: /\bextract\s*\(|\bparse_str\s*\(/, why: 'creates variables from data' },
	{ severity: 'block', pattern: /\bnew\s+(?:Reflection\w*|PDO|mysqli|SoapClient)\b/, why: 'instantiates an escaping class' },
	{ severity: 'block', pattern: /\*\s*:\s*\//, why: 'a wildcard scheme source allows every host' },
	{ severity: 'review', pattern: /\?>/, why: 'a closing PHP tag lets trailing whitespace reach the output' },
	{ severity: 'review', pattern: /\$policy\s*=(?!=)/, why: 'replaces the whole policy instead of adding to it' },
	{ severity: 'review', pattern: /'unsafe-inline'|"unsafe-inline"/, why: "re-adds 'unsafe-inline'" },
	{ severity: 'review', pattern: /'unsafe-eval'|"unsafe-eval"/, why: "re-adds 'unsafe-eval', which reopens what CSP closed" },
	{ severity: 'review', pattern: /'unsafe-hashes'|"unsafe-hashes"/, why: "allows hashed inline attributes; weaker than moving them to a file" },
	{ severity: 'review', pattern: /\bremove_filter\s*\(|\bremove_all_filters\s*\(/, why: 'unregisters other filters' },
	{ severity: 'review', pattern: /\badd_action\s*\(/, why: 'hooks an action; a policy snippet should only filter' },
	{ severity: 'review', pattern: /lkc_csp_mode|lkc_csp_strict|lkc_security_headers/, why: 'changes another security setting from inside a policy snippet' },
	{ severity: 'review', pattern: /['"]\*['"]/, why: "a bare '*' source allows every host" },
	{ severity: 'review', pattern: /\bdata:/, why: 'adds a data: source' },
	{ severity: 'review', pattern: /\bblob:/, why: 'adds a blob: source' },
];

/**
 * Blank out comments without moving anything to a different line.
 *
 * A docblock that says "never call eval() here" is documentation, not an
 * instruction, and reporting it would teach people to ignore the report. Block
 * comments are replaced character by character so line numbers survive, and a
 * `//` is only a comment when it is not part of a URL.
 *
 * @param {string} source File contents.
 * @returns {string} Source with comments blanked out.
 */
export function stripComments(source) {
	const withoutBlocks = String(source).replace(/\/\*[\s\S]*?\*\//g, (match) => match.replace(/[^\n]/g, ' '));

	return withoutBlocks
		.split('\n')
		.map((line) => line.replace(/(^|\s)\/\/.*$/, '').replace(/(^|\s)#.*$/, ''))
		.join('\n');
}

/**
 * Scan the snippet text without running it.
 *
 * @param {string} source File contents.
 * @returns {Array<object>} Findings with severity, line, why and the offending text.
 */
export function scanSnippet(source) {
	const lines = stripComments(source).split('\n');
	const findings = [];

	lines.forEach((line, index) => {
		const code = line;
		for (const rule of PATTERNS) {
			const match = rule.pattern.exec(code);
			if (match) {
				findings.push({
					severity: rule.severity,
					line: index + 1,
					why: rule.why,
					text: String(source).split('\n')[index].trim().slice(0, 110),
					source: 'static',
				});
			}
		}
	});

	return findings;
}

/**
 * Structural checks that need the whole file rather than one line.
 *
 * @param {string} source File contents.
 * @returns {Array<object>} Findings.
 */
export function scanStructure(source) {
	const text = String(source);
	const findings = [];
	const note = (why, severity = 'review') => findings.push({ severity, line: 1, why, text: '', source: 'static' });

	if (!/^[\s\S]*<\?php\b/.test(text)) {
		note('the file does not open with <?php, so WordPress will print it as text', 'block');
	}
	if (text.length > MAX_BYTES) {
		note(`the file is ${text.length} bytes; a policy snippet should be a handful of lines`, 'review');
	}
	if (text.split('\n').length > 200) {
		note(`the file is ${text.split('\n').length} lines long, which is more than an allowlist needs`, 'review');
	}
	if (!/add_filter\s*\(/.test(text)) {
		note('the file never calls add_filter(), so it cannot change the policy', 'block');
	} else if (!APPLIED_TAGS.some((tag) => text.includes(tag))) {
		note(`the file mentions neither '${EXPECTED_TAG}' nor '${POST_STRICT_TAG}', the only two filters the plugin applies`, 'block');
	}
	if (/add_filter\s*\(/.test(text) && !/return\s+\$policy/.test(text) && !/return\s+\$directives/.test(text)) {
		note('no `return $policy;` was found, so the filter will return null and the policy becomes empty', 'block');
	}
	const tags = [...text.matchAll(/add_filter\s*\(\s*['"]([^'"]+)['"]/g)].map((match) => match[1]);
	for (const tag of new Set(tags)) {
		if (tag !== EXPECTED_TAG && tag !== 'lkc_csp_directives') {
			note(`registers a filter on '${tag}', which this plugin never applies`, 'block');
		}
	}

	return findings;
}

/* ------------------------------------------------------------------ *
 * Sandbox evaluation
 * ------------------------------------------------------------------ */

/**
 * The PHP that runs inside the sandbox and prints one JSON result per snippet.
 *
 * Returned without an opening tag: it is appended to the prelude that already
 * opened one, and a second `<?php` is a parse error.
 *
 * @param {string[]} snippetPaths Paths of the snippets inside the sandbox, in order.
 * @returns {string} PHP source.
 */
export function phpPayload(snippetPaths) {
	const list = snippetPaths.map((item, index) => `\t${index} => '${item}',`).join('\n');

	return `
define( 'LKC_TEST_ROOT', '${mirror}' );
require '${mirror}/tests/php/bootstrap.php';

$snippets = array(
${list}
);

$describe = function ( $callbacks ) {
	$names = array();
	foreach ( $callbacks as $cb ) {
		if ( is_string( $cb ) ) {
			$names[] = $cb;
		} elseif ( is_array( $cb ) ) {
			$names[] = ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . $cb[1];
		} else {
			$names[] = 'closure';
		}
	}
	return $names;
};

$empty = array(
	'parse'                => 'ok',
	'parse_error'          => null,
	'filters_added'        => array(),
	'actions_added'        => array(),
	'before'               => array(),
	'after'                => array(),
	'added'                => array(),
	'removed'              => array(),
	'directives_lost'      => array(),
	'directives_new'       => array(),
	'header_before'        => '',
	'header_after'         => '',
	'strict_after'         => array(),
	'strict_added'         => array(),
	'strict_unsafe_inline' => false,
	'fatal'                => null,
);

/* A hard fatal in one snippet must still be reported rather than vanish. */
register_shutdown_function( function () {
	$error = error_get_last();
	if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		echo "\\nSNIPPET_FATAL=" . json_encode( $error ) . "\\n";
	}
} );

/* The shipped policy with no snippet loaded is the baseline every diff uses. */
$baseline_filters = $GLOBALS['__filters'];
$baseline_actions = $GLOBALS['__actions'];
$before           = lkc_get_csp_directives();
$header_before    = lkc_stringify_csp_directives( $before );

foreach ( $snippets as $index => $path ) {
	$result = $empty;

	/* Undo whatever the previous snippet registered before loading this one. */
	$GLOBALS['__filters'] = $baseline_filters;
	$GLOBALS['__actions'] = $baseline_actions;

	$result['before']        = $before;
	$result['header_before'] = $header_before;

	$tags_before    = array();
	$actions_before = array();
	foreach ( $GLOBALS['__filters'] as $tag => $callbacks ) {
		$tags_before[ $tag ] = count( $callbacks );
	}
	foreach ( $GLOBALS['__actions'] as $tag => $callbacks ) {
		$actions_before[ $tag ] = count( $callbacks );
	}

	/* A syntax error in the snippet must become a finding, not a dead sandbox. */
	try {
		require $path;
	} catch ( Throwable $e ) {
		$result['parse']       = 'error';
		$result['parse_error'] = get_class( $e ) . ': ' . $e->getMessage() . ' on line ' . $e->getLine();
		echo 'SNIPPET_JSON[' . $index . ']=' . json_encode( $result ) . "\\n";
		continue;
	}

	foreach ( $GLOBALS['__filters'] as $tag => $callbacks ) {
		$delta = count( $callbacks ) - ( isset( $tags_before[ $tag ] ) ? $tags_before[ $tag ] : 0 );
		if ( $delta > 0 ) {
			$result['filters_added'][ $tag ] = array_slice( $describe( $callbacks ), -$delta );
		}
	}
	foreach ( $GLOBALS['__actions'] as $tag => $callbacks ) {
		$delta = count( $callbacks ) - ( isset( $actions_before[ $tag ] ) ? $actions_before[ $tag ] : 0 );
		if ( $delta > 0 ) {
			$result['actions_added'][ $tag ] = array_slice( $describe( $callbacks ), -$delta );
		}
	}

	$result['after']        = lkc_get_csp_directives();
	$result['header_after'] = lkc_stringify_csp_directives( $result['after'] );

	foreach ( $result['after'] as $directive => $sources ) {
		$was    = isset( $result['before'][ $directive ] ) ? (array) $result['before'][ $directive ] : array();
		$now    = (array) $sources;
		$gained = array_values( array_diff( $now, $was ) );
		$lost   = array_values( array_diff( $was, $now ) );
		if ( $gained ) {
			$result['added'][ $directive ] = $gained;
		}
		if ( $lost ) {
			$result['removed'][ $directive ] = $lost;
		}
		if ( ! isset( $result['before'][ $directive ] ) ) {
			$result['directives_new'][] = $directive;
		}
	}
	foreach ( $result['before'] as $directive => $sources ) {
		if ( ! isset( $result['after'][ $directive ] ) ) {
			$result['directives_lost'][] = $directive;
		}
	}

	/* The snippet must not be able to defeat strict mode either. */
	add_filter( 'lkc_csp_strict', '__return_true' );
	$strict = lkc_get_csp_directives();
	$result['strict_after'] = $strict;
	foreach ( $strict as $directive => $sources ) {
		if ( ! in_array( $directive, array( 'script-src', 'style-src', 'default-src' ), true ) ) {
			continue;
		}
		$found = array_values( array_intersect( (array) $sources, array( "'unsafe-inline'", "'unsafe-eval'" ) ) );
		if ( $found ) {
			$result['strict_unsafe_inline']  = true;
			$result['strict_added'][ $directive ] = $found;
		}
	}

	echo 'SNIPPET_JSON[' . $index . ']=' . json_encode( $result ) . "\\n";
}
`;
}

/**
 * Boot one sandbox and evaluate every snippet in it.
 *
 * A single PHP instance serves all of them: the filter registry is restored
 * between snippets, so each diff is measured against the pristine shipped
 * policy. Booting the interpreter is the expensive part, which matters when
 * somebody reviews a whole folder of generated snippets.
 *
 * @param {Map<string, string>} sourcesByName Snippet name => file contents.
 * @param {string} phpVersion PHP version to boot.
 * @returns {Promise<Map<string, object>>} Snippet name => sandbox result.
 */
export async function evaluateSnippets(sourcesByName, phpVersion = '8.2') {
	const contents = new Map();
	for (const rel of NEEDED) {
		const absolute = path.join(root, rel);
		if (!existsSync(absolute)) {
			throw new Error(`missing repository file: ${rel}`);
		}
		contents.set(rel, readFileSync(absolute));
	}

	const names = [...sourcesByName.keys()];
	const paths = [];
	names.forEach((name, index) => {
		const rel = `snippet-${index}.php`;
		contents.set(rel, Buffer.from(sourcesByName.get(name), 'utf8'));
		paths.push(`${mirror}/${rel}`);
	});

	const prelude = ['<?php'];
	for (const [rel, buffer] of contents) {
		prelude.push(
			`@mkdir( dirname( '${mirror}/${rel}' ), 0777, true );`,
			`file_put_contents( '${mirror}/${rel}', base64_decode( ${JSON.stringify(buffer.toString('base64'))} ) );`
		);
	}

	const php = new PHP(await loadNodeRuntime(phpVersion, { emscriptenOptions: { processId: 4243 } }));
	const out = await php.runStream({ code: `${prelude.join('\n')}\n${phpPayload(paths)}` });
	const printed = await out.stdoutText;
	const errors = await out.stderrText;

	const fatal = /SNIPPET_FATAL=(\{[\s\S]*?\})\s*$/m.exec(printed);
	const results = new Map();

	names.forEach((name, index) => {
		const match = new RegExp(`SNIPPET_JSON\\[${index}\\]=(\\{[\\s\\S]*?\\})\\n`).exec(printed);
		results.set(name, match ? JSON.parse(match[1]) : failedResult(fatal ? JSON.parse(fatal[1]) : null, errors || printed));
	});

	return results;
}

/**
 * Classify a sandbox run that never printed its JSON line.
 *
 * An uncatchable fatal reaches us only through the shutdown handler, so the
 * message is what distinguishes "broken snippet" from "the sandbox failed".
 *
 * @param {object|null} fatal The captured fatal error, if any.
 * @param {string} output Everything the sandbox printed.
 * @returns {object} An empty result carrying the failure.
 */
export function failedResult(fatal, output) {
	const message = fatal && fatal.message ? String(fatal.message) : String(output || '').trim();
	const isParse = /parse error|syntax error/i.test(message);

	return {
		parse: isParse ? 'error' : 'crashed',
		parse_error: isParse ? message : null,
		fatal: fatal || { message: message || 'the sandbox produced no result', type: 'unknown' },
		filters_added: {},
		actions_added: {},
		before: {},
		after: {},
		added: {},
		removed: {},
		directives_lost: [],
		directives_new: [],
		header_before: '',
		header_after: '',
		strict_after: {},
		strict_added: {},
		strict_unsafe_inline: false,
	};
}

/* ------------------------------------------------------------------ *
 * Verdict
 * ------------------------------------------------------------------ */

/**
 * Turn a sandbox result into findings.
 *
 * @param {object} result Result from evaluateInSandbox().
 * @returns {Array<object>} Findings.
 */
export function dynamicFindings(result) {
	const findings = [];
	const add = (severity, why, text = '') => findings.push({ severity, line: 0, why, text, source: 'sandbox' });

	if (result.parse === 'error' || result.parse === 'crashed') {
		const detail = result.parse_error || (result.fatal && result.fatal.message) || 'unknown error';
		add('block', result.parse === 'error' ? `the snippet does not parse: ${detail}` : `the snippet stopped PHP: ${detail}`);
		return findings;
	}
	if (result.fatal) {
		add('block', `the snippet stopped PHP: ${result.fatal.message || 'fatal error'}${result.fatal.file ? ` in ${result.fatal.file}:${result.fatal.line}` : ''}`);
		return findings;
	}

	const tags = Object.keys(result.filters_added || {});
	const known = tags.filter((tag) => APPLIED_TAGS.includes(tag));
	if (tags.length === 0) {
		add('block', 'the snippet registered no filter at all, so pasting it changes nothing');
	} else if (known.length === 0) {
		add('block', `the snippet registers on '${tags.join("', '")}' but the plugin applies '${APPLIED_TAGS.join("' and '")}', so it will never run`);
	} else {
		for (const tag of known) {
			add('note', `registers ${result.filters_added[tag].length} filter(s) on '${tag}'`);
		}
		if (tags.includes(POST_STRICT_TAG)) {
			add('review', `'${POST_STRICT_TAG}' runs after strict mode has been applied, so a filter on it can put back what strict mode removed`);
		}
	}

	for (const [tag, callbacks] of Object.entries(result.actions_added || {})) {
		add('review', `also hooks the action '${tag}' with ${callbacks.join(', ')}`);
	}

	for (const [directive, sources] of Object.entries(result.added || {})) {
		for (const source of sources) {
			const hostWildcard = /(?:^|:\/\/)\*\.[a-z0-9-]/i.test(source);
			if (source.includes('*') && !hostWildcard) {
				add('block', `adds the source ${source} to ${directive}, which allows every host`);
			} else if (hostWildcard) {
				add('review', `adds the wildcard ${source} to ${directive}, which covers every subdomain of it`);
			} else if (/^(?:self|none|unsafe-inline|unsafe-eval|unsafe-hashes|strict-dynamic|report-sample)$/i.test(source)) {
				add('block', `adds ${source} to ${directive} without its single quotes, so a browser reads a host named "${source}" instead of the keyword`);
			} else if (source === "'unsafe-inline'" || source === "'unsafe-eval'") {
				add('review', `adds ${source} to ${directive}, undoing what strict mode removes`);
			} else if (/^http:\/\//i.test(source)) {
				add('review', `adds a plain-http source to ${directive}: ${source}, which an HTTPS page cannot use`);
			} else if (!/^https:\/\/[a-z0-9.-]+/i.test(source) && !/^'/.test(source) && !/^[a-z][a-z0-9+.-]*:$/i.test(source) && !/^[a-z0-9.*-]+(:\d+)?$/i.test(source)) {
				add('review', `adds an unusual source to ${directive}: ${source}`);
			} else {
				add('note', `${directive} gains ${source}`);
			}
		}
	}

	for (const [directive, sources] of Object.entries(result.removed || {})) {
		add('review', `${directive} loses ${sources.join(', ')} - if that is not deliberate, the snippet is overwriting instead of appending`);
	}
	const lost = result.directives_lost || [];
	const had = Object.keys(result.before || {}).length;
	if (lost.length > 0 && had > 0 && lost.length === had) {
		add('block', `all ${had} directives disappear: the filter returns something that is not the policy, so no CSP is sent at all`);
	} else {
		for (const directive of lost) {
			add('block', `the directive ${directive} disappears from the policy entirely`);
		}
	}
	for (const directive of result.directives_new || []) {
		add('review', `adds a directive the plugin does not send by default: ${directive}`);
	}

	if (result.strict_unsafe_inline) {
		add('block', `under lkc_csp_strict the policy still contains ${JSON.stringify(result.strict_added)} - the snippet defeats strict mode`);
	}

	if (result.header_after === '') {
		add('block', 'the rendered header is empty, which means no policy is sent at all');
	} else if (result.header_before && result.header_after.length < result.header_before.length - 40) {
		add('review', `the rendered header shrank from ${result.header_before.length} to ${result.header_after.length} characters`);
	}

	const before = Object.keys(result.before || {}).length;
	const after = Object.keys(result.after || {}).length;
	if (after < before) {
		add('review', `the policy went from ${before} directives to ${after}`);
	}

	return findings;
}

/**
 * Decide the exit status from a list of findings.
 *
 * @param {Array<object>} findings Findings from both scanners.
 * @returns {number} 0 clean, 1 review, 2 blocked.
 */
export function verdict(findings) {
	if (findings.some((finding) => finding.severity === 'block')) {
		return 2;
	}
	if (findings.some((finding) => finding.severity === 'review')) {
		return 1;
	}
	return 0;
}

const VERDICT_TEXT = {
	0: 'SAFE TO PASTE — the snippet only adds sources, and the header still builds.',
	1: 'NEEDS A HUMAN DECISION — nothing is broken, but something below is not a plain allowlist entry.',
	2: 'DO NOT PASTE — the snippet is broken or would weaken the policy.',
};

/**
 * Render the review as text.
 *
 * @param {object} review Review object from review().
 * @returns {string}
 */
export function renderReview(review) {
	const out = [];
	out.push(`\n${review.file}`);
	out.push(`  ${review.bytes} bytes, ${review.lines} lines, parsed with PHP ${review.phpVersion}`);

	if (review.sandbox.parse === 'error' || review.sandbox.fatal) {
		out.push('\n  The file never ran: see the blocked findings below.');
	} else {
		const added = Object.entries(review.sandbox.added || {});
		const removed = Object.entries(review.sandbox.removed || {});
		out.push(`\n  policy before: ${Object.keys(review.sandbox.before).length} directives   after: ${Object.keys(review.sandbox.after).length} directives`);
		if (added.length === 0 && removed.length === 0 && (review.sandbox.directives_lost || []).length === 0 && (review.sandbox.directives_new || []).length === 0) {
			out.push('  the resulting policy is identical to the shipped default');
		}
		for (const [directive, sources] of added) {
			out.push(`  + ${directive}: ${sources.join(' ')}`);
		}
		for (const [directive, sources] of removed) {
			out.push(`  - ${directive}: ${sources.join(' ')}`);
		}
		out.push(`\n  header it produces (${review.sandbox.header_after.length} characters):`);
		out.push(`    ${review.sandbox.header_after}`);
	}

	if (review.findings.length === 0) {
		out.push('\n  no findings');
	} else {
		for (const severity of ['block', 'review', 'note']) {
			const items = review.findings.filter((finding) => finding.severity === severity);
			if (items.length === 0) {
				continue;
			}
			out.push(`\n  ${severity.toUpperCase()} (${items.length}):`);
			for (const item of items) {
				out.push(`    ${item.line ? `line ${item.line}  ` : 'sandbox     '}${item.why}`);
				if (item.text) {
					out.push(`                  ${item.text}`);
				}
			}
		}
	}

	out.push(`\n  ${VERDICT_TEXT[review.status]}`);
	return `${out.join('\n')}\n`;
}

/**
 * Review several snippets in one sandbox boot.
 *
 * @param {string[]} files Paths to the snippets.
 * @param {object} options { phpVersion }
 * @returns {Promise<object[]>} One review per file: file, bytes, lines, findings, sandbox, status.
 */
export async function reviewFiles(files, options = {}) {
	const phpVersion = options.phpVersion || '8.2';
	const sources = new Map(files.map((file) => [file, readFileSync(file, 'utf8')]));
	const results = await evaluateSnippets(sources, phpVersion);

	return files.map((file) => {
		const source = sources.get(file);
		const sandbox = results.get(file);
		const findings = [...scanStructure(source), ...scanSnippet(source), ...dynamicFindings(sandbox)];

		return {
			file,
			bytes: Buffer.byteLength(source),
			lines: source.split('\n').length,
			phpVersion,
			findings,
			sandbox,
			status: verdict(findings),
		};
	});
}

/**
 * Review one file.
 *
 * @param {string} file Path to the snippet.
 * @param {object} options { phpVersion }
 * @returns {Promise<object>} Review object.
 */
export async function review(file, options = {}) {
	const [result] = await reviewFiles([file], options);
	return result;
}

/**
 * Command line entry point.
 *
 * @param {string[]} args Argument list.
 * @returns {Promise<number>} Exit status.
 */
export async function main(args) {
	const positional = [];
	let json = false;
	let phpVersion = '8.2';

	for (const arg of args) {
		if (arg === '--json') {
			json = true;
		} else if (arg === '--help' || arg === '-h') {
			stdout.write(`${usageText()}\n`);
			return 0;
		} else if (arg.startsWith('--php=')) {
			phpVersion = arg.slice('--php='.length);
		} else if (arg.startsWith('--')) {
			stderr.write(`unknown option: ${arg}\n`);
			return 2;
		} else {
			positional.push(arg);
		}
	}

	if (positional.length === 0) {
		stdout.write(`${usageText()}\n`);
		return 2;
	}

	for (const file of positional) {
		if (!existsSync(file)) {
			stderr.write(`snippet not found: ${file}\n`);
			return 2;
		}
	}

	const reviews = await reviewFiles(positional, { phpVersion });
	let worst = 0;
	for (const result of reviews) {
		if (json) {
			stdout.write(`${JSON.stringify({ ...result, verdict: VERDICT_TEXT[result.status] }, null, 2)}\n`);
		} else {
			stdout.write(renderReview(result));
		}
		worst = Math.max(worst, result.status);
	}

	return worst;
}

/**
 * The header comment of this file, which is the usage text.
 *
 * @returns {string}
 */
function usageText() {
	return readFileSync(fileURLToPath(import.meta.url), 'utf8')
		.split('\n')
		.slice(2, 30)
		.filter((line) => line.trim() !== '*/')
		.map((line) => line.replace(/^ ?\* ?/, ''))
		.join('\n');
}

const isMain = !!process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href;

if (isMain) {
	main(argv.slice(2)).then(
		(code) => exit(code),
		(error) => {
			stderr.write(`${error.stack || error.message}\n`);
			exit(2);
		}
	);
}

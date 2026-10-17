#!/usr/bin/env node
/**
 * Static Content-Security-Policy auditor for a rendered page.
 *
 * The report-only phase of the plugin's CSP collects violations from real
 * browsers, which is authoritative but slow: it only sees the pages somebody
 * actually visited, with the extensions they happened to have. This tool does
 * the other half. Point it at a page and a policy and it tells you, before you
 * switch `lkc_csp_mode` to `'enforce'`, exactly which resources that page would
 * lose.
 *
 * It is a scanner, not a browser. It reads the policy and the markup with the
 * same matching rules a browser applies (scheme sources, host wildcards, ports,
 * `'self'`, `'none'`, nonces and hashes, and the `-elem`/`-attr` fallback chain
 * to `default-src`), but it cannot see resources injected at runtime by
 * third-party scripts. Treat its output as a pre-flight check and the CSP report
 * tab as the ground truth.
 *
 * Usage:
 *   node tests/js/csp-audit.mjs --url=https://staging.example/ [--url=...]
 *   node tests/js/csp-audit.mjs --html=page.html [--policy="default-src 'self'"]
 *   cat page.html | node tests/js/csp-audit.mjs --policy-file=header.txt
 *
 * Options:
 *   --url=URL         fetch the page, and use its own CSP response header
 *   --html=FILE       audit a saved HTML file (repeatable)
 *   --policy=VALUE    the policy to test against; without it the header found on
 *                     the response is used, or the plugin's shipped default
 *   --policy-file=F   read the policy from a file (paste the header value)
 *   --document-url=U  the URL the markup came from, so 'self' resolves; needed
 *                     for --html and stdin, taken from the response for --url
 *   --strict          evaluate as if lkc_csp_strict were on: 'unsafe-inline' is
 *                     ignored, so inline markup must carry a nonce
 *   --format=json     machine-readable output
 *   --quiet           only print pages that would break
 *
 * Exit status: 0 nothing would be blocked, 1 something would be blocked,
 * 2 bad usage or an unreadable input.
 */

import { readFileSync, existsSync } from 'node:fs';
import { argv, exit, stdin, stdout, stderr } from 'node:process';
import { pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';

/* ------------------------------------------------------------------ *
 * Policy parsing
 * ------------------------------------------------------------------ */

/**
 * The order a browser consults directives in.
 *
 * The `-elem` and `-attr` variants fall back to their parent directive first and
 * only then to default-src, so `script-src 'unsafe-inline'` does cover an
 * onclick= attribute. Getting this wrong produces false alarms about inline
 * handlers and style attributes that the browser would in fact allow.
 */
const DIRECTIVE_CHAINS = {
	'script-src-elem': ['script-src-elem', 'script-src', 'default-src'],
	'script-src-attr': ['script-src-attr', 'script-src', 'default-src'],
	'style-src-elem': ['style-src-elem', 'style-src', 'default-src'],
	'style-src-attr': ['style-src-attr', 'style-src', 'default-src'],
	'frame-src': ['frame-src', 'child-src', 'default-src'],
	'worker-src': ['worker-src', 'child-src', 'default-src'],
	'child-src': ['child-src', 'default-src'],
};

/**
 * Parse a Content-Security-Policy header value.
 *
 * @param {string} header Raw header value.
 * @returns {Map<string, string[]>} directive => source expressions.
 */
export function parsePolicy(header) {
	const policy = new Map();
	if (typeof header !== 'string' || header.trim() === '') {
		return policy;
	}

	for (const rawDirective of header.split(';')) {
		const tokens = rawDirective.trim().split(/\s+/).filter(Boolean);
		if (tokens.length === 0) {
			continue;
		}
		const name = tokens[0].toLowerCase();
		const sources = tokens.slice(1);
		if (!policy.has(name)) {
			policy.set(name, sources);
		}
	}

	return policy;
}

/**
 * The directive list a browser actually consults for a resource kind.
 *
 * @param {Map<string, string[]>} policy Parsed policy.
 * @param {string} directive Primary directive name.
 * @returns {{directive: string, sources: string[]|null}} The first directive present.
 */
export function effectiveDirective(policy, directive) {
	const chain = DIRECTIVE_CHAINS[directive] || [directive, 'default-src'];

	for (const candidate of chain) {
		if (policy.has(candidate)) {
			return { directive: candidate, sources: policy.get(candidate) };
		}
	}

	return { directive, sources: null };
}

/* ------------------------------------------------------------------ *
 * Source matching
 * ------------------------------------------------------------------ */

const DEFAULT_PORTS = { 'http:': '80', 'https:': '443', 'ws:': '80', 'wss:': '443' };

/**
 * Split a URL into the parts the matcher needs.
 *
 * @param {string} raw URL as written in the markup.
 * @param {string} documentUrl Absolute URL of the page being audited.
 * @returns {object|null} Normalised parts, or null for unparsable URLs.
 */
export function normaliseUrl(raw, documentUrl) {
	const value = String(raw || '').trim();
	if (value === '') {
		return null;
	}

	let url;
	try {
		url = new URL(value, documentUrl || undefined);
	} catch (error) {
		return null;
	}

	const scheme = `${url.protocol}`;
	const host = url.hostname.toLowerCase();
	let port = url.port;
	if (port === '' && DEFAULT_PORTS[scheme]) {
		port = DEFAULT_PORTS[scheme];
	}

	return { scheme, host, port, pathname: url.pathname, href: url.href, origin: url.origin };
}

/**
 * Whether one host matches a CSP host-source part.
 *
 * A bare host matches only itself; `*.example.com` matches the apex and every
 * subdomain, which is what Chrome, Firefox and Safari all do.
 *
 * @param {string} sourceHost Host part of the source expression.
 * @param {string} host Host of the URL.
 * @returns {boolean}
 */
export function hostMatches(sourceHost, host) {
	const source = String(sourceHost).toLowerCase();
	const target = String(host).toLowerCase();

	if (source === '*') {
		return true;
	}
	if (source.startsWith('*.')) {
		const suffix = source.slice(2);
		return target === suffix || target.endsWith(`.${suffix}`);
	}
	return target === source;
}

/**
 * Break a CSP source expression into its parts.
 *
 * URL cannot parse a wildcard host (`https://*.example.com` is not a valid
 * URL), so this does the small amount of splitting the matcher needs by hand.
 *
 * @param {string} expression Source expression, e.g. `*.example.com:443`.
 * @returns {{kind: string, scheme: string|null, host: string, port: string}}
 */
export function parseSourceExpression(expression) {
	const value = String(expression).trim();

	if (value === '' || value === '*') {
		return { kind: 'host', scheme: null, host: '*', port: '' };
	}
	/* A bare scheme source: https:, data:, blob:, filesystem:. */
	if (/^[a-z][a-z0-9+.-]*:$/i.test(value)) {
		return { kind: 'scheme', scheme: value.toLowerCase(), host: '', port: '' };
	}
	/* `*:8080` is any host on that port; the remainder is the port, not a host. */
	if (value.startsWith('*:')) {
		return { kind: 'host', scheme: null, host: '*', port: value.slice(2) };
	}

	let scheme = null;
	let rest = value;

	const schemeMatch = /^([a-z][a-z0-9+.-]*):\/\//i.exec(value);
	if (schemeMatch) {
		scheme = `${schemeMatch[1].toLowerCase()}:`;
		rest = value.slice(schemeMatch[0].length);
	}

	/* Only host and port matter for matching; drop any path. */
	rest = rest.replace(/\/.*$/, '');

	let host = rest;
	let port = '';
	if (rest.startsWith('[')) {
		const end = rest.indexOf(']');
		if (end > -1) {
			host = rest.slice(0, end + 1);
			if (rest.slice(end + 1).startsWith(':')) {
				port = rest.slice(end + 2);
			}
		}
	} else {
		const colon = rest.lastIndexOf(':');
		if (colon > -1) {
			host = rest.slice(0, colon);
			port = rest.slice(colon + 1);
		}
	}

	return { kind: 'host', scheme, host: host.toLowerCase(), port };
}

/**
 * Whether a single source expression permits a URL.
 *
 * @param {string} source Source expression from the policy.
 * @param {object} url Normalised URL parts.
 * @param {string} documentUrl Absolute URL of the page.
 * @returns {boolean}
 */
export function sourceMatchesUrl(source, url, documentUrl) {
	const expression = String(source).trim();
	if (expression === '' || expression === "'none'") {
		return false;
	}
	if (expression === "'self'") {
		const self = normaliseUrl('./', documentUrl);
		return !!self && self.origin === url.origin;
	}

	const parsed = parseSourceExpression(expression);

	if (parsed.kind === 'scheme') {
		return parsed.scheme === url.scheme;
	}
	if (parsed.scheme && parsed.scheme !== url.scheme) {
		return false;
	}
	if (!hostMatches(parsed.host, url.host)) {
		return false;
	}

	if (parsed.port === '*') {
		return true;
	}
	if (parsed.port === '') {
		/* No port in the source: only the scheme's default port is allowed. */
		return url.port === (DEFAULT_PORTS[url.scheme] || '');
	}
	return parsed.port === url.port;
}

/**
 * Decide whether one resource is allowed by a policy.
 *
 * @param {object} resource Resource descriptor from scanHtml().
 * @param {Map<string, string[]>} policy Parsed policy.
 * @param {object} options { documentUrl, strict }
 * @returns {{allowed: boolean, reason: string, directive: string, sources: string[]|null}}
 */
export function evaluate(resource, policy, options = {}) {
	const documentUrl = options.documentUrl || 'https://example.test/';
	const strict = !!options.strict;
	const { directive, sources } = effectiveDirective(policy, resource.directive);

	if (sources === null) {
		return { allowed: true, reason: 'no directive and no default-src, so nothing is restricted', directive, sources };
	}
	if (sources.includes("'none'")) {
		return { allowed: false, reason: `${directive} is 'none'`, directive, sources };
	}

	/* Inline markup: keywords, nonces and hashes decide it, never a host list. */
	if (resource.inline) {
		const usesNonceOrHash = sources.some((source) => /'nonce-[^']+'|'sha(?:256|384|512)-[^']+'/.test(source));
		const allowsInlineKeyword = sources.includes("'unsafe-inline'") && !(strict || usesNonceOrHash);
		const isAttribute = resource.kind === 'style-attribute' || resource.kind === 'inline-handler';
		const nonceMatches = !!resource.nonce && sources.includes(`'nonce-${resource.nonce}'`);
		const hashMatches = !!resource.hash && sources.some((source) => source.replace(/^'|'$/g, '') === resource.hash);
		/* A nonce never covers an attribute, and a hash only does with 'unsafe-hashes'. */
		const hashApplies = hashMatches && (!isAttribute || sources.includes("'unsafe-hashes'"));

		if (allowsInlineKeyword) {
			return { allowed: true, reason: `'unsafe-inline' is present in ${directive}`, directive, sources };
		}
		if (!isAttribute && nonceMatches) {
			return { allowed: true, reason: `nonce matches ${directive}`, directive, sources };
		}
		if (hashApplies) {
			return { allowed: true, reason: `hash matches ${directive}`, directive, sources };
		}

		let reason;
		if (isAttribute) {
			/* True whatever else the policy says: browsers ignore nonces here. */
			reason = `a nonce cannot cover an attribute: ${directive} would need 'unsafe-inline'${strict ? ' (dropped in strict mode)' : ''}, or 'unsafe-hashes' plus '${resource.hash}'`;
		} else if (strict) {
			reason = `'unsafe-inline' was dropped from ${directive} (strict mode) and this ${resource.kind} carries no matching nonce`;
		} else if (usesNonceOrHash) {
			reason = `${directive} uses a nonce or hash, so 'unsafe-inline' is ignored and this ${resource.kind} matches neither`;
		} else {
			reason = `${directive} allows neither 'unsafe-inline' nor a matching nonce/hash`;
		}

		return { allowed: false, reason, directive, sources };
	}

	if (!resource.url) {
		return { allowed: true, reason: 'nothing to check', directive, sources };
	}

	const url = normaliseUrl(resource.url, documentUrl);
	if (!url) {
		return { allowed: false, reason: 'the URL could not be parsed', directive, sources };
	}

	for (const source of sources) {
		if (sourceMatchesUrl(source, url, documentUrl)) {
			return { allowed: true, reason: `matched ${source} in ${directive}`, directive, sources };
		}
	}

	return { allowed: false, reason: `no source in ${directive} matches ${url.href}`, directive, sources };
}

/* ------------------------------------------------------------------ *
 * HTML scanning
 * ------------------------------------------------------------------ */

/** Which directive governs each <link rel="preload" as="..."> value. */
const PRELOAD_AS = {
	script: 'script-src',
	worker: 'worker-src',
	style: 'style-src',
	font: 'font-src',
	image: 'img-src',
	audio: 'media-src',
	video: 'media-src',
	track: 'media-src',
	fetch: 'connect-src',
	xhr: 'connect-src',
	eventsource: 'connect-src',
	embed: 'object-src',
	object: 'object-src',
	document: 'frame-src',
};

/** Script MIME types that are data blocks rather than executable code. */
const NON_EXECUTABLE_TYPES = ['application/ld+json', 'application/json', 'text/template', 'text/html', 'text/plain', 'importmap'];

/**
 * The CSP hash of some inline content, the way a browser computes it.
 *
 * For an element the input is the exact text between its tags; for an attribute
 * it is the attribute value. Whitespace matters, so nothing is trimmed.
 *
 * @param {string} content Inline source text.
 * @returns {string} e.g. sha256-Base64Digest
 */
export function cspHash(content) {
	return `sha256-${createHash('sha256').update(String(content), 'utf8').digest('base64')}`;
}

/**
 * Line number of a character offset.
 *
 * @param {string} text Source text.
 * @param {number} index Character offset.
 * @returns {number} 1-based line number.
 */
function lineOf(text, index) {
	let line = 1;
	for (let i = 0; i < index && i < text.length; i += 1) {
		if (text[i] === '\n') {
			line += 1;
		}
	}
	return line;
}

/**
 * Read one attribute out of a tag's attribute text.
 *
 * @param {string} attributes Raw attribute text.
 * @param {string} name Attribute name.
 * @returns {string|null}
 */
export function attribute(attributes, name) {
	const match = new RegExp(`(?:^|\\s)${name}\\s*=\\s*("([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i').exec(attributes || '');
	if (!match) {
		return null;
	}
	return (match[2] ?? match[3] ?? match[4] ?? '').trim();
}

/**
 * Find every policy-relevant resource in an HTML document.
 *
 * @param {string} html Document markup.
 * @param {string} documentUrl Absolute URL of the document, for 'self'.
 * @returns {Array<object>} Resource descriptors.
 */
export function scanHtml(html, documentUrl) {
	const text = String(html || '');
	const findings = [];

	const push = (index, resource) => {
		findings.push({ ...resource, line: lineOf(text, index) });
	};

	/* <script> — external, inline executable, and data blocks. */
	const scriptPattern = /<script\b([^>]*)>([\s\S]*?)<\/script\s*>|<script\b([^>]*)\/?>/gi;
	for (const match of text.matchAll(scriptPattern)) {
		const attributes = match[1] ?? match[3] ?? '';
		const body = match[2] ?? '';
		const index = match.index;
		const src = attribute(attributes, 'src');
		const type = (attribute(attributes, 'type') || '').toLowerCase();

		if (src) {
			push(index, { kind: 'script', directive: 'script-src', url: src, inline: false });
			continue;
		}
		if (type && NON_EXECUTABLE_TYPES.includes(type.split(';')[0].trim())) {
			push(index, { kind: 'data-block', directive: null, url: null, inline: false, note: `type="${type}" is a data block, not executable code` });
			continue;
		}
		if (body.trim() === '') {
			continue;
		}
		push(index, {
			kind: 'inline-script',
			directive: 'script-src',
			url: null,
			inline: true,
			nonce: attribute(attributes, 'nonce'),
			hash: cspHash(body),
			note: body.trim().slice(0, 90),
		});
	}

	/* Inline event handlers are governed by script-src-attr and cannot be nonced. */
	const handlerPattern = /<([a-z][a-z0-9-]*)\b([^>]*?\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+))[^>]*>/gi;
	for (const match of text.matchAll(handlerPattern)) {
		const handlerValue = (match[3] || '').replace(/^["']|["']$/g, '');
		push(match.index, {
			kind: 'inline-handler',
			directive: 'script-src-attr',
			url: null,
			inline: true,
			hash: cspHash(handlerValue),
			note: match[2].trim().slice(0, 90),
		});
	}

	/* <link> — stylesheets, preload, preconnect, manifest, icon. */
	const linkPattern = /<link\b([^>]*)>/gi;
	for (const match of text.matchAll(linkPattern)) {
		const attributes = match[1] || '';
		const href = attribute(attributes, 'href');
		if (!href) {
			continue;
		}
		const rel = (attribute(attributes, 'rel') || '').toLowerCase();
		const as = (attribute(attributes, 'as') || '').toLowerCase();

		if (rel.includes('stylesheet')) {
			push(match.index, { kind: 'stylesheet', directive: 'style-src', url: href, inline: false });
		} else if (rel.includes('preload')) {
			push(match.index, { kind: `preload:${as || 'unknown'}`, directive: PRELOAD_AS[as] || 'default-src', url: href, inline: false });
		} else if (rel.includes('preconnect') || rel.includes('dns-prefetch')) {
			push(match.index, { kind: `link:${rel}`, directive: 'connect-src', url: href, inline: false });
		} else if (rel.includes('manifest')) {
			push(match.index, { kind: 'manifest', directive: 'manifest-src', url: href, inline: false });
		} else if (rel.includes('modulepreload')) {
			push(match.index, { kind: 'modulepreload', directive: 'script-src', url: href, inline: false });
		} else if (rel.includes('icon') || rel.includes('apple-touch')) {
			push(match.index, { kind: 'icon', directive: 'img-src', url: href, inline: false });
		} else if (rel.includes('prefetch')) {
			push(match.index, { kind: 'prefetch', directive: 'default-src', url: href, inline: false });
		}
	}

	/* <style> elements, which a nonce can cover. */
	for (const match of text.matchAll(/<style\b([^>]*)>([\s\S]*?)<\/style\s*>/gi)) {
		if ((match[2] || '').trim() === '') {
			continue;
		}
		push(match.index, {
			kind: 'inline-style',
			directive: 'style-src',
			url: null,
			inline: true,
			nonce: attribute(match[1] || '', 'nonce'),
			hash: cspHash(match[2] || ''),
			note: match[2].trim().slice(0, 90),
		});
	}

	/* style="..." attributes: no nonce can ever cover these. */
	for (const match of text.matchAll(/<[a-z][^>]*?\sstyle\s*=\s*("[^"]*"|'[^']*')[^>]*>/gi)) {
		const styleValue = (match[1] || '').replace(/^["']|["']$/g, '');
		push(match.index, {
			kind: 'style-attribute',
			directive: 'style-src-attr',
			url: null,
			inline: true,
			hash: cspHash(styleValue),
			note: styleValue.slice(0, 90),
		});
	}

	/* Images, including srcset candidates. */
	for (const match of text.matchAll(/<img\b([^>]*)>/gi)) {
		const attributes = match[1] || '';
		const src = attribute(attributes, 'src');
		if (src) {
			push(match.index, { kind: 'image', directive: 'img-src', url: src, inline: false });
		}
		const srcset = attribute(attributes, 'srcset');
		if (srcset) {
			for (const candidate of srcset.split(',')) {
				const url = candidate.trim().split(/\s+/)[0];
				if (url) {
					push(match.index, { kind: 'image-srcset', directive: 'img-src', url, inline: false });
				}
			}
		}
	}

	/* Media. A poster attribute is an image, so img-src governs it. */
	for (const match of text.matchAll(/<(video|audio|source|track)\b([^>]*)>/gi)) {
		const attributes = match[2] || '';
		const tag = match[1].toLowerCase();
		for (const name of ['src', 'href']) {
			const value = attribute(attributes, name);
			if (value) {
				push(match.index, { kind: tag, directive: 'media-src', url: value, inline: false });
			}
		}
		const poster = attribute(attributes, 'poster');
		if (poster) {
			push(match.index, { kind: `${tag}-poster`, directive: 'img-src', url: poster, inline: false });
		}
	}

	/* Frames, objects and forms. */
	for (const match of text.matchAll(/<(iframe|frame|embed|object|form|base)\b([^>]*)>/gi)) {
		const tag = match[1].toLowerCase();
		const attributes = match[2] || '';
		const value = attribute(attributes, tag === 'form' ? 'action' : tag === 'object' ? 'data' : 'href') || attribute(attributes, 'src');
		if (!value) {
			continue;
		}
		const directive = { iframe: 'frame-src', frame: 'frame-src', embed: 'object-src', object: 'object-src', form: 'form-action', base: 'base-uri' }[tag];
		push(match.index, { kind: tag, directive, url: value, inline: false });
	}

	/* Dangerous URL schemes deserve their own note whatever the policy says. */
	for (const match of text.matchAll(/\s(?:src|href|action|data|poster)\s*=\s*("\s*(javascript|data|vbscript):[^"]*"|'\s*(javascript|data|vbscript):[^']*')/gi)) {
		push(match.index, {
			kind: 'dangerous-scheme',
			directive: null,
			url: match[1].slice(0, 120),
			inline: false,
			note: `${(match[2] || match[3] || '').toLowerCase()}: URL in markup`,
		});
	}

	return findings;
}

/* ------------------------------------------------------------------ *
 * Reporting
 * ------------------------------------------------------------------ */

/**
 * Audit one document against one policy.
 *
 * @param {string} html Document markup.
 * @param {string} policyHeader Content-Security-Policy header value.
 * @param {object} options { documentUrl, strict, label }
 * @returns {object} Report: label, documentUrl, policy, resources, blocked, notes.
 */
export function auditDocument(html, policyHeader, options = {}) {
	const documentUrl = options.documentUrl || 'https://example.test/';
	const strict = !!options.strict;
	const policy = parsePolicy(policyHeader);
	const resources = [];

	for (const resource of scanHtml(html, documentUrl)) {
		if (resource.directive === null) {
			resources.push({ ...resource, allowed: true, reason: resource.note || 'not governed by the policy' });
			continue;
		}
		const verdict = evaluate(resource, policy, { documentUrl, strict });
		resources.push({ ...resource, ...verdict });
	}

	const blocked = resources.filter((resource) => !resource.allowed);

	/* Aggregate blocked hosts per directive so the output can become a filter. */
	const allowlist = new Map();
	for (const resource of blocked) {
		if (resource.inline || !resource.url) {
			continue;
		}
		const url = normaliseUrl(resource.url, documentUrl);
		if (!url || !url.host) {
			continue;
		}
		const key = resource.directive;
		if (!allowlist.has(key)) {
			allowlist.set(key, new Map());
		}
		const hosts = allowlist.get(key);
		hosts.set(url.host, (hosts.get(url.host) || 0) + 1);
	}

	const notes = [];
	const inlineBlocked = blocked.filter((resource) => resource.inline);
	const styleAttributes = inlineBlocked.filter((resource) => resource.kind === 'style-attribute');
	const handlers = inlineBlocked.filter((resource) => resource.kind === 'inline-handler');
	const inlineScripts = inlineBlocked.filter((resource) => resource.kind === 'inline-script');
	const inlineStyles = inlineBlocked.filter((resource) => resource.kind === 'inline-style');

	if (inlineScripts.length || inlineStyles.length) {
		notes.push(`${inlineScripts.length} inline <script> and ${inlineStyles.length} inline <style> element(s) would be blocked. lkc_csp_strict gives these a nonce automatically - that is the fix, not an allowlist.`);
	}
	if (styleAttributes.length) {
		notes.push(`${styleAttributes.length} style="..." attribute(s) would be blocked. A nonce cannot cover an attribute, so move the declaration into a stylesheet; 'unsafe-hashes' plus the hash shown above works but weakens the policy.`);
	}
	if (handlers.length) {
		notes.push(`${handlers.length} inline event handler(s) (onclick= and friends) would be blocked. A nonce cannot cover an attribute either: move the handler into a script with addEventListener, or accept 'unsafe-hashes' plus the hash above.`);
	}
	if (blocked.some((resource) => resource.kind === 'dangerous-scheme')) {
		notes.push('A javascript:, data: or vbscript: URL appears in the markup. That is a finding in its own right, independent of the policy.');
	}
	const mixed = resources.filter((resource) => resource.url && /^http:\/\//i.test(String(resource.url).trim()));
	if (mixed.length) {
		notes.push(`${mixed.length} resource(s) are loaded over plain http://. On an HTTPS page the browser blocks these as mixed content before the policy is even consulted.`);
	}
	if (policy.size === 0) {
		notes.push('No policy was supplied or found, so nothing was checked.');
	}

	return {
		label: options.label || documentUrl,
		documentUrl,
		policy: policyHeader || '',
		directives: Object.fromEntries(policy),
		strict,
		counts: {
			resources: resources.length,
			blocked: blocked.length,
			allowed: resources.length - blocked.length,
		},
		resources,
		blocked,
		allowlist: Object.fromEntries([...allowlist].map(([directive, hosts]) => [directive, Object.fromEntries(hosts)])),
		notes,
	};
}

/**
 * Build the lkc_content_security_policy filter that would unblock the hosts
 * found. Mirrors what the plugin's admin screen generates from live reports, so
 * both routes produce the same shape of snippet.
 *
 * @param {object[]} reports Reports from auditDocument().
 * @returns {string} PHP snippet.
 */
export function suggestFilterSnippet(reports) {
	const merged = new Map();
	for (const report of reports) {
		for (const [directive, hosts] of Object.entries(report.allowlist || {})) {
			if (!merged.has(directive)) {
				merged.set(directive, new Map());
			}
			const target = merged.get(directive);
			for (const [host, count] of Object.entries(hosts)) {
				target.set(host, (target.get(host) || 0) + count);
			}
		}
	}

	const lines = [
		"add_filter( 'lkc_content_security_policy', function ( $policy ) {",
		'\t/* From `node tests/js/csp-audit.mjs`. Drop any line for a domain you do not recognise. */',
	];

	if (merged.size === 0) {
		lines.push('\t/* No external host needs allowlisting; the blockers on these pages are inline, which lkc_csp_strict handles. */');
	}

	for (const directive of [...merged.keys()].sort()) {
		const hosts = [...merged.get(directive).entries()].sort((a, b) => b[1] - a[1]);
		for (const [host, count] of hosts) {
			lines.push(`\t$policy['${directive}'][] = 'https://${host}'; // ${count} reference(s)`);
		}
	}

	lines.push('\treturn $policy;');
	lines.push('} );');
	return lines.join('\n');
}

/**
 * Render one report as text.
 *
 * @param {object} report Report from auditDocument().
 * @returns {string}
 */
export function renderReport(report) {
	const out = [];
	out.push(`\n${report.label}`);
	out.push(`  policy: ${report.policy ? `${Object.keys(report.directives).length} directive(s)${report.strict ? ', evaluated in strict mode' : ''}` : 'none found'}`);
	out.push(`  resources checked: ${report.counts.resources}   would be blocked: ${report.counts.blocked}`);

	if (report.blocked.length === 0) {
		out.push('  nothing on this page would be blocked by enforce mode');
	} else {
		const byDirective = new Map();
		for (const resource of report.blocked) {
			const key = resource.directive;
			if (!byDirective.has(key)) {
				byDirective.set(key, []);
			}
			byDirective.get(key).push(resource);
		}
		for (const [directive, items] of byDirective) {
			out.push(`\n  ${directive} (${items.length}):`);
			for (const item of items) {
				const what = item.inline ? item.kind : item.url;
				out.push(`    line ${item.line}  ${what}`);
				out.push(`      ${item.reason}`);
				if (item.inline && item.hash) {
					out.push(`      hash '${item.hash}'`);
				}
			}
		}
	}

	for (const note of report.notes) {
		out.push(`\n  note: ${note}`);
	}

	return out.join('\n');
}

/* ------------------------------------------------------------------ *
 * CLI
 * ------------------------------------------------------------------ */

/**
 * The plugin's shipped default policy, so the tool is useful before a site has
 * configured any filter. Kept in sync with lkc_get_csp_directives().
 *
 * @returns {string}
 */
export function shippedDefaultPolicy() {
	return [
		"default-src 'self'",
		"script-src 'self' 'unsafe-inline'",
		"style-src 'self' 'unsafe-inline'",
		"img-src 'self' data: https:",
		"media-src 'self' https: blob:",
		"font-src 'self' data:",
		"connect-src 'self'",
		"frame-src 'self'",
		"object-src 'none'",
		"base-uri 'self'",
		"form-action 'self'",
		"frame-ancestors 'self'",
	].join('; ');
}

/**
 * Read all of stdin, synchronously enough for a CLI.
 *
 * @returns {Promise<string>}
 */
async function readStdin() {
	if (stdin.isTTY) {
		return '';
	}
	const chunks = [];
	for await (const chunk of stdin) {
		chunks.push(chunk);
	}
	return Buffer.concat(chunks).toString('utf8');
}

/**
 * Parse argv into an options object.
 *
 * @param {string[]} args Argument list.
 * @returns {object}
 */
export function parseArgs(args) {
	const options = { urls: [], files: [], policy: '', policyFile: '', documentUrl: '', html: '', strict: false, json: false, quiet: false, help: false };

	for (const arg of args) {
		const [flag, ...rest] = String(arg).split('=');
		const value = rest.join('=');
		switch (flag) {
			case '--url': options.urls.push(value); break;
			case '--html': options.files.push(value); break;
			case '--policy': options.policy = value; break;
			case '--policy-file': options.policyFile = value; break;
			case '--document-url': options.documentUrl = value; break;
			case '--strict': options.strict = true; break;
			case '--format': options.json = value === 'json'; break;
			case '--json': options.json = true; break;
			case '--quiet': options.quiet = true; break;
			case '-h': case '--help': options.help = true; break;
			default:
				if (!String(arg).startsWith('--')) {
					options.files.push(String(arg));
				} else {
					throw new Error(`unknown option: ${arg}`);
				}
		}
	}

	return options;
}

/**
 * The header comment of this file, which is the usage text.
 *
 * @returns {string}
 */
function usageText() {
	return readFileSync(new URL(import.meta.url), 'utf8')
		.split('\n')
		.slice(2, 36)
		.filter((line) => line.trim() !== '*/')
		.map((line) => line.replace(/^ ?\* ?/, ''))
		.join('\n');
}

/** An error whose message is a usage or input problem, not a bug. */
class CliError extends Error {}

const isMain = !!process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href;

/**
 * Collect one report per requested document.
 *
 * @param {object} options Options from parseArgs(), optionally carrying html from stdin.
 * @returns {Promise<object[]>} Reports, in the order they were requested.
 */
export async function collectReports(options) {
	if (options.policyFile) {
		if (!existsSync(options.policyFile)) {
			throw new CliError(`policy file not found: ${options.policyFile}`);
		}
		options.policy = readFileSync(options.policyFile, 'utf8').trim();
	}

	const documentUrl = options.documentUrl || 'https://example.test/';
	const reports = [];

	for (const file of options.files) {
		if (!existsSync(file)) {
			throw new CliError(`html file not found: ${file}`);
		}
		const report = auditDocument(readFileSync(file, 'utf8'), options.policy || shippedDefaultPolicy(), {
			documentUrl,
			strict: options.strict,
			label: file,
		});
		if (!options.policy) {
			report.notes.push('No policy was given, so the plugin\'s shipped default was used. Pass --policy, or --url to read the header the site really sends.');
		}
		reports.push(report);
	}

	for (const url of options.urls) {
		let response;
		try {
			response = await fetch(url, { redirect: 'follow' });
		} catch (error) {
			throw new CliError(`could not fetch ${url}: ${error.message}`);
		}
		const html = await response.text();
		/* The site's own header wins; --policy is how you test a change first. */
		const header = options.policy
			|| response.headers.get('content-security-policy')
			|| response.headers.get('content-security-policy-report-only')
			|| '';
		const report = auditDocument(html, header || shippedDefaultPolicy(), {
			documentUrl: response.url || url,
			strict: options.strict,
			label: `${url} (HTTP ${response.status})`,
		});
		if (!header) {
			report.notes.push('No Content-Security-Policy header was sent, so the plugin\'s shipped default was used instead. Is the plugin active on this page?');
		}
		reports.push(report);
	}

	if (options.html) {
		const report = auditDocument(options.html, options.policy || shippedDefaultPolicy(), {
			documentUrl,
			strict: options.strict,
			label: 'stdin',
		});
		if (!options.policy) {
			report.notes.push('No policy was given, so the plugin\'s shipped default was used.');
		}
		reports.push(report);
	}

	return reports;
}

/**
 * Print the reports and decide the exit status.
 *
 * @param {object[]} reports Reports from collectReports().
 * @param {object} options Options from parseArgs().
 * @returns {number} 0 when nothing would be blocked, 1 when something would.
 */
export function renderReports(reports, options) {
	const totalBlocked = reports.reduce((sum, report) => sum + report.counts.blocked, 0);

	if (options.json) {
		stdout.write(`${JSON.stringify({ reports, snippet: suggestFilterSnippet(reports), blocked: totalBlocked }, null, 2)}\n`);
		return totalBlocked > 0 ? 1 : 0;
	}

	for (const report of reports) {
		if (options.quiet && report.counts.blocked === 0) {
			continue;
		}
		stdout.write(`${renderReport(report)}\n`);
	}

	if (totalBlocked > 0) {
		stdout.write(`\n--- suggested filter for the blocked hosts ---\n${suggestFilterSnippet(reports)}\n`);
		stdout.write('\nInline resources are not in that list on purpose: no domain allowlists them.\n');
		stdout.write('Enable them with lkc_csp_strict (which nonces them), or move them to a file.\n');
	}

	stdout.write(`\n${reports.length} document(s), ${totalBlocked} resource(s) would be blocked by enforce mode.\n`);
	return totalBlocked > 0 ? 1 : 0;
}

/**
 * Command line entry point.
 *
 * @param {string[]} args Argument list, without node and the script name.
 * @returns {Promise<number>} Exit status: 0 clean, 1 something would break, 2 bad usage.
 */
export async function main(args) {
	let options;
	try {
		options = parseArgs(args);
	} catch (error) {
		stderr.write(`${error.message}\n`);
		return 2;
	}

	if (options.urls.length === 0 && options.files.length === 0 && !options.help) {
		const piped = await readStdin();
		if (piped.trim()) {
			options.html = piped;
		}
	}

	if (options.help || (options.urls.length === 0 && options.files.length === 0 && !options.html)) {
		stdout.write(`${usageText()}\n`);
		return options.help ? 0 : 2;
	}

	try {
		return renderReports(await collectReports(options), options);
	} catch (error) {
		if (error instanceof CliError) {
			stderr.write(`${error.message}\n`);
			return 2;
		}
		throw error;
	}
}

if (isMain) {
	main(argv.slice(2)).then(
		(code) => exit(code),
		(error) => {
			stderr.write(`${error.stack || error.message}\n`);
			exit(2);
		}
	);
}

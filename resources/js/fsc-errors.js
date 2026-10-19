/*!
 * Fullstack Content browser error reporter (`<x-content::errors />`, PHP SDK). No dependencies, ~4 kB.
 *
 * - Config from <script type="application/json" id="fsc-errors">; without it nothing happens.
 * - Only uncaught errors (`error`) and unhandled promise rejections, and only when the stack has a frame of a script on this page's own
 *   origin (errors of third-party scripts and extensions are not the site's errors).
 * - Every event is built from an allowlist: event id, time, exception type (built-in JS errors only), the message after scrubText (same
 *   rules and test vectors as fss-core's Scrubber), own-origin frames (path, line, column, function). No URL, query, cookies, user,
 *   breadcrumbs or DOM. The server rebuilds the event again before it reaches GlitchTip.
 * - Sent to the first-party tunnel (`tunnel`, same origin, no cookies, no referrer), or straight to the public ingest when the tunnel is off.
 *   At most 10 events per page, the same error once.
 */
(function (root, factory) {
    var api = factory(root);
    root.FscErrors = api;
    if (root.document && root.addEventListener) {
        api.start(root.document, root);
    }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
    'use strict';

    var PUBLIC_DSN = /^https:\/\/([a-f0-9]{32})@([a-z0-9.-]+)\/(\d+)$/;
    var ERROR_TYPES = ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'URIError', 'EvalError', 'AggregateError', 'DOMException'];
    var MAX_LENGTH = 300;
    var MAX_EVENTS = 10;

    // Order and patterns equal to src/Errors/Scrubber.php (PATTERNS) and fss-core's Scrubber.
    var PATTERNS = [
        [/\b(bearer|basic)\s+[^\s,;]+/gi, '$1 [token]'],
        [/\b(password|passwd|pwd|secret|(?:access[_-]?|refresh[_-]?)?token|code|api[_-]?key|apikey|authorization|auth|signature|sig|otp|session|cookie)(\s*[=:]\s*)("[^"]*"|'[^']*'|[^\s&,;]+)/gi, '$1$2[redacted]'],
        [/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/g, '[token]'],
        [/\b(?:fss|fsc|fsv|sk|pk|rk|whsec|ghp|gho|ghs|github_pat|xox[abpr]|test|live|access)_[A-Za-z0-9_-]{8,}/g, '[token]'],
        [/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, '[email]'],
        [/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/g, '[uuid]'],
        [/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b/g, '[iban]'],
        [/\b(?=[A-Za-z0-9_-]{20,}\b)(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]+/g, '[token]'],
        [/(?<![\w])(?:\+|00)[1-9](?:[\s().-]?\d){7,14}(?!\w)/g, '[phone]'],
        [/(?<![\w])0(?:[\s.-]?\d){9}(?!\w)/g, '[phone]'],
        [/\b\d{4}\s?[A-Z]{2}\b/g, '[postcode]'],
        [/\b(?:\d{1,3}\.){3}\d{1,3}\b/g, '[ip]'],
        [/\b\d{6,}\b/g, '[number]'],
    ];

    function scrubPath(path) {
        if (!path) return path;
        var prefix = '';
        var origin = /^(https?:\/\/[^/]+)(.*)$/i.exec(path);
        if (origin) {
            prefix = origin[1].replace(/^(https?:\/\/)[^/]*@/i, '$1');
            path = origin[2];
        }
        return prefix + path.split('/').map(function (segment) {
            var decoded;
            try {
                decoded = decodeURIComponent(segment);
            } catch (e) {
                decoded = segment;
            }
            if (decoded === '') return segment;
            if (decoded.indexOf('@') !== -1) return '[email]';
            if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(decoded)) return '[uuid]';
            if (/^\d+$/.test(decoded)) return '[id]';
            if (decoded.length >= 16 && /^[A-Za-z0-9_.~-]+$/.test(decoded) && /\d/.test(decoded) && /[A-Za-z]/.test(decoded)) return '[token]';
            return segment;
        }).join('/');
    }

    function scrubSql(text) {
        var state = /SQLSTATE\[([0-9A-Z]{5})\](?::\s*([^:(]+))?/.exec(text);
        if (state) return 'SQLSTATE[' + state[1] + ']' + (state[2] !== undefined ? ': ' + state[2].trim() : '');
        if (/\b(select\s[\s\S]+\sfrom|insert\s+into|update\s+\S+\s+set|delete\s+from)\b/i.test(text)) {
            return text.replace(/\b(select|insert|update|delete|with)\b[\s\S]*$/i, '[sql]');
        }
        return text;
    }

    function scrubQuoted(text) {
        return text.replace(/(["'`])([^"'`\r\n]{1,200})\1/gu, function (match, quote, inner) {
            var identifier = /^[A-Za-z_\\][A-Za-z0-9_\\]*(?:::[A-Za-z_][A-Za-z0-9_]*(?:\(\))?)?$/.test(inner)
                && (inner.indexOf('\\') !== -1 || inner.indexOf('::') !== -1);
            var dotted = /^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/.test(inner);
            return identifier || dotted ? match : quote + '[value]' + quote;
        });
    }

    function scrubText(value) {
        if (typeof value !== 'string' || value === '') return '';
        var text = scrubSql(value);
        text = text.replace(/(\bhttps?:\/\/[^\s?#"'<>]+|(?<![\w/.:])\/[A-Za-z0-9._~/%-]+)(\?[^\s#"'<>]*)?(#[^\s"'<>]*)?/g, function (match, base, query) {
            return scrubPath(base) + (query ? '?[query]' : '');
        });
        text = scrubQuoted(text);
        for (var i = 0; i < PATTERNS.length; i++) text = text.replace(PATTERNS[i][0], PATTERNS[i][1]);
        text = text.replace(/\s+/g, ' ').trim();
        var chars = Array.from(text);
        return chars.length > MAX_LENGTH ? chars.slice(0, MAX_LENGTH - 1).join('') + '…' : text;
    }

    function readConfig(value, origin) {
        try {
            if (!value || typeof value !== 'object' || typeof value.dsn !== 'string' || !PUBLIC_DSN.test(value.dsn)) return null;
            if (typeof origin !== 'string' || !/^https?:\/\/[^/]+$/.test(origin)) return null;
            var tunnel = typeof value.tunnel === 'string' && /^\/[A-Za-z0-9_\-/]*$/.test(value.tunnel) ? value.tunnel : null;
            return {
                dsn: value.dsn,
                tunnel: tunnel,
                origin: origin,
                environment: typeof value.environment === 'string' && /^[a-z0-9_-]{1,32}$/.test(value.environment) ? value.environment : 'production',
                release: typeof value.release === 'string' && /^[A-Za-z0-9._@+-]{1,64}$/.test(value.release) ? value.release : undefined,
                route: typeof value.route === 'string' && /^[A-Z]{3,7} \/[A-Za-z0-9_\-/{}.?:*]*$/.test(value.route) ? value.route : 'GET /[unmatched]',
            };
        } catch (e) {
            return null;
        }
    }

    // Chrome/Edge: "    at fn (https://x/app.js:10:5)" or "    at https://x/app.js:10:5"; Firefox/Safari: "fn@https://x/app.js:10:5".
    function parseStack(stack) {
        if (typeof stack !== 'string') return [];
        var frames = [];
        var lines = stack.split('\n').slice(0, 60);
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            var m = /^at (?:(.+?) \()?(.+?):(\d+):(\d+)\)?$/.exec(line) || /^(?:(.*?)@)?(.+?):(\d+):(\d+)$/.exec(line);
            if (!m) continue;
            frames.push({ function: m[1] || undefined, filename: m[2], lineno: Number(m[3]), colno: Number(m[4]) });
        }
        // Sentry wants the oldest frame first.
        return frames.reverse();
    }

    function frameLocation(frame, origin) {
        if (!frame || typeof frame.filename !== 'string') return null;
        var url;
        try {
            url = new URL(frame.filename, origin);
        } catch (e) {
            return null;
        }
        if (url.origin !== origin || url.username || !/^\/[A-Za-z0-9_./-]+\.m?js$/.test(url.pathname)) return null;
        var result = { filename: origin + url.pathname, in_app: true };
        if (typeof frame.function === 'string' && /^[A-Za-z0-9_$.<>]{1,120}$/.test(frame.function)) result.function = frame.function;
        ['lineno', 'colno'].forEach(function (key) {
            if (Number.isSafeInteger(frame[key]) && frame[key] > 0 && frame[key] <= 10000000) result[key] = frame[key];
        });
        return result;
    }

    function eventId(crypto) {
        var bytes = new Uint8Array(16);
        if (crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
        else for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    /** The event for an error value (or `null`: no own-origin frame, nothing to report). */
    function buildEvent(error, mechanism, config, now, id) {
        if (!error || typeof error !== 'object') return null;
        var frames = parseStack(error.stack).slice(-50).map(function (frame) { return frameLocation(frame, config.origin); }).filter(Boolean);
        if (!frames.length) return null;
        var type = typeof error.name === 'string' && ERROR_TYPES.indexOf(error.name) !== -1 ? error.name : 'Error';
        var event = {
            event_id: id,
            timestamp: now,
            platform: 'javascript',
            level: 'error',
            environment: config.environment,
            transaction: config.route,
            exception: { values: [{
                type: type,
                value: scrubText(error.message) || '[message removed]',
                stacktrace: { frames: frames },
                mechanism: { type: mechanism, handled: false },
            }] },
        };
        if (config.release) event.release = config.release;
        return event;
    }

    function envelope(event, config) {
        var payload = JSON.stringify(event);
        return JSON.stringify({ event_id: event.event_id, sent_at: new Date().toISOString(), dsn: config.dsn }) + '\n'
            + JSON.stringify({ type: 'event' }) + '\n' + payload + '\n';
    }

    function endpoint(config) {
        if (config.tunnel) return config.tunnel;
        var m = PUBLIC_DSN.exec(config.dsn);
        return 'https://' + m[2] + '/api/' + m[3] + '/envelope/?sentry_version=7&sentry_key=' + m[1];
    }

    function start(document, browser) {
        try {
            var element = document.getElementById('fsc-errors');
            var origin = browser.location && browser.location.origin;
            var config = readConfig(JSON.parse((element && element.textContent) || 'null'), origin);
            if (!config) return false;
            var sent = 0;
            var seen = {};
            var send = function (error, mechanism) {
                try {
                    if (sent >= MAX_EVENTS) return;
                    var event = buildEvent(error, mechanism, config, Date.now() / 1000, eventId(browser.crypto));
                    if (!event) return;
                    var key = event.exception.values[0].type + '|' + event.exception.values[0].value + '|' + JSON.stringify(event.exception.values[0].stacktrace.frames.slice(-1));
                    if (seen[key]) return;
                    seen[key] = true;
                    sent++;
                    var body = envelope(event, config);
                    var url = endpoint(config);
                    if (typeof browser.fetch === 'function') {
                        browser.fetch(url, {
                            method: 'POST', body: body, keepalive: true, credentials: 'omit', referrerPolicy: 'no-referrer',
                            headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                        }).catch(function () {});
                    } else if (browser.navigator && browser.navigator.sendBeacon) {
                        browser.navigator.sendBeacon(url, body);
                    }
                } catch (e) {
                    // Monitoring never breaks the page.
                }
            };
            browser.addEventListener('error', function (event) { send(event && event.error, 'onerror'); });
            browser.addEventListener('unhandledrejection', function (event) { send(event && event.reason, 'onunhandledrejection'); });
            return true;
        } catch (e) {
            return false;
        }
    }

    return { scrubText: scrubText, readConfig: readConfig, parseStack: parseStack, buildEvent: buildEvent, envelope: envelope, endpoint: endpoint, start: start };
});

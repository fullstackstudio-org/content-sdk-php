// Tests for resources/js/fsc-errors.js (the browser reporter). Run with: bun test packages/content-sdk-php/tests/js
import { describe, expect, it } from 'bun:test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const dir = import.meta.dir;
const source = readFileSync(join(dir, '../../resources/js/fsc-errors.js'), 'utf8');
const vectors = JSON.parse(readFileSync(join(dir, '../Fixtures/telemetry/scrub-vectors.json'), 'utf8')).vectors;

/** Loads the reporter into a fake browser; returns the API and the recorded requests. */
function load({ config, withDocument = true } = {}) {
    const listeners = {};
    const requests = [];
    const root = {
        location: { origin: 'https://www.example.nl' },
        crypto: { getRandomValues: (bytes) => bytes.fill(171) },
        addEventListener: (type, fn) => { listeners[type] = fn; },
        fetch: (url, init) => { requests.push({ url, init }); return Promise.resolve({ status: 202 }); },
    };
    if (withDocument) {
        root.document = { getElementById: (id) => (id === 'fsc-errors' && config ? { textContent: JSON.stringify(config) } : null) };
    }
    new Function('globalThis', 'window', source)(root, root);
    return { api: root.FscErrors, listeners, requests };
}

const DSN = `https://${'a'.repeat(32)}@errors.fullstackstudio.nl/12`;
const CONFIG = { dsn: DSN, tunnel: '/_fsc/e/envelope', environment: 'production', release: 'abc123', route: 'GET /contact' };

function errorWith(message, stack, name = 'TypeError') {
    return { name, message, stack };
}

describe('scrubText (shared vectors with fss-core and the PHP Scrubber)', () => {
    const { api } = load({ withDocument: false });
    for (const vector of vectors) {
        it(vector.in, () => {
            const out = api.scrubText(vector.in);
            for (const part of vector.contains) expect(out).toContain(part);
            for (const part of vector.absent) expect(out).not.toContain(part);
        });
    }
});

describe('reporter', () => {
    it('does nothing without a valid config', () => {
        expect(load({ config: null }).listeners.error).toBeUndefined();
        expect(load({ config: { ...CONFIG, dsn: 'https://evil.example/1' } }).listeners.error).toBeUndefined();
    });

    it('reports an own-origin error through the tunnel, scrubbed, without credentials or referrer', () => {
        const { listeners, requests } = load({ config: CONFIG });
        listeners.error({ error: errorWith('Mail to jan@example.nl failed', 'TypeError: x\n    at send (https://www.example.nl/build/app.js?v=3:10:5)\n    at https://cdn.other.com/lib.js:1:1') });

        expect(requests).toHaveLength(1);
        const { url, init } = requests[0];
        expect(url).toBe('/_fsc/e/envelope');
        expect(init.credentials).toBe('omit');
        expect(init.referrerPolicy).toBe('no-referrer');
        const [header, item, payload] = init.body.split('\n');
        expect(JSON.parse(header).dsn).toBe(DSN);
        expect(JSON.parse(item).type).toBe('event');
        const event = JSON.parse(payload);
        expect(event.exception.values[0].value).toBe('Mail to [email] failed');
        expect(event.exception.values[0].stacktrace.frames).toEqual([{ filename: 'https://www.example.nl/build/app.js', in_app: true, function: 'send', lineno: 10, colno: 5 }]);
        expect(event.transaction).toBe('GET /contact');
        expect(event.release).toBe('abc123');
        expect(init.body).not.toContain('jan@example.nl');
        expect(init.body).not.toContain('cdn.other.com');
        expect(init.body).not.toContain('v=3');
    });

    it('ignores errors without a frame of an own script (third-party scripts, extensions, "Script error.")', () => {
        const { listeners, requests } = load({ config: CONFIG });
        listeners.error({ error: errorWith('boom', 'Error: boom\n    at https://cdn.other.com/lib.js:1:1') });
        listeners.error({ error: null });
        listeners.unhandledrejection({ reason: 'plain string' });
        expect(requests).toHaveLength(0);
    });

    it('reads Firefox/Safari stacks, keeps only built-in error types and sends the same error once', () => {
        const { listeners, requests } = load({ config: CONFIG });
        const error = errorWith('Customer \'Jansen\' not found', 'load@https://www.example.nl/js/app.mjs:4:2\n', 'CustomerError');
        listeners.unhandledrejection({ reason: error });
        listeners.unhandledrejection({ reason: error });
        expect(requests).toHaveLength(1);
        const event = JSON.parse(requests[0].init.body.split('\n')[2]);
        expect(event.exception.values[0].type).toBe('Error');
        expect(event.exception.values[0].value).toBe("Customer '[value]' not found");
        expect(event.exception.values[0].mechanism).toEqual({ type: 'onunhandledrejection', handled: false });
    });

    it('sends at most 10 events per page', () => {
        const { listeners, requests } = load({ config: CONFIG });
        for (let i = 0; i < 15; i++) {
            listeners.error({ error: errorWith(`err ${i}`, `Error\n    at f (https://www.example.nl/app.js:${i + 1}:1)`) });
        }
        expect(requests).toHaveLength(10);
    });

    it('posts straight to the public ingest when the tunnel is off', () => {
        const { listeners, requests } = load({ config: { ...CONFIG, tunnel: null } });
        listeners.error({ error: errorWith('x', 'Error\n    at f (https://www.example.nl/app.js:1:1)') });
        expect(requests[0].url).toBe(`https://errors.fullstackstudio.nl/api/12/envelope/?sentry_version=7&sentry_key=${'a'.repeat(32)}`);
    });
});

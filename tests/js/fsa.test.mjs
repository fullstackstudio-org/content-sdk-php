// Tests of resources/assets/fsa.js (the native tracker) with a minimal fake browser (no dependencies).
// Run: `bun test tests/js` (or `node --test tests/js/fsa.test.mjs`).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

const SOURCE = readFileSync(fileURLToPath(new URL('../../resources/assets/fsa.js', import.meta.url)), 'utf8');
// The served file is the minified build (scripts/build-fsa.sh); every test runs against the source and the build.
const MINIFIED = readFileSync(fileURLToPath(new URL('../../resources/assets/fsa.min.js', import.meta.url)), 'utf8');
const CURRENT = { source: SOURCE };
const VARIANTS = [['source', SOURCE], ['min', MINIFIED]];
const realTest = test;
const tests = [];
const test_ = (name, fn) => tests.push([name, fn]);
const KEY = 'fsa_TestSite0123456789abcd';

function storage(initial = {}) {
    const data = { ...initial };
    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = String(value); },
        removeItem: (key) => { delete data[key]; },
        data,
    };
}

/** A fake element: tag, attributes, parent chain, a bounding box. `closest` supports the selectors the tracker uses. */
function el(tag, attrs = {}, parent = null, rect = { left: 0, top: 0, width: 100, height: 40 }) {
    const node = {
        tagName: tag.toUpperCase(),
        id: attrs.id || '',
        className: attrs.class || '',
        parentElement: parent,
        attributes: Object.entries(attrs).map(([name, value]) => ({ name, value })),
        getAttribute: (name) => (name in attrs ? attrs[name] : null),
        hasAttribute: (name) => name in attrs,
        getBoundingClientRect: () => rect,
        closest(selector) {
            for (let n = node; n; n = n.parentElement) {
                if (selector === 'a[href]' && n.tagName === 'A' && n.hasAttribute('href')) return n;
                if (selector === 'form' && n.tagName === 'FORM') return n;
                if (selector === '[data-fsa-event],[data-fsc]' && (n.hasAttribute('data-fsa-event') || n.hasAttribute('data-fsc'))) return n;
            }
            return null;
        },
    };
    return node;
}

/** A page running the tracker. Timers are manual (`advance`), beacons are captured as parsed JSON batches. */
function page({ url = 'https://studio.nl/', referrer = '', title = 'Home', consent = null, gpc = false, dataset = {}, stored = {}, navStatus = 0, ttfb = 0, beacon = true, stub } = {}) {
    const observers = {};
    let clock = 1_800_000_000_000;
    const timers = [];
    const beacons = [];
    const fetched = [];
    const docListeners = {};
    const winListeners = {};
    const local = storage({ ...(consent === null ? {} : { 'fsc-consent': consent }), ...stored });
    const doc = {
        referrer,
        title,
        visibilityState: 'visible',
        currentScript: { dataset: { sitekey: KEY, endpoint: '/_fsc/a/e', ...dataset } },
        documentElement: { scrollHeight: 4000 },
        body: { scrollHeight: 4000 },
        addEventListener: (type, fn) => { (docListeners[type] ||= []).push(fn); },
    };
    const location = new URL(url);
    const history = {
        pushState(state, _t, next) { const u = new URL(next, location.href); location.href = u.href; },
        replaceState(state, _t, next) { if (next) { const u = new URL(next, location.href); location.href = u.href; } },
    };
    const win = {
        location,
        history,
        localStorage: local,
        innerWidth: 1280,
        innerHeight: 800,
        scrollY: 0,
        URL,
        Blob,
        crypto: { randomUUID: () => '11111111-2222-4333-8444-555555555555' },
        navigator: {
            language: 'nl-NL',
            webdriver: false,
            globalPrivacyControl: gpc,
            sendBeacon: beacon ? (endpoint, blob) => { beacons.push({ endpoint, blob }); return true; } : undefined,
        },
        fetch: (endpoint, init) => { fetched.push({ endpoint, init }); return Promise.resolve({}); },
        performance: { getEntriesByType: () => (navStatus || ttfb ? [{ responseStatus: navStatus, responseStart: ttfb }] : []) },
        PerformanceObserver: Object.assign(function (cb) {
            this.observe = (opts) => { (observers[opts.type] ||= []).push(cb); };
        }, { supportedEntryTypes: ['largest-contentful-paint', 'paint', 'layout-shift', 'event', 'first-input'] }),
        setTimeout: (fn, ms) => { const t = { fn, at: clock + ms, once: true }; timers.push(t); return t; },
        clearTimeout: (t) => { const i = timers.indexOf(t); if (i >= 0) timers.splice(i, 1); },
        setInterval: (fn, ms) => { const t = { fn, at: clock + ms, every: ms }; timers.push(t); return t; },
        addEventListener: (type, fn) => { (winListeners[type] ||= []).push(fn); },
    };
    if (stub) win.fsa = stub;

    const realNow = Date.now;
    Date.now = () => clock;
    try {
        new Function('window', 'document', CURRENT.source)(win, doc);
    } finally {
        Date.now = realNow;
    }

    function run(fn) {
        const saved = Date.now;
        Date.now = () => clock;
        try { return fn(); } finally { Date.now = saved; }
    }

    function advance(ms) {
        run(() => {
            const end = clock + ms;
            for (;;) {
                timers.sort((a, b) => a.at - b.at);
                const next = timers.find((t) => t.at <= end);
                if (!next) break;
                clock = next.at;
                if (next.once) timers.splice(timers.indexOf(next), 1);
                else next.at += next.every;
                next.fn();
            }
            clock = end;
        });
    }

    async function batches() {
        const out = [];
        for (const b of beacons) out.push({ endpoint: b.endpoint, type: b.blob.type, body: JSON.parse(await b.blob.text()) });
        for (const f of fetched) out.push({ endpoint: f.endpoint, init: f.init, body: JSON.parse(f.init.body) });
        return out;
    }

    async function events() {
        return (await batches()).flatMap((b) => b.body.e);
    }

    const fireDoc = (type, event) => run(() => (docListeners[type] || []).forEach((fn) => fn(event)));
    const fireWin = (type, event) => run(() => (winListeners[type] || []).forEach((fn) => fn(event)));

    const perf = (type, entries) => run(() => (observers[type] || []).forEach((cb) => cb({ getEntries: () => entries })));

    return { win, doc, local, advance, batches, events, fireDoc, fireWin, run, perf, beacons, fetched, tick: (ms) => { clock += ms; } };
}

test_('sends one cookieless pageview batch with the sitekey, no persistent id and only campaign parameters', async () => {
    const p = page({ url: 'https://studio.nl/prijzen?utm_source=nieuwsbrief&utm_medium=email&email=jan@x.nl&gclid=abc#top', referrer: 'https://www.google.com/search?q=studio', title: 'Prijzen' });
    assert.equal(p.beacons.length, 0, 'batched: nothing before the flush timer');
    p.advance(1000);

    const [batch] = await p.batches();
    assert.equal(batch.endpoint, '/_fsc/a/e');
    assert.match(batch.type, /^text\/plain/);
    assert.equal(batch.body.k, KEY);
    assert.equal(batch.body.c, 0);
    assert.equal('pid' in batch.body, false, 'no id without consent');
    assert.equal(batch.body.vw, 1280);
    assert.equal(batch.body.lang, 'nl-NL');
    assert.equal(p.local.data['fsa-id'], undefined, 'nothing stored on the device');
    const [pv] = batch.body.e;
    assert.equal(pv.t, 'pageview');
    assert.equal(pv.u, 'https://studio.nl/prijzen?utm_source=nieuwsbrief&utm_medium=email&gclid=abc');
    assert.equal(pv.r, 'https://www.google.com/search');
    assert.equal(pv.ti, 'Prijzen');
});

test_('replaces e-mail addresses and phone numbers in paths, titles and props', async () => {
    const p = page({ url: 'https://studio.nl/afmelden/jan.jansen@example.nl?utm_campaign=bel%200612345678', title: 'Hoi jan@example.nl' });
    p.run(() => p.win.fsa.track('contact', { note: 'mail a@b.nl of bel +31 6 12345678', email: 'x@y.nl', firstName: 'Jan', plan: 'pro', qty: 2, ok: true, bad: { x: 1 }, 'Bad Key': 1 }));
    p.advance(1000);
    const [pv, ev] = await p.events();
    assert.equal(pv.u, 'https://studio.nl/afmelden/[email]?utm_campaign=bel%20%5Btel%5D');
    assert.equal(pv.ti, 'Hoi [email]');
    assert.deepEqual(ev.p, { note: 'mail [email] of bel [tel]', plan: 'pro', qty: 2, ok: true });
});

test_('with consent: a persistent id in localStorage and heatmap clicks; revoking deletes the id and stops both', async () => {
    const p = page({ consent: 'accepted' });
    const button = el('button', { class: 'btn primary' }, el('section', { id: 'hero' }), { left: 100, top: 50, width: 200, height: 50 });
    p.fireDoc('click', { type: 'click', target: button, clientX: 150, clientY: 75 });
    p.advance(1000);
    let [batch] = await p.batches();
    assert.equal(batch.body.c, 1);
    assert.equal(batch.body.pid, '11111111-2222-4333-8444-555555555555');
    assert.equal(p.local.data['fsa-id'], '11111111-2222-4333-8444-555555555555');
    const heat = batch.body.e.find((e) => e.t === 'heatmap');
    assert.deepEqual({ sel: heat.sel, x: heat.x, y: heat.y, hk: heat.hk }, { sel: 'section#hero > button.btn.primary', x: 0.25, y: 0.5, hk: 'click' });

    p.fireWin('fsc:consent', { detail: 'declined' });
    assert.equal(p.local.data['fsa-id'], undefined, 'revoking deletes the id at once');
    p.fireDoc('click', { type: 'click', target: button, clientX: 150, clientY: 75 });
    p.run(() => p.win.fsa.track('after_revoke'));
    p.advance(1000);
    batch = (await p.batches())[1];
    assert.equal(batch.body.c, 0);
    assert.equal('pid' in batch.body, false);
    assert.equal(batch.body.e.some((e) => e.t === 'heatmap'), false, 'no heatmap without consent');
});

test_('without consent no heatmap clicks; Global Privacy Control blocks the persistent id even with consent', async () => {
    const p = page();
    p.fireDoc('click', { type: 'click', target: el('div'), clientX: 1, clientY: 1 });
    p.advance(1000);
    assert.equal((await p.events()).some((e) => e.t === 'heatmap'), false);

    const g = page({ consent: '{"choice":"all"}', gpc: true });
    g.advance(1000);
    const [batch] = await g.batches();
    assert.equal(batch.body.c, 1, 'consent still counts');
    assert.equal('pid' in batch.body, false, 'but no persistent id under GPC');
    assert.equal(g.local.data['fsa-id'], undefined);
});

test_('consent given later through the event or the storage key starts the id; a {categories} object is understood', async () => {
    const p = page();
    p.fireWin('fsc:consent', { detail: { categories: ['necessary', 'analytics'] } });
    p.advance(1000);
    assert.equal((await p.batches())[0].body.pid, '11111111-2222-4333-8444-555555555555');

    const s = page({ dataset: { consentKey: 'site-consent' } });
    s.fireWin('storage', { key: 'site-consent', newValue: 'accepted' });
    assert.equal(s.run(() => s.win.fsa.hasConsent()), true);
});

test_('SPA navigation sends a new pageview with the previous page as referrer, after a heartbeat with engaged time', async () => {
    const p = page({ url: 'https://studio.nl/', referrer: 'https://www.google.com/' });
    p.fireWin('mousemove', {});
    p.tick(4000);
    p.fireWin('keydown', {});
    p.run(() => p.win.history.pushState({}, '', '/diensten?utm_source=x&secret=1'));
    p.advance(1000);
    const events = await p.events();
    const types = events.map((e) => e.t);
    assert.deepEqual(types, ['pageview', 'heartbeat', 'pageview']);
    assert.equal(events[1].u, 'https://studio.nl/');
    assert.ok(events[1].d >= 4000, 'engaged time in ms');
    assert.equal(events[2].u, 'https://studio.nl/diensten?utm_source=x');
    assert.equal(events[2].r, 'https://studio.nl/');

    // Same URL again (replaceState for state only): no second pageview.
    p.run(() => p.win.history.replaceState({ a: 1 }, ''));
    p.advance(1000);
    assert.equal((await p.events()).filter((e) => e.t === 'pageview').length, 2);
});

test_('scroll depth marks 25/50/75/100 once each; heartbeat on hide carries the max depth', async () => {
    const p = page();
    p.win.scrollY = 1200; // (1200 + 800) / 4000 = 50 %
    p.fireWin('scroll', {});
    p.fireWin('scroll', {});
    p.win.scrollY = 3200; // 100 %
    p.fireWin('scroll', {});
    p.tick(2000);
    p.doc.visibilityState = 'hidden';
    p.fireDoc('visibilitychange', {});
    const events = await p.events();
    assert.deepEqual(events.filter((e) => e.t === 'scroll').map((e) => e.sd), [25, 50, 75, 100]);
    const hb = events.find((e) => e.t === 'heartbeat');
    assert.equal(hb.sd, 100);
    assert.equal(p.beacons.length, 1, 'hiding the page flushes at once');
});

test_('outbound links, mailto/tel without the address, downloads and declarative events', async () => {
    const p = page();
    const click = (target) => p.fireDoc('click', { type: 'click', target, clientX: 0, clientY: 0 });
    click(el('span', {}, el('a', { href: 'https://partner.example/path/jan@x.nl?ref=1' })));
    click(el('a', { href: 'mailto:info@studio.nl?subject=hoi' }));
    click(el('a', { href: 'tel:+31201234567' }));
    click(el('a', { href: 'https://wa.me/31612345678' }));
    click(el('a', { href: '/files/Offerte%20jan@x.nl.PDF' }));
    click(el('a', { href: 'https://www.studio.nl/contact' }));
    click(el('button', { 'data-fsa-event': 'cta_click', 'data-fsa-prop-location': 'hero', 'data-fsa-prop-plan-name': 'pro' }));
    p.advance(1000);
    const events = (await p.events()).filter((e) => e.t !== 'pageview');
    assert.deepEqual(events.map((e) => [e.t, e.n || null, e.p]), [
        ['outbound', null, { kind: 'link', host: 'partner.example', path: '/path/[email]' }],
        ['outbound', null, { kind: 'mailto' }],
        ['outbound', null, { kind: 'tel' }],
        ['outbound', null, { kind: 'whatsapp' }],
        // The extension looks like a top-level domain after an address: over-redacting is the safe side.
        ['download', null, { file: 'Offerte [email]', ext: 'pdf', host: 'studio.nl' }],
        ['event', 'cta_click', { location: 'hero', plan_name: 'pro' }],
    ]);
    assert.equal(JSON.stringify(events).includes('info@studio.nl'), false);
    assert.equal(JSON.stringify(events).includes('31201234567'), false);
});

test_('form started once per form and submitted, never with field values; data-fsa-ignore opts out', async () => {
    const p = page();
    const form = el('form', { id: 'contact', action: '/content/forms/contact' });
    const input = el('input', { name: 'email', value: 'jan@x.nl' }, form);
    input.form = form;
    p.fireDoc('focusin', { target: input });
    p.fireDoc('focusin', { target: input });
    p.fireDoc('submit', { target: form });
    const hidden = el('form', { 'data-fsa-ignore': '' });
    const field = el('input', {}, hidden);
    field.form = hidden;
    p.fireDoc('focusin', { target: field });
    p.advance(1000);
    const events = (await p.events()).filter((e) => e.t.startsWith('form'));
    assert.deepEqual(events.map((e) => [e.t, e.p]), [['form_start', { form: 'contact' }], ['form_submit', { form: 'contact' }]]);
    assert.equal(JSON.stringify(await p.batches()).includes('jan@x.nl'), false);
});

test_('track() validates names; revenue() needs a positive amount and an ISO currency and flushes at once', async () => {
    const p = page();
    p.run(() => {
        p.win.fsa.track('bad name!', {});
        p.win.fsa.track('signup', { plan: 'pro' });
        p.win.fsa.revenue(-1, 'EUR');
        p.win.fsa.revenue(49.95, 'eur', { order: 'A-1' });
    });
    const events = await p.events();
    assert.deepEqual(events.slice(1).map((e) => [e.t, e.n, e.amt, e.cur, e.p]), [
        ['event', 'signup', undefined, undefined, { plan: 'pro' }],
        ['revenue', 'purchase', 49.95, 'EUR', { order: 'A-1' }],
    ]);
});

test_('404 from data-status or the navigation timing; calls queued before load are replayed', async () => {
    const a = page({ dataset: { status: '404' } });
    a.advance(1000);
    assert.deepEqual((await a.events()).map((e) => [e.t, e.n]), [['pageview', undefined], ['event', 'not_found']]);

    const b = page({ navStatus: 404 });
    b.advance(1000);
    assert.equal((await b.events()).some((e) => e.n === 'not_found'), true);

    const stub = function () {};
    stub.q = [['track', 'early', { a: 1 }], ['revenue', 10, 'EUR']];
    const c = page({ stub });
    c.advance(1000);
    assert.deepEqual((await c.events()).map((e) => e.n || e.t), ['pageview', 'early', 'purchase']);
});

test_('falls back to fetch with keepalive and no credentials when sendBeacon is missing; does nothing without a sitekey', async () => {
    const p = page({ beacon: false });
    p.advance(1000);
    assert.equal(p.fetched.length, 1);
    assert.deepEqual({ method: p.fetched[0].init.method, keepalive: p.fetched[0].init.keepalive, credentials: p.fetched[0].init.credentials }, { method: 'POST', keepalive: true, credentials: 'omit' });

    const none = page({ dataset: { sitekey: '' } });
    none.advance(5000);
    assert.equal(none.beacons.length, 0);
    assert.equal(none.win.fsa, undefined);
});

test_('batches at most 50 events per request', async () => {
    const p = page();
    p.run(() => { for (let i = 0; i < 120; i++) p.win.fsa.track('e' + i); });
    p.advance(1000);
    const sizes = (await p.batches()).map((b) => b.body.e.length);
    assert.deepEqual(sizes, [50, 50, 21]);
});

test_('secret-looking path segments become [token] (same cases as the collector), paths are capped at 500', async () => {
    const TOKEN_CASES = {
        '/reset-password/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08': '/reset-password/[token]',
        '/s/Xk3j9Qa2LmP0wZ8rT1vB': '/s/[token]',
        '/wachtwoord/Jx7_kQ2p9Lw4Zr8tYb3N-c5': '/wachtwoord/[token]',
        '/order/3f2504e0-4f89-41d3-9a0c-0305e82c3301/ok': '/order/[token]/ok',
        '/blog/website-laten-maken-2026': '/blog/website-laten-maken-2026',
        '/diensten/seo-checklist-2026-v2': '/diensten/seo-checklist-2026-v2',
        '/cases/appelmaninfra-nl': '/cases/appelmaninfra-nl',
        '/product/12345678901234567890': '/product/12345678901234567890',
    };
    for (const [path, want] of Object.entries(TOKEN_CASES)) {
        const p = page({ url: 'https://studio.nl' + path, referrer: 'https://mail.example/s/Xk3j9Qa2LmP0wZ8rT1vB' });
        p.advance(1000);
        const [pv] = await p.events();
        assert.equal(pv.u, 'https://studio.nl' + want, path);
        assert.equal(pv.r, 'https://mail.example/s/[token]');
    }
    const p = page({ url: 'https://studio.nl/' + 'lang-pad/'.repeat(80) });
    p.fireDoc('click', { type: 'click', target: el('a', { href: 'https://partner.example/s/Xk3j9Qa2LmP0wZ8rT1vB' }), clientX: 0, clientY: 0 });
    p.advance(1000);
    const [pv, out] = await p.events();
    assert.equal(new URL(pv.u).pathname.length, 500);
    assert.equal(out.p.path, '/s/[token]');
});

test_('Web Vitals are sent once per page load when the page is hidden, rated, for the first page', async () => {
    const p = page({ url: 'https://studio.nl/prijzen', ttfb: 320.44 });
    p.perf('largest-contentful-paint', [{ startTime: 1200, renderTime: 1850.27 }]);
    p.perf('paint', [{ name: 'first-paint', startTime: 500 }, { name: 'first-contentful-paint', startTime: 900 }]);
    p.perf('layout-shift', [{ value: 0.05, startTime: 1000 }, { value: 0.5, startTime: 1100, hadRecentInput: true }, { value: 0.04, startTime: 1500 }, { value: 0.3, startTime: 9000 }]);
    p.perf('event', [{ interactionId: 1, duration: 120 }, { interactionId: 0, duration: 900 }, { interactionId: 2, duration: 260 }]);
    p.run(() => p.win.history.pushState({}, '', '/contact'));
    p.advance(1000);
    p.doc.visibilityState = 'hidden';
    p.fireDoc('visibilitychange', {});
    p.doc.visibilityState = 'visible';
    p.fireDoc('visibilitychange', {});
    p.doc.visibilityState = 'hidden';
    p.fireDoc('visibilitychange', {});
    p.fireWin('pagehide', {});
    const vitals = (await p.events()).filter((e) => e.t === 'vital');
    assert.deepEqual(vitals.map((v) => [v.n, v.v, v.rt, v.u]), [
        ['LCP', 1850.3, 'good', 'https://studio.nl/prijzen'],
        ['INP', 260, 'needs-improvement', 'https://studio.nl/prijzen'],
        ['CLS', 0.3, 'poor', 'https://studio.nl/prijzen'],
        ['FCP', 900, 'good', 'https://studio.nl/prijzen'],
        ['TTFB', 320.4, 'good', 'https://studio.nl/prijzen'],
    ]);
});

test_('data-fsc works like data-fsa-event, with data-fsc-prop-*', async () => {
    const p = page();
    p.fireDoc('click', { type: 'click', target: el('span', {}, el('a', { href: '#', 'data-fsc': 'pricing_cta', 'data-fsc-prop-plan': 'pro' })), clientX: 0, clientY: 0 });
    p.advance(1000);
    const ev = (await p.events()).find((e) => e.t === 'event');
    assert.deepEqual([ev.n, ev.p], ['pricing_cta', { plan: 'pro' }]);
});

test_('a stored id is deleted on load when there is no consent (declined elsewhere, or never given) and under GPC', async () => {
    const a = page({ stored: { 'fsa-id': 'old-id-0123456789abcdef' } });
    assert.equal(a.local.data['fsa-id'], undefined);
    const b = page({ consent: 'declined', stored: { 'fsa-id': 'old-id-0123456789abcdef' } });
    assert.equal(b.local.data['fsa-id'], undefined);
    const c = page({ consent: 'accepted', gpc: true, stored: { 'fsa-id': 'old-id-0123456789abcdef' } });
    assert.equal(c.local.data['fsa-id'], undefined);
    const d = page({ consent: 'accepted', stored: { 'fsa-id': 'kept-id-0123456789abcdef' } });
    d.advance(1000);
    assert.equal((await d.batches())[0].body.pid, 'kept-id-0123456789abcdef');
});

test_('the minified build is the current source', () => {
    const hash = createHash('sha256').update(SOURCE).digest('hex');
    assert.ok(MINIFIED.startsWith(`/*! fsa.js sha256:${hash} */`), 'run scripts/build-fsa.sh');
});

for (const [label, source] of VARIANTS) {
    for (const [name, fn] of tests) {
        realTest(`[${label}] ${name}`, async () => {
            CURRENT.source = source;
            await fn();
        });
    }
}

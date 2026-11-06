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
const INTERACTIVE_TAGS = ['BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'LABEL', 'SUMMARY', 'OPTION'];
const INTERACTIVE_ATTRS = ['onclick', 'tabindex', 'contenteditable', 'role', 'data-fsa-event', 'data-fsc'];

function el(tag, attrs = {}, parent = null, rect = { left: 0, top: 0, width: 100, height: 40 }, previous = null) {
    const node = {
        nodeType: 1,
        previousElementSibling: previous,
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
                if (selector.startsWith('a[href],button') && ((n.tagName === 'A' && n.hasAttribute('href')) || INTERACTIVE_TAGS.includes(n.tagName) || INTERACTIVE_ATTRS.some((a) => n.hasAttribute(a)))) return n;
                if (selector === '[data-fsa-event],[data-fsc]' && (n.hasAttribute('data-fsa-event') || n.hasAttribute('data-fsc'))) return n;
            }
            return null;
        },
    };
    return node;
}

/** A page running the tracker. Timers are manual (`advance`), beacons are captured as parsed JSON batches. */
function page({ url = 'https://studio.nl/', referrer = '', title = 'Home', consent = null, gpc = false, dataset = {}, stored = {}, navStatus = 0, ttfb = 0, beacon = true, stub, navigationApi = false } = {}) {
    const observers = {};
    let clock = 1_800_000_000_000;
    const timers = [];
    const beacons = [];
    const fetched = [];
    const docListeners = {};
    const winListeners = {};
    const mutationObservers = [];
    const local = storage({ ...(consent === null ? {} : { 'fsc-consent': consent }), ...stored });
    const doc = {
        referrer,
        title,
        cookies: [],
        set cookie(value) { this.cookies.push(value); },
        visibilityState: 'visible',
        currentScript: { dataset: { sitekey: KEY, endpoint: '/_fsc/a/e', ...dataset } },
        documentElement: { scrollHeight: 4000 },
        body: { scrollHeight: 4000 },
        addEventListener: (type, fn) => { (docListeners[type] ||= []).push(fn); },
        appended: [],
        createElement: (tag) => ({ tagName: tag.toUpperCase() }),
        head: { appendChild(node) { doc.appended.push(node); return node; } },
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
        MutationObserver: function (cb) {
            this.observe = () => { mutationObservers.push(this); };
            this.disconnect = () => { mutationObservers.splice(mutationObservers.indexOf(this), 1); };
            this.cb = cb;
        },
        setTimeout: (fn, ms) => { const t = { fn, at: clock + ms, once: true }; timers.push(t); return t; },
        clearTimeout: (t) => { const i = timers.indexOf(t); if (i >= 0) timers.splice(i, 1); },
        setInterval: (fn, ms) => { const t = { fn, at: clock + ms, every: ms }; timers.push(t); return t; },
        addEventListener: (type, fn) => { (winListeners[type] ||= []).push(fn); },
    };
    if (stub) win.fsa = stub;
    const navListeners = [];
    if (navigationApi) {
        win.navigation = { currentEntry: { index: 3 }, addEventListener: (type, fn) => { if (type === 'navigate') navListeners.push(fn); } };
    }

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

    const mutate = () => mutationObservers.slice().forEach((o) => o.cb([{}]));
    const navigate = (event) => run(() => navListeners.forEach((fn) => fn(event)));

    return { mutate, navigate, win, doc, local, advance, batches, events, fireDoc, fireWin, run, perf, beacons, fetched, tick: (ms) => { clock += ms; } };
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

test_('fsa.pid() hands the browser id to a site form only with consent and never under Global Privacy Control', async () => {
    const none = page();
    assert.equal(none.run(() => none.win.fsa.pid()), null);
    assert.equal(none.local.data['fsa-id'], undefined, 'asking does not create an id without consent');

    const yes = page({ consent: 'accepted' });
    assert.equal(yes.run(() => yes.win.fsa.pid()), '11111111-2222-4333-8444-555555555555');

    const gpc = page({ consent: 'accepted', gpc: true });
    assert.equal(gpc.run(() => gpc.win.fsa.pid()), null);
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
    assert.deepEqual(types, ['pageview', 'heartbeat', 'scroll_max', 'pageview']);
    assert.equal(events[1].u, 'https://studio.nl/');
    assert.ok(events[1].d >= 4000, 'engaged time in ms');
    assert.equal(events[3].u, 'https://studio.nl/diensten?utm_source=x');
    assert.equal(events[3].r, 'https://studio.nl/');

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
    let spot = 0;
    const click = (target) => p.fireDoc('click', { type: 'click', target, clientX: (spot += 100), clientY: 0 });
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
    // Submitted in the same instant as started: 0 seconds.
    assert.deepEqual(events.map((e) => [e.t, e.p]), [['form_start', { form: 'contact' }], ['form_submit', { form: 'contact', seconds: 0 }]]);
    assert.equal(JSON.stringify(await p.batches()).includes('jan@x.nl'), false);
});

/** A form with fields: `fields` maps a name to its type; the inputs carry a value that must never leave the page. */
function formPage(options = {}, attrs = { id: 'contact' }, fields = { email: 'email', message: 'text', token: 'hidden', pw: 'password' }) {
    const p = page(options);
    const form = el('form', attrs);
    const inputs = {};
    for (const [name, type] of Object.entries(fields)) {
        const input = el(type === 'text' ? 'textarea' : 'input', { name, type }, form);
        input.form = form;
        input.value = `secret-value-of-${name}@x.nl`;
        inputs[name] = input;
    }
    return { p, form, inputs };
}

test_('form_submit carries the seconds since form_start; data-fsa-form wins as the form identifier', async () => {
    const { p, form, inputs } = formPage({}, { id: 'contact', 'data-fsa-form': 'offerte-aanvraag' });
    p.fireDoc('focusin', { target: inputs.email });
    p.tick(42_340);
    p.fireDoc('submit', { target: form });
    // A second submit on the same page has no start of its own: no duration.
    p.tick(5000);
    p.fireDoc('submit', { target: form });
    p.advance(1000);
    const forms = (await p.events()).filter((e) => e.t.startsWith('form'));
    assert.deepEqual(forms.map((e) => [e.t, e.p]), [
        ['form_start', { form: 'offerte-aanvraag' }],
        ['form_submit', { form: 'offerte-aanvraag', seconds: 42.3 }],
        ['form_submit', { form: 'offerte-aanvraag' }],
    ]);
});

test_('an absurd duration (a tab left open for days) is not reported as seconds', async () => {
    const { p, form, inputs } = formPage();
    p.fireDoc('focusin', { target: inputs.email });
    p.tick(3 * 86_400_000);
    p.fireDoc('submit', { target: form });
    p.advance(1000);
    assert.deepEqual((await p.events()).filter((e) => e.t === 'form_submit').map((e) => e.p), [{ form: 'contact' }]);
});

test_('form_field only with consent and without GPC; only the field name, never a value, hidden or password input', async () => {
    const run = async (options) => {
        const { p, form, inputs } = formPage(options);
        p.fireDoc('focusin', { target: inputs.email });
        p.tick(2000);
        p.fireDoc('focusin', { target: inputs.token });
        p.fireDoc('focusin', { target: inputs.pw });
        p.fireDoc('focusin', { target: inputs.message });
        p.tick(3000);
        p.advance(1000);
        return { p, events: await p.events() };
    };

    const yes = await run({ consent: 'accepted' });
    assert.deepEqual(yes.events.filter((e) => e.n === 'form_field').map((e) => [e.t, e.p]), [
        ['event', { form: 'contact', field: 'email', seconds: 0 }],
        ['event', { form: 'contact', field: 'message', seconds: 2 }],
    ]);
    const wire = JSON.stringify(await yes.p.batches());
    assert.equal(wire.includes('secret-value'), false, 'no field value on the wire');
    assert.equal(wire.includes('token'), false);
    assert.equal(wire.includes('"pw"'), false);

    for (const options of [{}, { consent: 'declined' }, { consent: 'accepted', gpc: true }]) {
        const no = await run(options);
        assert.equal(no.events.some((e) => e.n === 'form_field'), false, JSON.stringify(options));
        assert.deepEqual(no.events.filter((e) => e.t.startsWith('form')).map((e) => e.t), ['form_start']);
    }
});

test_('form_field follows consent given or revoked while the page is open, and ignores forms that opt out', async () => {
    const { p, inputs } = formPage();
    p.fireDoc('focusin', { target: inputs.email });
    p.fireWin('fsc:consent', { detail: 'accepted' });
    p.fireDoc('focusin', { target: inputs.message });
    p.fireWin('fsc:consent', { detail: 'declined' });
    p.fireDoc('focusin', { target: inputs.email });
    p.advance(1000);
    assert.deepEqual((await p.events()).filter((e) => e.n === 'form_field').map((e) => e.p.field), ['message']);

    const out = formPage({ consent: 'accepted' }, { id: 'x', 'data-fsa-ignore': '' });
    out.p.fireDoc('focusin', { target: out.inputs.email });
    out.p.advance(1000);
    assert.equal((await out.p.events()).some((e) => e.t.startsWith('form') || e.n === 'form_field'), false);
});

test_('form_field names are cut down to a safe alphabet; generated ids and e-mail like names are not reported; one event per change of field', async () => {
    const { p, inputs } = formPage({ consent: 'accepted' }, { id: 'contact' }, {
        'fields[Voor Naam]': 'text', 'f_8f3a9c1d2e4b5a67': 'text', 'jan@x.nl': 'text', input123456789: 'text', plain: 'text',
    });
    for (const name of ['fields[Voor Naam]', 'f_8f3a9c1d2e4b5a67', 'jan@x.nl', 'input123456789', 'plain', 'plain']) p.fireDoc('focusin', { target: inputs[name] });
    p.fireDoc('focusin', { target: inputs['fields[Voor Naam]'] });
    p.advance(1000);
    assert.deepEqual((await p.events()).filter((e) => e.n === 'form_field').map((e) => e.p.field), ['fields_voor_naam', 'plain', 'fields_voor_naam']);
});

test_('form_field is capped per form and page view', async () => {
    const names = Object.fromEntries(Array.from({ length: 60 }, (_, i) => [`veld${i}`, 'text']));
    const { p, inputs } = formPage({ consent: 'accepted' }, { id: 'long' }, names);
    for (const name of Object.keys(names)) p.fireDoc('focusin', { target: inputs[name] });
    p.advance(1000);
    assert.equal((await p.events()).filter((e) => e.n === 'form_field').length, 40);
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

test_('the pageview leaves at once (next tick), other events wait for the batch window', async () => {
    const p = page();
    p.advance(0);
    assert.deepEqual((await p.events()).map((e) => e.t), ['pageview'], 'no 1 s wait for the pageview');
    p.run(() => p.win.fsa.track('later'));
    p.advance(500);
    assert.equal(p.beacons.length, 1, 'a custom event waits for the batch window');
    p.advance(600);
    assert.equal(p.beacons.length, 2);
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

test_('heatmap: stable selector (id, allow-listed data attribute, no text), element and page position, per-element and per-page throttle', async () => {
    const p = page({ consent: 'accepted' });
    p.win.scrollY = 1000;
    p.win.innerWidth = 1280;
    const section = el('section', { id: 'hero' });
    const withData = el('a', { href: '/x', 'data-testid': 'buy-now', class: 'btn' }, section, { left: 100, top: 200, width: 200, height: 50 });
    const withText = el('div', { 'data-testid': 'Buy now for jan@x.nl', 'aria-label': 'Jan Jansen', class: 'card css-9f8e7d6c5b' }, section);
    const second = el('li', {}, el('ul', { class: 'menu' }), undefined, el('li', {}, null));
    second.previousElementSibling.tagName = 'LI';
    p.fireDoc('click', { type: 'click', target: withData, clientX: 200, clientY: 225, detail: 1 });
    p.fireDoc('click', { type: 'click', target: withText, clientX: 10, clientY: 10, detail: 1 });
    p.fireDoc('click', { type: 'click', target: second, clientX: 600, clientY: 10, detail: 1 });
    p.advance(1000);
    const heats = (await p.events()).filter((e) => e.t === 'heatmap');
    assert.equal(heats[0].sel, 'a[data-testid="buy-now"]');
    assert.deepEqual([heats[0].x, heats[0].y, heats[0].px, heats[0].py, heats[0].dh, heats[0].hk], [0.5, 0.5, 0.156, 0.306, 4000, 'click']);
    assert.equal(heats[1].sel, 'section#hero > div.card', 'text-like data attributes and hashed classes never reach the selector');
    assert.equal(heats[2].sel, 'ul.menu > li:nth-of-type(2)');
    assert.ok(!JSON.stringify(heats).includes('Jansen') && !JSON.stringify(heats).includes('Buy now'));

    // 10 per element, 100 per page view
    const q = page({ consent: 'accepted' });
    const button = el('button', { id: 'go' });
    for (let i = 0; i < 25; i++) q.fireDoc('click', { type: 'click', target: button, clientX: i * 100, clientY: 5, detail: 1 });
    q.advance(1000);
    assert.equal((await q.events()).filter((e) => e.t === 'heatmap').length, 10);
    const r = page({ consent: 'accepted' });
    for (let i = 0; i < 130; i++) r.fireDoc('click', { type: 'click', target: el('p', { id: 'p' + String.fromCharCode(97 + (i % 26)) + String.fromCharCode(97 + Math.floor(i / 26)) }), clientX: i * 50, clientY: 5, detail: 1 });
    r.advance(1000);
    assert.equal((await r.events()).filter((e) => e.t === 'heatmap').length, 100);
});

test_('heatmap: keyboard and script clicks (detail 0) have no pointer position and are skipped', async () => {
    const p = page({ consent: 'accepted' });
    p.fireDoc('click', { type: 'click', target: el('button', { id: 'go' }), clientX: 0, clientY: 0, detail: 0 });
    p.advance(1000);
    assert.equal((await p.events()).some((e) => e.t === 'heatmap'), false);
});

test_('scroll map: one scroll_max per page view with the deepest point at 10%, also without consent; a short page counts as 100', async () => {
    const p = page();
    p.win.scrollY = 1000; // (1000 + 800) / 4000 = 45 %
    p.fireWin('scroll', {});
    p.win.scrollY = 0;
    p.fireWin('scroll', {});
    p.doc.visibilityState = 'hidden';
    p.fireDoc('visibilitychange', {});
    assert.equal((await p.events()).some((e) => e.t === 'scroll_max'), false, 'hiding the tab is not the end of the page view');
    p.doc.visibilityState = 'visible';
    p.win.scrollY = 2400; // (2400 + 800) / 4000 = 80 %
    p.fireWin('scroll', {});
    p.fireWin('pagehide', {});
    p.fireWin('pagehide', {});
    let maxes = (await p.events()).filter((e) => e.t === 'scroll_max');
    assert.deepEqual(maxes.map((e) => e.sd), [80], 'sent once, at pagehide');
    assert.equal(maxes[0].u, 'https://studio.nl/');

    // Restored from the back/forward cache: a new measurement
    p.win.scrollY = 400; // 30 %
    p.fireWin('pageshow', { persisted: true });
    p.fireWin('scroll', {});
    p.fireWin('pagehide', {});
    maxes = (await p.events()).filter((e) => e.t === 'scroll_max');
    assert.deepEqual(maxes.map((e) => e.sd), [80, 30]);

    const short = page();
    short.doc.documentElement.scrollHeight = 600;
    short.doc.body.scrollHeight = 600;
    short.fireWin('pagehide', {});
    maxes = (await short.events()).filter((e) => e.t === 'scroll_max');
    assert.deepEqual(maxes.map((e) => e.sd), [100]);

    const spa = page();
    spa.win.scrollY = 3200;
    spa.fireWin('scroll', {});
    spa.run(() => spa.win.history.pushState({}, '', '/volgende'));
    spa.advance(10);
    spa.fireWin('pagehide', {});
    maxes = (await spa.events()).filter((e) => e.t === 'scroll_max');
    assert.deepEqual(maxes.map((e) => [e.u, e.sd]), [['https://studio.nl/', 100], ['https://studio.nl/volgende', 100]]);
});

test_('rage click: 3 clicks within 700 ms and 30 px is one frustration event, with or without consent; slow clicks are not', async () => {
    for (const consent of [null, 'accepted']) {
        const p = page({ consent });
        const cta = el('a', { id: 'cta', href: '/x' });
        for (let i = 0; i < 6; i++) { p.fireDoc('click', { type: 'click', target: cta, clientX: 50 + i, clientY: 50, detail: 1 }); p.tick(100); }
        p.tick(5000);
        for (let i = 0; i < 4; i++) { p.fireDoc('click', { type: 'click', target: cta, clientX: 50, clientY: 50, detail: 1 }); p.tick(800); }
        p.advance(1000);
        const events = await p.events();
        assert.deepEqual(events.filter((e) => e.t === 'frustration').map((e) => [e.n, e.sel]), [['rage_click', consent === null ? undefined : 'a#cta']], 'no selector without consent');
        assert.equal(events.some((e) => e.t === 'heatmap'), consent !== null);
    }
});

test_('dead click: a click on a non-interactive element without any DOM change or navigation within 1 s', async () => {
    const p = page({ consent: 'accepted' });
    const card = el('div', { class: 'card' }, el('section', { id: 'plans' }));
    p.fireDoc('click', { type: 'click', target: card, clientX: 10, clientY: 10, detail: 1 });
    p.advance(999);
    assert.equal((await p.events()).some((e) => e.t === 'frustration'), false, 'not before the second is over');
    p.advance(2);
    p.advance(1000);
    let events = (await p.events()).filter((e) => e.t === 'frustration');
    assert.deepEqual(events.map((e) => [e.n, e.sel]), [['dead_click', 'section#plans > div.card']]);

    // Without consent the signal counts but names no element.
    const anon = page();
    anon.fireDoc('click', { type: 'click', target: card, clientX: 10, clientY: 10, detail: 1 });
    anon.advance(1100);
    anon.advance(1000);
    const [dead] = (await anon.events()).filter((e) => e.t === 'frustration');
    assert.deepEqual([dead.n, 'sel' in dead, 'p' in dead], ['dead_click', false, false]);

    // DOM change, interactive element, navigation, text selection: no dead click
    const q = page();
    q.fireDoc('click', { type: 'click', target: el('div', { class: 'tab' }), clientX: 10, clientY: 10, detail: 1 });
    q.advance(300);
    q.mutate();
    q.fireDoc('click', { type: 'click', target: el('button', { id: 'go' }), clientX: 300, clientY: 10, detail: 1 });
    q.fireDoc('click', { type: 'click', target: el('span', { class: 'ico' }, el('a', { href: '/x' })), clientX: 600, clientY: 10, detail: 1 });
    q.fireDoc('click', { type: 'click', target: el('div', { class: 'go' }), clientX: 900, clientY: 10, detail: 1 });
    q.win.location.href = 'https://studio.nl/next';
    q.advance(2000);
    q.advance(1000);
    assert.equal((await q.events()).some((e) => e.t === 'frustration' && e.n === 'dead_click'), false);

    const s = page();
    s.win.getSelection = () => ({ toString: () => 'selected text' });
    s.fireDoc('click', { type: 'click', target: el('p', {}), clientX: 10, clientY: 10, detail: 1 });
    s.advance(3000);
    assert.equal((await s.events()).some((e) => e.t === 'frustration'), false, 'selecting text is not frustration');
});

test_('dead click: a pointer cursor makes an element interactive', async () => {
    const p = page();
    p.win.getComputedStyle = (node) => ({ cursor: node.getAttribute('class') === 'clicky' ? 'pointer' : 'auto' });
    p.fireDoc('click', { type: 'click', target: el('div', { class: 'clicky' }), clientX: 10, clientY: 10, detail: 1 });
    p.advance(3000);
    assert.equal((await p.events()).some((e) => e.t === 'frustration'), false);
});

test_('error click: a JS error within 1 s after a click sends the error class and a message hash, never the message or a stack', async () => {
    const p = page({ consent: 'accepted' });
    const buy = el('button', { class: 'buy' }, el('form', { id: 'order' }));
    p.fireDoc('click', { type: 'click', target: buy, clientX: 10, clientY: 10, detail: 1 });
    p.tick(400);
    const error = new TypeError("Cannot read properties of undefined (reading 'x') at https://studio.nl/app.js?token=abc123 for jan@x.nl");
    p.fireWin('error', { error, message: error.message });
    p.fireWin('error', { error, message: error.message });
    assert.equal(p.beacons.length, 1, 'sent at once');
    const [ev] = (await p.events()).filter((e) => e.t === 'frustration');
    assert.deepEqual([ev.n, ev.sel, ev.p.err], ['error_click', 'form#order > button.buy', 'TypeError']);
    assert.match(ev.p.eh, /^[0-9a-f]{8}$/);
    assert.deepEqual(Object.keys(ev.p).sort(), ['eh', 'err']);
    const raw = JSON.stringify(await p.batches());
    assert.ok(!/jan@x|token=abc|Cannot read|\bat https/.test(raw), 'no message, url or stack leaves the page');
    assert.equal((await p.events()).filter((e) => e.n === 'error_click').length, 1, 'one per click');

    // the same bug with other numbers hashes the same; a different bug differs
    const hashOf = async (message) => {
        const q = page({ consent: 'accepted' });
        q.fireDoc('click', { type: 'click', target: buy, clientX: 10, clientY: 10, detail: 1 });
        q.fireWin('unhandledrejection', { reason: new RangeError(message) });
        return (await q.events()).find((e) => e.n === 'error_click').p;
    };
    const a = await hashOf('Item 12 not found');
    const b = await hashOf('Item 99 not found');
    const c = await hashOf('Something else');
    assert.equal(a.eh, b.eh);
    assert.notEqual(a.eh, c.eh);
    assert.equal(a.err, 'RangeError');

    // Without consent: the signal only, no element, class or hash.
    const anon = page();
    anon.fireDoc('click', { type: 'click', target: buy, clientX: 10, clientY: 10, detail: 1 });
    anon.fireWin('error', { error: new TypeError('boom'), message: 'boom' });
    const [anonymous] = (await anon.events()).filter((e) => e.t === 'frustration');
    assert.deepEqual(Object.keys(anonymous).filter((k) => !['t', 'n', 'ts', 'u'].includes(k)), [], 'name only');
    assert.equal(anonymous.n, 'error_click');

    // The hash ignores everything after the first colon and personal data; it differs per site key.
    const hashFor = async (message, dataset) => {
        const q = page({ consent: 'accepted', dataset });
        q.fireDoc('click', { type: 'click', target: buy, clientX: 10, clientY: 10, detail: 1 });
        q.fireWin('error', { error: new Error(message), message });
        return (await q.events()).find((e) => e.n === 'error_click').p.eh;
    };
    assert.equal(await hashFor('Failed: jan@x.nl 06-12345678'), await hashFor('Failed: other thing'));
    assert.notEqual(await hashFor('Failed: x'), await hashFor('Failed: x', { sitekey: 'fsa_OtherSite0123456789abc' }));

    // too late: no error click
    const late = page();
    late.fireDoc('click', { type: 'click', target: buy, clientX: 10, clientY: 10, detail: 1 });
    late.tick(1500);
    late.fireWin('error', { error: new Error('late'), message: 'late' });
    late.advance(1000);
    assert.equal((await late.events()).some((e) => e.n === 'error_click'), false);
});

test_('quick back: back within 5 s via the Navigation API (forward and late are not); popstate fallback without it', async () => {
    const p = page({ navigationApi: true });
    p.tick(3000);
    p.navigate({ navigationType: 'traverse', destination: { index: 4 } }); // forward
    p.navigate({ navigationType: 'push', destination: { index: 2 } });
    assert.equal(p.beacons.length, 0);
    p.navigate({ navigationType: 'traverse', destination: { index: 2 } });
    assert.equal(p.beacons.length, 1, 'sent at once: the page is leaving');
    const [qb] = (await p.events()).filter((e) => e.t === 'frustration');
    assert.deepEqual([qb.n, qb.u, 'sel' in qb], ['quick_back', 'https://studio.nl/', false]);

    const late = page({ navigationApi: true });
    late.tick(6000);
    late.navigate({ navigationType: 'traverse', destination: { index: 2 } });
    late.advance(1000);
    assert.equal((await late.events()).some((e) => e.t === 'frustration'), false);

    const old = page();
    old.run(() => old.win.history.pushState({}, '', '/tweede'));
    old.advance(10);
    old.tick(2000);
    old.win.location.href = 'https://studio.nl/';
    old.fireWin('popstate', {});
    old.advance(1000);
    assert.deepEqual((await old.events()).filter((e) => e.t === 'frustration').map((e) => [e.n, e.u]), [['quick_back', 'https://studio.nl/tweede']]);

    const hash = page();
    hash.win.location.href = 'https://studio.nl/#sectie';
    hash.fireWin('popstate', {});
    hash.advance(1000);
    assert.equal((await hash.events()).some((e) => e.t === 'frustration'), false, 'an in-page anchor is not a back');
});

test_('frustration events are capped at 20 per page view', async () => {
    const p = page();
    for (let i = 0; i < 30; i++) {
        p.fireDoc('click', { type: 'click', target: el('i', { id: 'a' + String.fromCharCode(97 + i) }), clientX: i * 100, clientY: 5, detail: 1 });
        p.fireWin('error', { error: new Error('x' + i), message: 'x' + i });
    }
    p.advance(1000);
    assert.equal((await p.events()).filter((e) => e.t === 'frustration').length, 20);
});

test_('selectors that do not follow the grammar are never sent (no heatmap click, no selector on a signal)', async () => {
    const p = page({ consent: 'accepted' });
    const click = (target, x) => p.fireDoc('click', { type: 'click', target, clientX: x, clientY: 5, detail: 1 });
    let deep = null;
    for (let i = 0; i < 7; i++) deep = el('div', { class: 'level' + 'abcdefg'[i] }, deep);
    click(deep, 10); // longer than 4 parts: the path is cut at 4, still valid
    click(el('Custom_Tag', { id: 'x' }), 200); // underscore in the tag: not in the grammar
    click(el('a', { 'data-testid': 'ok', id: 'jan-0612345678-x' }), 400); // id with a phone-like number is skipped, the data attribute wins
    click(el('a', { 'data-testid': 'Buy now', 'data-fsa-id': 'jan@x.nl' }), 600); // text-like values: plain tag
    p.advance(1000);
    const sels = (await p.events()).filter((e) => e.t === 'heatmap').map((e) => e.sel);
    assert.deepEqual(sels, ['div.leveld > div.levele > div.levelf > div.levelg', 'a[data-testid="ok"]', 'a']);
    for (const sel of sels) assert.ok(sel.split(' > ').length <= 4 && sel.length <= 300);
});

test_('a page opened with an overlay token is not measured, drops the token from the URL at once and loads the overlay script', async () => {
    const token = 'v1.' + 'A'.repeat(30) + '.' + 's'.repeat(43);
    const p = page({ url: 'https://studio.nl/prijzen?x=1#top&fsa_overlay=' + token, consent: 'accepted' });
    assert.equal(p.win.location.href, 'https://studio.nl/prijzen?x=1#top', 'synchronously out of the address bar');
    assert.equal(p.win.__fsaOverlayToken, token, 'handed to overlay.js in memory');
    assert.equal(p.win.fsaOverlayMode, true, 'fsc.js and the Umami tracker stand down');
    p.run(() => { p.win.fsa.track('x'); p.win.fsa.pageview(); });
    p.fireDoc('click', { type: 'click', target: el('button', { id: 'buy' }), clientX: 5, clientY: 5, detail: 1 });
    p.advance(70_000);
    assert.equal((await p.batches()).length, 0, 'nothing sent: no pageview, heatmap click or heartbeat');
    assert.equal(p.local.data['fsa-id'], undefined, 'nothing stored');
    assert.deepEqual(p.doc.appended.map((n) => n.src), ['/_fsc/a/overlay.js']);
    assert.equal(p.run(() => p.win.fsa.pid()), null, 'no browser id for Intelligence in a staff view, also with consent');
    // Another fragment is a normal visit.
    const q = page({ url: 'https://studio.nl/prijzen#fsa_overlayx' });
    q.advance(1000);
    assert.equal((await q.events())[0].t, 'pageview');
    assert.equal(q.doc.appended.length, 0);
    assert.equal(q.win.fsaOverlayMode, undefined);
});

test_('a newsletter click token (fscn) leaves the address bar before the first pageview; other parameters and the fragment stay', async () => {
    const token = 'AQ' + 'k'.repeat(66);
    const p = page({ url: 'https://studio.nl/aanbod?utm_source=nieuwsbrief&fscn=' + token + '&x=1#top' });
    assert.equal(p.win.location.href, 'https://studio.nl/aanbod?utm_source=nieuwsbrief&x=1#top');
    p.advance(1000);
    const events = await p.events();
    assert.equal(events.filter((e) => e.t === 'pageview').length, 1, 'one pageview, not a second one for the cleanup');
    assert.equal(JSON.stringify(events).includes(token), false, 'the token is never sent');
    const only = page({ url: 'https://studio.nl/?fscn=' + token });
    assert.equal(only.win.location.href, 'https://studio.nl/');
    const none = page({ url: 'https://studio.nl/?xfscn=1' });
    assert.equal(none.win.location.href, 'https://studio.nl/?xfscn=1');
});

test_('nl: 1 rides on the landing pageview that had fscn, a boolean without the token, and on no other view (plan analytics-complete D6)', async () => {
    const token = 'AQ' + 'k'.repeat(66);
    const p = page({ url: 'https://studio.nl/aanbod?fscn=' + token });
    p.advance(1000);
    const first = (await p.events()).filter((e) => e.t === 'pageview');
    assert.equal(first.length, 1);
    assert.equal(first[0].nl, 1);
    assert.equal(JSON.stringify(first).includes(token), false);
    // A later view of the same visit (SPA navigation) is not a newsletter landing.
    p.run(() => p.win.history.pushState({}, '', '/prijzen'));
    p.advance(1000);
    const views = (await p.events()).filter((e) => e.t === 'pageview');
    assert.equal(views.at(-1).u.includes('prijzen'), true);
    assert.equal('nl' in views.at(-1), false);
    // Without fscn the field is absent.
    const plain = page({ url: 'https://studio.nl/?utm_source=nieuwsbrief' });
    plain.advance(1000);
    assert.equal('nl' in (await plain.events()).find((e) => e.t === 'pageview'), false);
});

test_('the minified build is the current source', () => {
    const hash = createHash('sha256').update(SOURCE).digest('hex');
    assert.ok(MINIFIED.startsWith(`/*! fsa.js sha256:${hash} */`), 'run scripts/build-fsa.sh');
});

test_('with consent the browser id is mirrored into a first-party cookie; revoking, GPC and no consent remove or never set it', async () => {
    const p = page({ consent: 'accepted', url: 'https://studio.nl/' });
    const set = p.doc.cookies.filter((c) => c.startsWith('fsa_pid=') && !c.startsWith('fsa_pid=;'));
    assert.equal(set.length, 1, 'mirrored once, at the first view');
    assert.match(set[0], /^fsa_pid=11111111-2222-4333-8444-555555555555; Max-Age=34128000; Path=\/; SameSite=Lax; Secure$/);

    p.fireWin('fsc:consent', { detail: 'declined' });
    assert.match(p.doc.cookies.at(-1), /^fsa_pid=; Max-Age=0; Path=\//, 'revoking removes the cookie at once');

    const none = page({ consent: 'declined' });
    assert.equal(none.doc.cookies.some((c) => /^fsa_pid=[^;]/.test(c)), false, 'never set without consent');
    const gpc = page({ consent: 'accepted', gpc: true });
    assert.equal(gpc.doc.cookies.some((c) => /^fsa_pid=[^;]/.test(c)), false, 'never set under Global Privacy Control');

    const stale = page({ consent: 'accepted', gpc: true, stored: { 'fsa-id': 'kept-id-0123456789abcdef' } });
    assert.match(stale.doc.cookies.at(-1), /^fsa_pid=; Max-Age=0; Path=\//, 'GPC clears an existing mirror cookie');

    // The cookie follows a removed id: an existing id is mirrored too, and plain http gets no Secure flag.
    const old = page({ consent: 'accepted', url: 'http://localhost/', stored: { 'fsa-id': 'kept-id-0123456789abcdef' } });
    assert.equal(old.doc.cookies.find((c) => c.startsWith('fsa_pid=kept')), 'fsa_pid=kept-id-0123456789abcdef; Max-Age=34128000; Path=/; SameSite=Lax');
});

test_('a configured pid cookie domain is used for setting and removing, only when the page lies under it', async () => {
    const p = page({ consent: 'accepted', url: 'https://www.studio.nl/', dataset: { pidDomain: '.studio.nl' } });
    const set = p.doc.cookies.filter((c) => /^fsa_pid=[^;]/.test(c));
    assert.equal(set.length, 1);
    assert.equal(set[0], 'fsa_pid=11111111-2222-4333-8444-555555555555; Max-Age=34128000; Path=/; Domain=.studio.nl; SameSite=Lax; Secure');
    assert.ok(p.doc.cookies.includes('fsa_pid=; Max-Age=0; Path=/; SameSite=Lax; Secure'), 'a host-only copy from before is dropped');

    p.fireWin('fsc:consent', { detail: 'declined' });
    const last = p.doc.cookies.slice(-2);
    assert.deepEqual(last, ['fsa_pid=; Max-Age=0; Path=/; SameSite=Lax; Secure', 'fsa_pid=; Max-Age=0; Path=/; Domain=.studio.nl; SameSite=Lax; Secure'], 'revoking removes both copies');

    const gpc = page({ consent: 'accepted', gpc: true, url: 'https://studio.nl/', dataset: { pidDomain: 'studio.nl' }, stored: { 'fsa-id': 'kept-id-0123456789abcdef' } });
    assert.ok(gpc.doc.cookies.includes('fsa_pid=; Max-Age=0; Path=/; Domain=studio.nl; SameSite=Lax; Secure'), 'GPC removes the domain cookie');
    assert.equal(gpc.doc.cookies.some((c) => /^fsa_pid=[^;]/.test(c)), false);

    // Another host or a malformed value: host-only, as before.
    for (const pidDomain of ['.other.nl', 'studio.nl; Secure', 'nl', '']) {
        const q = page({ consent: 'accepted', url: 'https://studio.nl/', dataset: { pidDomain } });
        assert.equal(q.doc.cookies.find((c) => /^fsa_pid=[^;]/.test(c)), 'fsa_pid=11111111-2222-4333-8444-555555555555; Max-Age=34128000; Path=/; SameSite=Lax; Secure', pidDomain);
        assert.equal(q.doc.cookies.some((c) => c.includes('Domain=')), false, pidDomain);
    }
});

for (const [label, source] of VARIANTS) {
    for (const [name, fn] of tests) {
        realTest(`[${label}] ${name}`, async () => {
            CURRENT.source = source;
            await fn();
        });
    }
}

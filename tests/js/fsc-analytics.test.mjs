// Tests of resources/assets/fsc-analytics.js with a minimal fake browser (no dependencies).
// Run: `node --test tests/js` or `bun test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SOURCE = readFileSync(fileURLToPath(new URL('../../resources/assets/fsc-analytics.js', import.meta.url)), 'utf8');
const WEBSITE = '2d585159-5e61-400a-8326-029816f769a9';

function storage(initial = {}) {
    const data = { ...initial };
    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = String(value); },
        removeItem: (key) => { delete data[key]; },
        data,
    };
}

function element(tag, doc) {
    const attrs = {};
    const listeners = {};
    const el = {
        tagName: tag.toUpperCase(),
        children: [],
        dataset: {},
        classList: { contains: (name) => (attrs.class || '').split(' ').includes(name) },
        setAttribute: (name, value) => { attrs[name] = String(value); if (name === 'id') el.id = String(value); },
        getAttribute: (name) => (name in attrs ? attrs[name] : null),
        hasAttribute: (name) => name in attrs,
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        fire: (type) => (listeners[type] || []).forEach((fn) => fn({})),
        appendChild: (child) => { el.children.push(child); child.parentNode = el; if (child.id) doc.ids[child.id] = child; return child; },
        remove: () => { if (el.parentNode) el.parentNode.children = el.parentNode.children.filter((c) => c !== el); delete doc.ids[el.id]; },
        querySelector: (selector) => el.children.find((c) => selector.includes(`name="${c.name}"`)) || null,
        attrs,
    };
    Object.defineProperty(el, 'id', { get: () => attrs.id, set: (v) => { attrs.id = v; }, configurable: true });
    return el;
}

/** A page with the tracker tag, the helper running, and optional stored consent / URL / referrer. */
function page({ consent = null, search = '', referrer = '', session = {}, consentKey, hash = '', overlayMode, excludedFlag, excludedStored, strip } = {}) {
    const doc = { ids: {}, referrer, listeners: {} };
    doc.head = element('head', doc);
    doc.createElement = (tag) => element(tag, doc);
    doc.getElementById = (id) => doc.ids[id] || null;
    doc.addEventListener = (type, fn) => { (doc.listeners[type] ||= []).push(fn); };

    const tracker = element('script', doc);
    tracker.setAttribute('id', 'fsc-analytics');
    tracker.setAttribute('data-website-id', WEBSITE);
    tracker.setAttribute('data-host-url', '/_fsc/a');
    tracker.setAttribute('data-fsc-recorder-src', '/_fsc/a/recorder.js');
    if (strip !== undefined) {
        tracker.setAttribute('data-before-send', 'fscUmamiBeforeSend');
        tracker.setAttribute('data-fsc-strip-params', strip);
    }
    doc.head.appendChild(tracker);

    doc.currentScript = { dataset: { tracker: 'fsc-analytics', ...(consentKey ? { consentKey } : {}) } };

    const fetched = [];
    const bodies = [];
    const winListeners = {};
    const local = storage({ ...(consent === null ? {} : { [consentKey || 'fsc-consent']: consent }), ...(excludedStored ? { 'fsa-exclude': '1' } : {}) });
    const win = {
        localStorage: local,
        sessionStorage: storage(session),
        location: { pathname: '/', search, hash, origin: 'https://studio.nl' },
        fsaOverlayMode: overlayMode,
        fsaExcluded: excludedFlag,
        Response,
        fetch: (input, init) => { fetched.push(String(input)); bodies.push(init ? init.body : undefined); return Promise.resolve(new Response('{"real":true}')); },
        addEventListener: (type, fn) => { (winListeners[type] ||= []).push(fn); },
        dispatch: (type, event) => (winListeners[type] || []).forEach((fn) => fn(event)),
    };
    win.history = {
        state: null,
        replaceState(_state, _title, next) {
            const u = new URL(next, 'https://studio.nl');
            Object.assign(win.location, { pathname: u.pathname, search: u.search, hash: u.hash });
        },
    };

    new Function('window', 'document', SOURCE)(win, doc);

    return { win, doc, tracker, fetched, bodies, recorder: () => doc.getElementById('fsc-analytics-recorder') };
}

/**
 * Runs the real Umami tracker (tests/Fixtures/umami/script.js) in the fake page `p`, the way a browser does with the deferred tags:
 * the tracker first (document still `interactive`), the helper already ran, then the document completes and the pageview goes out.
 */
function runUmami(p, { href, referrer = '', title = 'Planner' }) {
    const u = new URL(href);
    Object.assign(p.win.location, { href, hostname: u.hostname, pathname: u.pathname, search: u.search, origin: u.origin });
    Object.assign(p.win, { screen: { width: 1920, height: 1080 }, navigator: { language: 'en' }, top: p.win, document: p.doc });
    p.win.history.pushState = () => {};
    const listeners = {};
    Object.assign(p.doc, {
        referrer,
        title,
        readyState: 'interactive',
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        currentScript: { src: 'https://studio.nl/_fsc/a/script.js', getAttribute: (name) => p.tracker.getAttribute(name) },
    });
    // The tracker calls the global `fetch`; in a browser that is the helper's guard on window.fetch.
    new Function('window', 'fetch', UMAMI)(p.win, (...args) => p.win.fetch(...args));
    p.doc.readyState = 'complete';
    (listeners.readystatechange || []).forEach((fn) => fn({}));
}

const UMAMI = readFileSync(fileURLToPath(new URL('../Fixtures/umami/script.js', import.meta.url)), 'utf8');
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

test('stripParams: the real Umami tracker calls the helper before sending and the pageview url loses only those parameters', async () => {
    const p = page({ strip: 'gpu,cpu,ram,res,hz,disk,goal' });
    assert.equal(typeof p.win.fscUmamiBeforeSend, 'function');
    runUmami(p, { href: 'https://studio.nl/?gpu=rtx-4060&cpu=ryzen-5-5600&ram=16&utm_source=reddit&res=1080p&hz=144&disk=nvme&goal=balanced&gclid=G1#plan', referrer: 'https://studio.nl/detect?cpu=i5&gpu=gtx&w=1' });
    await tick(); await tick();

    const send = p.fetched.indexOf('/_fsc/a/api/send');
    assert.notEqual(send, -1, 'the pageview was sent');
    const { type, payload } = JSON.parse(p.bodies[send]);
    assert.equal(type, 'event');
    assert.equal(payload.url, 'https://studio.nl/?utm_source=reddit&gclid=G1#plan', 'utm_* and click ids stay, the hash stays');
    assert.equal(payload.referrer, '/detect?w=1', 'a same-site referrer loses them too');
    assert.equal(payload.website, WEBSITE);
    assert.equal(p.win.location.search, '?gpu=rtx-4060&cpu=ryzen-5-5600&ram=16&utm_source=reddit&res=1080p&hz=144&disk=nvme&goal=balanced&gclid=G1', 'the address bar is untouched');
});

test('stripParams: an external referrer, a query without those parameters and custom events pass unchanged; DNT still wins', async () => {
    const p = page({ strip: 'gpu' });
    runUmami(p, { href: 'https://studio.nl/games?sort=new', referrer: 'https://www.google.com/search?gpu=x&q=fps' });
    await tick(); await tick();
    const { payload } = JSON.parse(p.bodies[p.fetched.indexOf('/_fsc/a/api/send')]);
    assert.equal(payload.url, 'https://studio.nl/games?sort=new');
    assert.equal(payload.referrer, 'https://www.google.com/search?gpu=x&q=fps', 'another site’s query is not ours to change');

    const before = p.fetched.length;
    p.win.umami.track('site_search', { term: 'gta' });
    await tick(); await tick();
    const event = JSON.parse(p.bodies[before]);
    assert.equal(event.payload.name, 'site_search');
    assert.deepEqual(event.payload.data, { term: 'gta' });

    const dnt = page({ strip: 'gpu' });
    dnt.tracker.setAttribute('data-do-not-track', 'true');
    dnt.win.doNotTrack = '1';
    runUmami(dnt, { href: 'https://studio.nl/?gpu=x' });
    await tick(); await tick();
    assert.equal(dnt.fetched.includes('/_fsc/a/api/send'), false, 'do-not-track: nothing is sent');
});

test('stripParams: without the setting there is no before-send function and the url goes out as is', async () => {
    const p = page();
    assert.equal(p.win.fscUmamiBeforeSend, undefined);
    runUmami(p, { href: 'https://studio.nl/?gpu=rtx-4060' });
    await tick(); await tick();
    assert.equal(JSON.parse(p.bodies[p.fetched.indexOf('/_fsc/a/api/send')]).payload.url, 'https://studio.nl/?gpu=rtx-4060');
});

test('stripParams: utm_*, click ids and invalid names in the list are ignored', () => {
    const p = page({ strip: 'utm_source, gclid,FBCLID,bad name,gpu' });
    const out = p.win.fscUmamiBeforeSend('event', { url: 'https://studio.nl/?utm_source=a&gclid=b&FBCLID=c&gpu=d', referrer: '' });
    assert.equal(out.url, 'https://studio.nl/?utm_source=a&gclid=b&FBCLID=c');
    assert.equal(page({ strip: 'utm_source,gclid' }).win.fscUmamiBeforeSend, undefined, 'nothing left to strip: no hook');
});

test('stripParams: recorder uploads (heatmap and replay) lose the parameters after consent; without consent nothing leaves', async () => {
    const p = page({ strip: 'gpu,cpu' });
    p.win.umami = {};
    // The shapes recorder.js (Umami 3.4) posts: heatmap events with `url`, rrweb meta (type 4) with `href`, the url-change custom event.
    const body = JSON.stringify({
        type: 'record',
        payload: {
            website: WEBSITE,
            timestamp: 1,
            events: [
                { type: 4, data: { href: 'https://studio.nl/?gpu=rtx&utm_source=x', width: 1, height: 1 } },
                { type: 5, data: { tag: 'url-change', payload: { url: 'https://studio.nl/games/x?cpu=i5&gpu=gtx' } } },
                { type: 2, data: { node: { attributes: { href: '/?gpu=rtx&cpu=i5#a', 'data-x': 'say "hi"\\' } } } },
            ],
        },
    });
    await p.win.fetch('/_fsc/a/api/record', { method: 'POST', body });
    assert.deepEqual(p.fetched, [], 'no consent: blocked');

    p.win.dispatch('fsc:consent', { detail: 'accepted' });
    await p.win.fetch('/_fsc/a/api/record', { method: 'POST', body, keepalive: true, headers: { 'x-umami-cache': 'c' } });
    const sent = JSON.parse(p.bodies[0]);
    assert.equal(sent.payload.events[0].data.href, 'https://studio.nl/?utm_source=x');
    assert.equal(sent.payload.events[1].data.payload.url, 'https://studio.nl/games/x');
    assert.equal(sent.payload.events[2].data.node.attributes.href, '/#a');
    assert.equal(sent.payload.events[2].data.node.attributes['data-x'], 'say "hi"\\');

    const heatmap = JSON.stringify({ type: 'heatmap', payload: { website: WEBSITE, events: [{ type: 'click', url: 'https://studio.nl/?ram=16&gpu=x', x: 1 }] } });
    await p.win.fetch('https://analytics.fullstackstudio.nl/api/record', { method: 'POST', body: heatmap });
    assert.equal(JSON.parse(p.bodies[1]).payload.events[0].url, 'https://studio.nl/?ram=16');

    // Other requests are not touched.
    await p.win.fetch('/api/thing', { method: 'POST', body: '{"u":"/?gpu=x"}' });
    assert.equal(p.bodies[2], '{"u":"/?gpu=x"}');
});

test('without consent the recorder is not loaded and nothing goes to the record endpoint; pageviews pass', async () => {
    const p = page();
    p.win.umami = {};
    p.tracker.fire('load');
    assert.equal(p.recorder(), null);

    const blocked = await p.win.fetch('/_fsc/a/api/record', { method: 'POST' });
    assert.deepEqual(await blocked.json(), {});
    await p.win.fetch('/_fsc/a/api/send', { method: 'POST' });
    assert.deepEqual(p.fetched, ['/_fsc/a/api/send']);
    assert.equal(p.win.fscAnalytics.hasConsent(), false);
});

test('stored consent loads the recorder after the tracker, with the website id and host', () => {
    const p = page({ consent: 'accepted' });
    assert.equal(p.recorder(), null, 'waits for the tracker');
    p.win.umami = {};
    p.tracker.fire('load');

    const rec = p.recorder();
    assert.ok(rec);
    assert.equal(rec.src, '/_fsc/a/recorder.js');
    assert.equal(rec.getAttribute('data-website-id'), WEBSITE);
    assert.equal(rec.getAttribute('data-host-url'), '/_fsc/a');
});

test('accepting via the event starts it at once; revoking stops every upload immediately', async () => {
    const p = page();
    p.win.umami = {};
    p.win.dispatch('fsc:consent', { detail: 'accepted' });
    assert.ok(p.recorder());

    await p.win.fetch('https://analytics.fullstackstudio.nl/api/record');
    assert.deepEqual(p.fetched, ['https://analytics.fullstackstudio.nl/api/record']);

    p.win.dispatch('fsc:consent', { detail: 'declined' });
    assert.equal(p.recorder(), null);
    await p.win.fetch('https://analytics.fullstackstudio.nl/api/record');
    assert.equal(p.fetched.length, 1, 'no upload after revoking');
});

test('consent formats: categories, booleans, JSON in storage, a custom key, and a storage change in another tab', () => {
    for (const detail of [{ categories: ['analytics'] }, { analytics: true }, true, 'all']) {
        const p = page();
        p.win.umami = {};
        p.win.dispatch('fsc:consent', { detail });
        assert.ok(p.recorder(), JSON.stringify(detail));
    }

    for (const detail of [{ categories: ['marketing'] }, false, 'necessary']) {
        const p = page();
        p.win.umami = {};
        p.win.dispatch('fsc:consent', { detail });
        assert.equal(p.recorder(), null, JSON.stringify(detail));
    }

    const json = page({ consent: '{"analytics":true}', consentKey: 'site-cookies' });
    json.win.umami = {};
    json.tracker.fire('load');
    assert.ok(json.recorder());

    json.win.dispatch('storage', { key: 'site-cookies', newValue: 'declined' });
    assert.equal(json.recorder(), null);
    assert.equal(json.win.fscAnalytics.hasConsent(), false);
});

test('first-touch attribution keeps utm and click ids, the landing URL and an external referrer only', () => {
    const p = page({ search: '?utm_source=google&utm_campaign=herfst&gclid=Cj0K&fbclid=FB1&x=1', referrer: 'https://www.google.com/search?q=a' });
    assert.deepEqual(p.win.fscAnalytics.attribution(), {
        landing_url: '/?utm_source=google&utm_campaign=herfst&gclid=Cj0K&fbclid=FB1&x=1',
        utm_source: 'google',
        utm_campaign: 'herfst',
        gclid: 'Cj0K',
        fbclid: 'FB1',
        referrer: 'https://www.google.com/search?q=a',
    });

    // Next page in the same visit: the first touch stays.
    const next = page({ search: '?utm_source=newsletter', referrer: 'https://studio.nl/', session: p.win.sessionStorage.data });
    assert.equal(next.win.fscAnalytics.attribution().utm_source, 'google');

    const internal = page({ referrer: 'https://studio.nl/about' });
    assert.equal(internal.win.fscAnalytics.attribution().referrer, undefined);
});

test('a newsletter click token (fscn) leaves the address bar before the landing URL is kept', () => {
    const p = page({ search: '?utm_source=nieuwsbrief&fscn=' + 'AQ' + 'k'.repeat(66) + '&x=1', hash: '#top' });
    assert.equal(p.win.location.search, '?utm_source=nieuwsbrief&x=1');
    assert.equal(p.win.location.hash, '#top');
    assert.equal(p.win.fscAnalytics.attribution().landing_url, '/?utm_source=nieuwsbrief&x=1');
});

test('SDK forms and opted-in forms get the attribution as a hidden field', () => {
    const p = page({ search: '?msclkid=M1' });
    const form = element('form', p.doc);
    form.setAttribute('class', 'fsc-form');
    p.doc.listeners.submit.forEach((fn) => fn({ target: form }));

    const field = form.children.find((c) => c.name === '_fsc_attribution');
    assert.ok(field);
    assert.equal(field.type, 'hidden');
    assert.equal(JSON.parse(field.value).msclkid, 'M1');

    const other = element('form', p.doc);
    p.doc.listeners.submit.forEach((fn) => fn({ target: other }));
    assert.equal(other.children.length, 0, 'other forms are left alone');
});

test('in heatmap overlay mode (flag from fsa.js, or the fragment) no recorder, no replay and no attribution, the API stays', () => {
    for (const opts of [{ overlayMode: true }, { hash: '#fsa_overlay=v1.abc' }]) {
        const p = page({ consent: 'accepted', search: '?utm_source=nieuwsbrief', ...opts });
        p.win.umami = { track: () => { throw new Error('no counting'); } };
        p.tracker.fire('load');
        assert.equal(p.recorder(), null);
        assert.equal(p.win.fscAnalytics.overlay, true);
        assert.equal(p.win.fscAnalytics.track('x'), undefined);
        assert.equal(p.win.fscAnalytics.hasConsent(), false);
        assert.equal(p.win.sessionStorage.getItem('fsc-attribution'), null);
    }
});

test('an excluded browser (flag from fsa.js, the stored flag, or ?fsa_exclude=1) gets no recorder, no attribution and no counting, the API stays', () => {
    for (const opts of [{ excludedFlag: true }, { excludedStored: true }, { search: '?fsa_exclude=1&utm_source=x' }]) {
        const p = page({ consent: 'accepted', ...opts });
        p.win.umami = { track: () => { throw new Error('no counting'); } };
        p.tracker.fire('load');
        assert.equal(p.recorder(), null);
        assert.equal(p.win.fscAnalytics.overlay, true);
        assert.equal(p.win.fscAnalytics.track('x'), undefined);
        assert.equal(p.win.sessionStorage.getItem('fsc-attribution'), null);
    }
});

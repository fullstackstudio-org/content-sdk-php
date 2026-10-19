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
function page({ consent = null, search = '', referrer = '', session = {}, consentKey } = {}) {
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
    doc.head.appendChild(tracker);

    doc.currentScript = { dataset: { tracker: 'fsc-analytics', ...(consentKey ? { consentKey } : {}) } };

    const fetched = [];
    const winListeners = {};
    const local = storage(consent === null ? {} : { [consentKey || 'fsc-consent']: consent });
    const win = {
        localStorage: local,
        sessionStorage: storage(session),
        location: { pathname: '/', search, origin: 'https://studio.nl' },
        Response,
        fetch: (input) => { fetched.push(String(input)); return Promise.resolve(new Response('{"real":true}')); },
        addEventListener: (type, fn) => { (winListeners[type] ||= []).push(fn); },
        dispatch: (type, event) => (winListeners[type] || []).forEach((fn) => fn(event)),
    };

    new Function('window', 'document', SOURCE)(win, doc);

    return { win, doc, tracker, fetched, recorder: () => doc.getElementById('fsc-analytics-recorder') };
}

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

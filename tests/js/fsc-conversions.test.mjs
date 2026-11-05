// Tests of the conversion markers in resources/assets/fsc-analytics.js (begin_checkout, appointment_booked, purchase) with a minimal
// fake browser. Run: `node --test tests/js` or `bun test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SOURCE = readFileSync(fileURLToPath(new URL('../../resources/assets/fsc-analytics.js', import.meta.url)), 'utf8');

function storage() {
    const data = {};
    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = String(value); },
        removeItem: (key) => { delete data[key]; },
        data,
    };
}

/** A hidden marker as `<x-content::...>` prints it: attrs = { 'data-fsc-fire': 'purchase', ... }. */
function marker(attrs) {
    const map = { ...attrs };
    return {
        attributes: Object.keys(map).map((name) => ({ name, value: map[name] })),
        getAttribute: (name) => (name in map ? map[name] : null),
        setAttribute: (name, value) => { map[name] = String(value); },
        map,
    };
}

/** The page: markers in the DOM, optional engines (native `fsa`, `umami`), a document that is already parsed (or still loading). */
function page({ markers = [], fsa = false, umami = false, loading = false, session = storage() } = {}) {
    const calls = [];
    const docListeners = {};
    const winListeners = {};
    const doc = {
        readyState: loading ? 'loading' : 'complete',
        referrer: '',
        currentScript: { dataset: {} },
        head: { appendChild: () => {} },
        getElementById: () => null,
        querySelectorAll: (selector) => (selector === '[data-fsc-fire]' ? markers : []),
        addEventListener: (type, fn) => { (docListeners[type] ||= []).push(fn); },
    };
    const win = {
        localStorage: storage(),
        sessionStorage: session,
        location: { pathname: '/', search: '', hash: '', origin: 'https://studio.nl' },
        Response,
        fetch: () => Promise.resolve(new Response('{}')),
        addEventListener: (type, fn) => { (winListeners[type] ||= []).push(fn); },
    };
    const install = () => {
        if (fsa) {
            win.fsa = {
                track: (name, props) => calls.push(['fsa.track', name, props]),
                revenue: (amount, currency, props) => calls.push(['fsa.revenue', amount, currency, props]),
            };
        }
        if (umami) win.umami = { track: (name, props) => calls.push(['umami.track', name, props]) };
    };
    install();
    new Function('window', 'document', SOURCE)(win, doc);

    return {
        win,
        calls,
        session,
        ready: () => (docListeners.DOMContentLoaded || []).forEach((fn) => fn({})),
        load: () => (winListeners.load || []).forEach((fn) => fn({})),
        install: (opts) => { Object.assign(win, {}); if (opts.fsa) { fsa = true; } if (opts.umami) { umami = true; } install(); },
    };
}

test('begin_checkout fires once with its props, even when the page scans twice', () => {
    const m = marker({ 'data-fsc-fire': 'begin_checkout', 'data-fsc-prop-items': '3', 'data-fsc-prop-value': '49.95' });
    const p = page({ markers: [m], fsa: true });

    assert.deepEqual(p.calls, [['fsa.track', 'begin_checkout', { items: '3', value: '49.95' }]]);
    p.win.fscAnalytics.scan();
    assert.equal(p.calls.length, 1, 'once per page');
    assert.equal(m.map['data-fsc-fired'], '1');
});

test('appointment_booked carries the booking path and fires once per reference in a session', () => {
    const session = storage();
    const attrs = { 'data-fsc-fire': 'appointment_booked', 'data-fsc-once': 'b-42', 'data-fsc-prop-service': 'knippen', 'data-fsc-prop-booking-path': '/afspraak' };
    const first = page({ markers: [marker(attrs)], fsa: true, session });
    assert.deepEqual(first.calls, [['fsa.track', 'appointment_booked', { service: 'knippen', booking_path: '/afspraak' }]]);

    const reload = page({ markers: [marker(attrs)], fsa: true, session });
    assert.deepEqual(reload.calls, [], 'a reload of the confirmation page does not count the booking again');

    const other = page({ markers: [marker({ ...attrs, 'data-fsc-once': 'b-43' })], fsa: true, session });
    assert.equal(other.calls.length, 1, 'another booking counts');
});

test('purchase is a revenue event named purchase with the order, once per order per session', () => {
    const session = storage();
    const attrs = { 'data-fsc-fire': 'purchase', 'data-fsc-revenue': '104.85', 'data-fsc-currency': 'EUR', 'data-fsc-once': '0042', 'data-fsc-prop-order': '0042' };
    const first = page({ markers: [marker(attrs)], fsa: true, session });
    assert.deepEqual(first.calls, [['fsa.revenue', 104.85, 'EUR', { name: 'purchase', order: '0042' }]]);

    const reload = page({ markers: [marker(attrs)], fsa: true, session });
    assert.deepEqual(reload.calls, []);
});

test('purchase on the Umami engine uses Umami revenue and currency; engine both counts in both', () => {
    const attrs = { 'data-fsc-fire': 'purchase', 'data-fsc-revenue': '20', 'data-fsc-currency': 'eur', 'data-fsc-prop-order': 'A-1' };
    const umami = page({ markers: [marker(attrs)], umami: true });
    assert.deepEqual(umami.calls, [['umami.track', 'purchase', { order: 'A-1', revenue: 20, currency: 'EUR' }]]);

    const both = page({ markers: [marker(attrs)], fsa: true, umami: true });
    assert.deepEqual(both.calls.map((c) => c[0]), ['fsa.revenue', 'umami.track']);
});

test('a marker waits for a tracker that arrives late, and fires on load; no tracker at all fires nothing and never throws', () => {
    const m = marker({ 'data-fsc-fire': 'begin_checkout' });
    const p = page({ markers: [m] });
    assert.deepEqual(p.calls, []);
    assert.equal(m.map['data-fsc-fired'], undefined);

    p.install({ umami: true });
    p.load();
    assert.deepEqual(p.calls, [['umami.track', 'begin_checkout', {}]]);
});

test('markers in a document that is still parsing fire at DOMContentLoaded', () => {
    const p = page({ markers: [marker({ 'data-fsc-fire': 'begin_checkout' })], fsa: true, loading: true });
    assert.deepEqual(p.calls, []);
    p.ready();
    assert.equal(p.calls.length, 1);
});

test('events need no consent, and invalid markers (bad name, zero or bad revenue) send nothing', () => {
    const p = page({
        fsa: true,
        markers: [
            marker({ 'data-fsc-fire': 'bad name!' }),
            marker({ 'data-fsc-fire': 'purchase', 'data-fsc-revenue': '0', 'data-fsc-currency': 'EUR' }),
            marker({ 'data-fsc-fire': 'purchase', 'data-fsc-revenue': '5', 'data-fsc-currency': 'EURO' }),
            marker({ 'data-fsc-fire': 'begin_checkout' }),
        ],
    });
    assert.equal(p.win.fscAnalytics.hasConsent(), false);
    assert.deepEqual(p.calls, [['fsa.track', 'begin_checkout', {}]]);
});

test('fscAnalytics.revenue and track are public for custom sites', () => {
    const p = page({ fsa: true });
    p.win.fscAnalytics.revenue(12.5, 'eur', { name: 'purchase', order: 'X-9' });
    p.win.fscAnalytics.track('appointment_booked', { service: 'a' });
    assert.deepEqual(p.calls, [['fsa.revenue', 12.5, 'EUR', { name: 'purchase', order: 'X-9' }], ['fsa.track', 'appointment_booked', { service: 'a' }]]);
});

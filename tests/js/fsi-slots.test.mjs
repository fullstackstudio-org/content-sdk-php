// Tests of resources/assets/fsi-slots.js (FullStack Intelligence personalisation, phase 7) with a minimal fake DOM (no dependencies).
// Run: `bun test tests/js` (or `node --test tests/js/fsi-slots.test.mjs`).
// The fake layout: an element with `h` has that height; otherwise its height is the sum of its children; a text node is 20 px per started
// 20 characters; `hidden` is 0; `style.minHeight` is a floor. A slot's top comes from its `top` attribute. That is enough to prove the
// no-layout-shift rule, the fair control group, the deadline and the consent rules.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SOURCE = readFileSync(fileURLToPath(new URL('../../resources/assets/fsi-slots.js', import.meta.url)), 'utf8');

class Node {
    constructor(type, tag = '', attrs = {}) {
        this.nodeType = type;
        this.tagName = tag.toUpperCase();
        this.attrs = { ...attrs };
        this.childNodes = [];
        this.parentNode = null;
        this.data = '';
        this.hidden = false;
        this.style = { minHeight: '', visibility: '' };
        this.listeners = {};
        if (this.tagName === 'TEMPLATE') this.content = new Node(11);
    }
    get firstChild() { return this.childNodes[0] || null; }
    get nextSibling() { const s = this.parentNode ? this.parentNode.childNodes : []; return s[s.indexOf(this) + 1] || null; }
    get firstElementChild() { return this.childNodes.find((n) => n.nodeType === 1) || null; }
    get nextElementSibling() { const s = this.parentNode ? this.parentNode.childNodes : []; return s.slice(s.indexOf(this) + 1).find((n) => n.nodeType === 1) || null; }
    getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; }
    setAttribute(name, value) { this.attrs[name] = String(value); }
    removeAttribute(name) { delete this.attrs[name]; }
    hasAttribute(name) { return name in this.attrs; }
    detach(node) { if (node.parentNode) { node.parentNode.childNodes.splice(node.parentNode.childNodes.indexOf(node), 1); node.parentNode = null; } }
    insertBefore(node, ref) {
        const nodes = node.nodeType === 11 ? [...node.childNodes] : [node];
        for (const n of nodes) {
            this.detach(n);
            const at = ref ? this.childNodes.indexOf(ref) : -1;
            if (at < 0) this.childNodes.push(n); else this.childNodes.splice(at, 0, n);
            n.parentNode = this;
        }
        return node;
    }
    appendChild(node) { return this.insertBefore(node, null); }
    removeChild(node) { assert.equal(node.parentNode, this); this.detach(node); return node; }
    cloneNode(deep) {
        const copy = new Node(this.nodeType, this.tagName, this.attrs);
        copy.data = this.data;
        if (this.content) copy.content = this.content.cloneNode(true);
        if (deep) for (const c of this.childNodes) copy.appendChild(c.cloneNode(true));
        return copy;
    }
    set textContent(value) { this.childNodes.forEach((c) => { c.parentNode = null; }); this.childNodes = []; this.appendChild(text(value)); }
    get textContent() { return this.nodeType === 3 ? this.data : this.childNodes.map((c) => c.textContent).join(''); }
    all() { return this.childNodes.flatMap((c) => (c.nodeType === 1 && c.tagName !== 'TEMPLATE' ? [c, ...c.all()] : c.nodeType === 11 ? c.all() : [])); }
    querySelectorAll(selector) {
        const attr = /^\[([a-z-]+)\]$/.exec(selector)[1];
        return this.all().filter((n) => n.hasAttribute(attr));
    }
    contains(node) { for (let n = node; n; n = n.parentNode) if (n === this) return true; return false; }
    closest(selector) {
        for (let n = this; n && n.nodeType === 1; n = n.parentNode) {
            if (selector === 'a, button, [data-fsc]' && (n.tagName === 'A' || n.tagName === 'BUTTON' || n.hasAttribute('data-fsc'))) return n;
        }
        return null;
    }
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
    height() {
        if (this.nodeType === 3) return Math.ceil(this.data.length / 20) * 20;
        if (this.hidden || this.tagName === 'TEMPLATE') return 0;
        const own = this.hasAttribute('h') ? Number(this.attrs.h) : this.childNodes.reduce((sum, c) => sum + c.height(), 0);
        return Math.max(own, parseFloat(this.style.minHeight) || 0);
    }
    getBoundingClientRect() { const top = Number(this.attrs.top || 0); return { top, height: this.height(), bottom: top + this.height() }; }
}

const text = (value) => { const n = new Node(3); n.data = String(value); return n; };
const h = (tag, attrs = {}, ...children) => { const n = new Node(1, tag, attrs); children.forEach((c) => n.appendChild(typeof c === 'string' ? text(c) : c)); return n; };
const template = (variant, ...children) => { const t = h('template', { 'data-fsi-variant': variant }); children.forEach((c) => t.content.appendChild(c)); return t; };

/** A page with the given slots, a fetch that answers `answer` (or fails), a tracker with or without consent, and a manual clock. */
function page({ slots, answer = { d: {} }, fail = false, consent = false, fsaLoaded = true, referrer = '', hash = '', delay = 0, viewport = 800, lang = '', client = '1' }) {
    let clock = 0;
    const tracked = [];
    const requests = [];
    const timers = [];
    const observers = [];
    const root = h('body', {}, ...slots);
    const document = {
        currentScript: { getAttribute: (n) => ({ 'data-endpoint': '/_fsc/i/decide', 'data-deadline': '1500', 'data-client': client })[n] ?? null },
        referrer,
        documentElement: { clientHeight: viewport, lang },
        querySelectorAll: (selector) => root.querySelectorAll(selector).concat(slots.filter((s) => s.tagName === 'TEMPLATE' && s.hasAttribute('data-fsi-slot'))),
        createDocumentFragment: () => new Node(11),
        dispatchEvent: () => true,
    };
    const window = {
        location: { pathname: '/diensten', origin: 'https://studio.nl', hash },
        innerHeight: viewport,
        performance: { now: () => clock },
        setTimeout: (fn, ms) => { timers.push([clock + ms, fn]); return timers.length; },
        clearTimeout: () => {},
        fetch: (url, init) => {
            requests.push({ url, body: JSON.parse(init.body), credentials: init.credentials });
            clock += delay;
            return fail ? Promise.reject(new Error('down')) : Promise.resolve({ ok: true, json: () => Promise.resolve(answer) });
        },
        IntersectionObserver: class {
            constructor(fn) { this.fn = fn; observers.push(this); }
            observe(el) { this.el = el; }
            disconnect() { this.done = true; }
        },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
        URL,
    };
    if (fsaLoaded) {
        window.fsa = { __loaded: true, hasConsent: () => consent, pid: () => (consent ? 'browser-id-1234567890' : null), track: (name, props) => tracked.push([name, props]) };
    }
    new Function('window', 'document', 'CustomEvent', 'IntersectionObserver', 'URL', SOURCE)(window, document, window.CustomEvent, window.IntersectionObserver, URL);
    const settle = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
    const runTimers = async () => { while (timers.length) { const [at, fn] = timers.shift(); clock = Math.max(clock, at); fn(); await settle(); } };
    const seeAll = () => observers.forEach((o) => !o.done && o.fn([{ isIntersecting: true, intersectionRatio: 1, target: o.el }]));
    return { window, document, tracked, requests, settle, runTimers, seeAll, root };
}

function hero(top = 0, ...extra) {
    return h('div', { 'data-fsi-slot': 'home-hero', top }, h('h1', { 'data-fsi-field': 'title' }, 'Websites die werken'), h('a', { href: '/contact', 'data-fsi-href': 'cta_url' }, 'Contact'), ...extra);
}

test('sends only the current and same-site previous page without consent; the browser id only with consent', async () => {
    const anon = page({ slots: [hero()], referrer: 'https://elders.example/x' });
    await anon.settle();
    assert.deepEqual(anon.requests[0].body, { s: ['home-hero'], p: '/diensten', c: 0 });
    assert.equal(anon.requests[0].credentials, 'same-origin');

    const own = page({ slots: [hero()], referrer: 'https://studio.nl/voip?x=1#y', consent: true });
    await own.settle();
    assert.deepEqual(own.requests[0].body, { s: ['home-hero'], p: '/diensten', c: 1, r: '/voip', pid: 'browser-id-1234567890' });
});

test('swaps a variant of the same height in view and measures it as personalised', async () => {
    const slot = hero(100);
    const p = page({ slots: [slot], answer: { d: { 'home-hero': { v: 'voip', g: 'personal', f: { title: 'Bellen zonder gedoe', cta_url: '/voip' } } } } });
    await p.settle();
    assert.equal(slot.querySelectorAll('[data-fsi-field]')[0].textContent, 'Bellen zonder gedoe');
    assert.equal(slot.querySelectorAll('[data-fsi-href]')[0].getAttribute('href'), '/voip');
    assert.deepEqual(p.window.fsiSlots.get('home-hero'), { variant: 'voip', group: 'personal', fields: { title: 'Bellen zonder gedoe', cta_url: '/voip' } });
    assert.deepEqual(p.tracked, []);
    p.seeAll();
    p.seeAll();
    assert.deepEqual(p.tracked, [['fsi_slot_view', { fsi_slot: 'home-hero', fsi_variant: 'voip', fsi_group: 'personal' }]]);
    // A click on the link inside is measured once.
    const link = slot.querySelectorAll('[data-fsi-href]')[0];
    slot.listeners.click[0]({ target: link });
    slot.listeners.click[0]({ target: link });
    assert.deepEqual(p.tracked[1], ['fsi_slot_click', { fsi_slot: 'home-hero', fsi_variant: 'voip', fsi_group: 'personal' }]);
    assert.equal(p.tracked.length, 2);
});

test('never moves the page: a taller variant in view stays default (fallback), below the viewport it may swap', async () => {
    const longTitle = { d: { 'home-hero': { v: 'voip', g: 'personal', f: { title: 'Een veel langere titel die over drie regels loopt en meer' } } } };

    const inView = hero(100);
    const before = inView.getBoundingClientRect().height;
    const a = page({ slots: [inView], answer: longTitle });
    await a.settle();
    assert.equal(inView.querySelectorAll('[data-fsi-field]')[0].textContent, 'Websites die werken');
    assert.equal(inView.getBoundingClientRect().height, before);
    assert.equal(a.window.fsiSlots.get('home-hero').group, 'fallback');

    const below = hero(1200);
    const b = page({ slots: [below], answer: longTitle });
    await b.settle();
    assert.match(below.querySelectorAll('[data-fsi-field]')[0].textContent, /langere titel/);
    assert.equal(b.window.fsiSlots.get('home-hero').group, 'personal');

    // Above the viewport (scrolled past) a taller variant would push the content in view: refused as well.
    const above = hero(-900);
    const c = page({ slots: [above], answer: longTitle });
    await c.settle();
    assert.equal(c.window.fsiSlots.get('home-hero').group, 'fallback');
});

test('keeps the height of a shorter variant reserved and the space of a hidden one while in view', async () => {
    const slot = h('div', { 'data-fsi-slot': 'cta', top: 50 }, h('p', { 'data-fsi-field': 'text' }, 'Word klant en bespaar direct op je kosten vandaag'));
    const before = slot.getBoundingClientRect().height;
    const p = page({ slots: [slot], answer: { d: { cta: { v: 'kort', g: 'personal', f: { text: 'Kort' } } } } });
    await p.settle();
    assert.equal(slot.querySelectorAll('[data-fsi-field]')[0].textContent, 'Kort');
    assert.equal(slot.style.minHeight, `${before}px`);
    assert.equal(slot.getBoundingClientRect().height, before);

    const cta = h('div', { 'data-fsi-slot': 'cta', top: 50, h: 60 });
    const q = page({ slots: [cta], answer: { d: { cta: { v: 'klant', g: 'customer', h: 1 } } } });
    await q.settle();
    assert.equal(cta.style.visibility, 'hidden');
    assert.equal(cta.hidden, false);
    assert.equal(q.window.fsiSlots.get('cta').group, 'customer');
});

test('the control group keeps the default and drops the same visits as the personalised group', async () => {
    const same = hero(100);
    const a = page({ slots: [same], answer: { d: { 'home-hero': { v: 'voip', g: 'control', f: { title: 'Bellen zonder gedoe' } } } } });
    await a.settle();
    assert.equal(same.querySelectorAll('[data-fsi-field]')[0].textContent, 'Websites die werken');
    assert.deepEqual(a.window.fsiSlots.get('home-hero'), { variant: 'voip', group: 'control', fields: null });
    a.seeAll();
    assert.deepEqual(a.tracked, [['fsi_slot_view', { fsi_slot: 'home-hero', fsi_variant: 'voip', fsi_group: 'control' }]]);

    const tall = hero(100);
    const b = page({ slots: [tall], answer: { d: { 'home-hero': { v: 'voip', g: 'control', f: { title: 'Een veel langere titel die over drie regels loopt en meer' } } } } });
    await b.settle();
    assert.equal(b.window.fsiSlots.get('home-hero').group, 'fallback');
    assert.equal(tall.querySelectorAll('[data-fsi-field]')[0].textContent, 'Websites die werken');
});

test('uses the template of the variant when the site has one, and leaves slots without a decision alone', async () => {
    const slot = hero(100, template('voip', h('h1', {}, 'VoIP-hero'), h('a', { href: '/voip' }, 'Bekijk')));
    const other = h('div', { 'data-fsi-slot': 'cases', top: 900 }, 'Cases');
    const p = page({ slots: [slot, other], answer: { d: { 'home-hero': { v: 'voip', g: 'personal' } } } });
    await p.settle();
    assert.equal(slot.textContent, 'VoIP-heroBekijk');
    assert.equal(slot.childNodes.filter((n) => n.tagName === 'TEMPLATE').length, 1);
    assert.equal(p.window.fsiSlots.get('cases'), null);
    p.seeAll();
    assert.deepEqual(p.tracked.map(([, props]) => props.fsi_slot), ['home-hero']);
});

test('applies nothing after the deadline or on an error, and nothing at all in heatmap overlay mode', async () => {
    const late = hero(100);
    const a = page({ slots: [late], delay: 2000, answer: { d: { 'home-hero': { v: 'voip', g: 'personal', f: { title: 'Bellen' } } } } });
    await a.settle();
    assert.equal(late.querySelectorAll('[data-fsi-field]')[0].textContent, 'Websites die werken');
    assert.equal(a.window.fsiSlots.get('home-hero').group, 'fallback');

    const down = hero(100);
    const b = page({ slots: [down], fail: true });
    await b.settle();
    b.seeAll();
    assert.equal(b.window.fsiSlots.get('home-hero'), null);
    assert.deepEqual(b.tracked, []);

    const overlay = page({ slots: [hero(100)], hash: '#fsa_overlay=abc' });
    await overlay.settle();
    assert.equal(overlay.requests.length, 0);
    assert.equal(overlay.window.fsiSlots, undefined);
});

test('a data slot hands its fields to the page only when the variant applies and reports its view once', async () => {
    const data = h('template', { 'data-fsi-slot': 'chat-opening', 'data-fsi-data': '' });
    const p = page({ slots: [data], answer: { d: { 'chat-opening': { v: 'voip', g: 'personal', f: { text: 'Waar kan ik je mee helpen bij bellen?' } } } } });
    let seen = null;
    p.window.fsiSlots.ready((api) => { seen = api.get('chat-opening'); });
    await p.settle();
    assert.deepEqual(seen, { variant: 'voip', group: 'personal', fields: { text: 'Waar kan ik je mee helpen bij bellen?' } });
    p.window.fsiSlots.view('chat-opening');
    p.window.fsiSlots.view('chat-opening');
    assert.equal(p.tracked.length, 1);

    const control = page({ slots: [h('template', { 'data-fsi-slot': 'chat-opening', 'data-fsi-data': '' })], answer: { d: { 'chat-opening': { v: 'voip', g: 'control', f: { text: 'x' } } } } });
    await control.settle();
    assert.deepEqual(control.window.fsiSlots.get('chat-opening'), { variant: 'voip', group: 'control', fields: null });
});

test('waits briefly for the tracker and queues measurements until it is there', async () => {
    const slot = hero(100);
    const p = page({ slots: [slot], fsaLoaded: false, answer: { d: { 'home-hero': { v: 'default', g: 'none' } } } });
    assert.equal(p.requests.length, 0);
    await p.runTimers();
    assert.equal(p.requests.length, 1);
    assert.equal(p.requests[0].body.c, 0);
    p.seeAll();
    assert.deepEqual(Array.from(p.window.fsa.q[0]), ['track', 'fsi_slot_view', { fsi_slot: 'home-hero', fsi_variant: 'default', fsi_group: 'none' }]);
});

test('a field variant changes the existing nodes in place, so listeners stay; the page language goes along', async () => {
    const slot = hero(100);
    const title = slot.querySelectorAll('[data-fsi-field]')[0];
    const link = slot.querySelectorAll('[data-fsi-href]')[0];
    const p = page({ slots: [slot], lang: 'en', answer: { d: { 'home-hero': { v: 'voip', g: 'personal', f: { title: 'Calls', cta_url: '/en/voip' } } } } });
    await p.settle();
    assert.equal(p.requests[0].body.l, 'en');
    assert.equal(slot.querySelectorAll('[data-fsi-field]')[0], title);
    assert.equal(title.textContent, 'Calls');
    assert.equal(slot.querySelectorAll('[data-fsi-href]')[0], link);
    assert.equal(link.getAttribute('href'), '/en/voip');

    // A refused in-place swap restores the original text and link.
    const tall = hero(100);
    const t2 = tall.querySelectorAll('[data-fsi-field]')[0];
    const q = page({ slots: [tall], answer: { d: { 'home-hero': { v: 'voip', g: 'personal', f: { title: 'Een veel langere titel die over drie regels loopt en meer', cta_url: '/voip' } } } } });
    await q.settle();
    assert.equal(t2.textContent, 'Websites die werken');
    assert.equal(tall.querySelectorAll('[data-fsi-href]')[0].getAttribute('href'), '/contact');
});

test('a chat data slot counts its view in every group and the first chat message once', async () => {
    for (const group of ['control', 'personal']) {
        const p = page({ slots: [h('template', { 'data-fsi-slot': 'chat-opening', 'data-fsi-data': '' })], answer: { d: { 'chat-opening': { v: 'voip', g: group, f: { text: 'x' } } } } });
        await p.settle();
        p.window.fsiSlots.chatMessage('chat-opening'); // before the view: not counted
        p.window.fsiSlots.view('chat-opening');
        p.window.fsiSlots.chatMessage('chat-opening');
        p.window.fsiSlots.chatMessage('chat-opening');
        assert.deepEqual(p.tracked.map(([name, props]) => [name, props.fsi_group]), [['fsi_slot_view', group], ['fsi_slot_chat_message', group]]);
    }
});

test('a slot the server already decided is never asked for or swapped; it is only measured with the server variant and group', async () => {
    const decided = h('div', { 'data-fsi-slot': 'home-hero', top: 100, 'data-fsi-variant': 'voip', 'data-fsi-group': 'personal', 'data-fsi-fields': '{"title":"Bellen"}' }, h('h1', { 'data-fsi-field': 'title' }, 'Bellen'));
    const control = h('div', { 'data-fsi-slot': 'cta', top: 300, 'data-fsi-variant': 'klant', 'data-fsi-group': 'control' }, h('a', { href: '/contact' }, 'Contact'));
    const answer = { d: { 'home-hero': { v: 'x', g: 'personal', f: { title: 'Nooit' } } } };

    // Server rendering mode (data-client 0): no request at all, content untouched.
    const p = page({ slots: [decided, control], client: '0', answer });
    await p.settle();
    assert.equal(p.requests.length, 0);
    assert.equal(decided.querySelectorAll('[data-fsi-field]')[0].textContent, 'Bellen');
    assert.deepEqual(p.window.fsiSlots.get('home-hero'), { variant: 'voip', group: 'personal', fields: { title: 'Bellen' } });
    assert.deepEqual(p.window.fsiSlots.get('cta'), { variant: 'klant', group: 'control', fields: null });
    p.seeAll();
    assert.deepEqual(p.tracked.map(([n, props]) => [n, props.fsi_slot, props.fsi_variant, props.fsi_group]), [['fsi_slot_view', 'home-hero', 'voip', 'personal'], ['fsi_slot_view', 'cta', 'klant', 'control']]);

    // Browser path switched on: only the slots the server did not decide are asked.
    const open = h('div', { 'data-fsi-slot': 'footer-cta', top: 500 }, h('a', { href: '/x', 'data-fsi-href': 'cta_url' }, 'x'));
    const q = page({ slots: [decided, open], client: '1', answer: { d: { 'footer-cta': { v: 'voip', g: 'personal', f: { cta_url: '/voip' } } } } });
    await q.settle();
    assert.equal(q.requests.length, 1);
    assert.deepEqual(q.requests[0].body.s, ['footer-cta']);
    assert.equal(q.window.fsiSlots.get('home-hero').group, 'personal');
    assert.equal(q.window.fsiSlots.get('footer-cta').group, 'personal');
    assert.equal(decided.querySelectorAll('[data-fsi-field]')[0].textContent, 'Bellen');
});

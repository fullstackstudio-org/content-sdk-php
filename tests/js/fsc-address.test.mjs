// Tests of resources/assets/fsc-address.js with a minimal fake browser (no dependencies). Run: `bun test packages/content-sdk-php/tests/js`.
import { describe, expect, it } from 'bun:test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(join(import.meta.dir, '../../resources/assets/fsc-address.js'), 'utf8');

function el(tag, attrs = {}) {
    const listeners = {};
    const node = {
        tagName: tag,
        attrs: { ...attrs },
        children: [],
        hidden: false,
        value: '',
        required: false,
        textContent: '',
        id: attrs.id || '',
        _classes: new Set((attrs.class || '').split(' ').filter(Boolean)),
        classList: {
            remove: (c) => node._classes.delete(c),
            toggle: (c, on) => (on ? node._classes.add(c) : node._classes.delete(c)),
            has: (c) => node._classes.has(c),
        },
        getAttribute: (n) => (n in node.attrs ? node.attrs[n] : null),
        setAttribute: (n, v) => { node.attrs[n] = String(v); },
        removeAttribute: (n) => { delete node.attrs[n]; },
        appendChild: (c) => { node.children.push(c); return c; },
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        dispatchEvent: (event) => { (listeners[event.type] || []).forEach((fn) => fn(event)); },
        focus: () => { node.focused = true; },
        scrollIntoView: () => {},
        querySelector: (selector) => node.querySelectorAll(selector)[0] || null,
        querySelectorAll: (selector) => {
            const match = /^\[([a-z-]+)(?:=([a-z]+))?\]$/.exec(selector);
            const out = [];
            const walk = (n) => n.children.forEach((c) => {
                if (match && (match[2] ? c.attrs[match[1]] === match[2] : match[1] in c.attrs)) { out.push(c); }
                walk(c);
            });
            walk(node);
            return out;
        },
        fire: (type, extra = {}) => node.dispatchEvent({ type, preventDefault() { this.prevented = true; }, ...extra }),
    };
    Object.defineProperty(node, 'innerHTML', { get: () => '', set: () => { node.children = []; } });
    return node;
}

function build({ filled = false, required = false } = {}) {
    const root = el('div', { id: 'a1', 'data-fsc-address': '', 'data-fsc-filled': filled ? '1' : '0', class: 'fsc-address fsc-address--nojs',
        'data-fsc-config': JSON.stringify({ suggestUrl: '/s', lookupUrl: '/l', required, labels: { noResults: 'Geen', results: '{count} suggesties', selected: 'Gekozen: {address}' } }) });
    const parts = {
        q: el('input', { 'data-fsc-q': '' }), list: el('ul', { 'data-fsc-list': '' }), status: el('p', { 'data-fsc-status': '' }),
        search: el('div', { 'data-fsc-search': '' }), summary: el('div', { 'data-fsc-summary': '' }), summaryText: el('span', { 'data-fsc-summary-text': '' }),
        manual: el('div', { 'data-fsc-manual-fields': '' }), manualBtn: el('button', { 'data-fsc-manual': '' }), changeBtn: el('button', { 'data-fsc-change': '' }),
    };
    const fields = {};
    for (const key of ['street', 'houseNumber', 'addition', 'postcode', 'city', 'country']) {
        fields[key] = el('input', { 'data-fsc-key': key });
        parts.manual.appendChild(fields[key]);
    }
    if (filled) { Object.assign(fields.street, { value: 'Roggekamp' }); fields.houseNumber.value = '68'; fields.postcode.value = '2592VB'; fields.city.value = 'Den Haag'; }
    [parts.q, parts.list, parts.status, parts.search, parts.summary, parts.manual, parts.manualBtn, parts.changeBtn].forEach((p) => root.appendChild(p));
    parts.summary.appendChild(parts.summaryText);

    return { root, parts, fields };
}

function run(dom, responses) {
    const timers = [];
    const requests = [];
    const document = { readyState: 'complete', querySelectorAll: () => [dom.root], addEventListener: () => {}, createElement: (tag) => el(tag) };
    const window = {
        clearTimeout: () => { timers.length = 0; },
        setTimeout: (fn) => { timers.push(fn); return timers.length; },
    };
    const fetch = (url) => { requests.push(url); const body = responses(url); return Promise.resolve({ ok: body !== null, json: () => Promise.resolve(body) }); };
    new Function('document', 'window', 'fetch', 'AbortController', 'Event', source)(document, window, fetch, undefined, class { constructor(type) { this.type = type; } });

    return { timers, requests, flush: () => new Promise((resolve) => setTimeout(resolve, 0)) };
}

const ADDRESS = { label: "Roggekamp 68, 2592VB 's-Gravenhage", street: 'Roggekamp', houseNumber: 68, houseLetter: 'A', addition: '2', postcode: '2592VB', city: "'s-Gravenhage", country: 'NL', lat: 52.09, lon: 4.34 };

describe('fsc-address.js', () => {
    it('switches to the enhanced mode and starts in search mode', () => {
        const dom = build({ required: true });
        run(dom, () => null);

        expect(dom.root.classList.has('fsc-address--nojs')).toBe(false);
        expect(dom.parts.search.hidden).toBe(false);
        expect(dom.parts.manual.hidden).toBe(true);
        expect(dom.parts.q.required).toBe(true);
        expect(dom.fields.street.required).toBe(false);
    });

    it('debounces, ignores short input, lists suggestions and picks one with the keyboard', async () => {
        const dom = build();
        const ctx = run(dom, () => ({ addresses: [ADDRESS, { ...ADDRESS, label: 'x' }] }));

        dom.parts.q.value = 'ro';
        dom.parts.q.fire('input');
        expect(ctx.timers.length).toBe(0);

        dom.parts.q.value = 'roggekamp 68';
        dom.parts.q.fire('input');
        expect(ctx.requests.length).toBe(0);
        ctx.timers[0]();
        await ctx.flush();

        expect(ctx.requests).toEqual(['/s?limit=8&q=roggekamp%2068']);
        expect(dom.parts.list.hidden).toBe(false);
        expect(dom.parts.list.children.length).toBe(2);
        expect(dom.parts.status.textContent).toBe('2 suggesties');

        dom.parts.q.fire('keydown', { key: 'ArrowDown' });
        dom.parts.q.fire('keydown', { key: 'ArrowDown' });
        dom.parts.q.fire('keydown', { key: 'ArrowUp' });
        const enter = { key: 'Enter' };
        dom.parts.q.fire('keydown', enter);

        expect(dom.fields.street.value).toBe('Roggekamp');
        expect(dom.fields.houseNumber.value).toBe('68');
        expect(dom.fields.addition.value).toBe('A-2');
        expect(dom.fields.postcode.value).toBe('2592VB');
        expect(dom.fields.country.value).toBe('NL');
        expect(dom.parts.summary.hidden).toBe(false);
        expect(dom.parts.search.hidden).toBe(true);
        expect(dom.parts.summaryText.textContent).toBe("Roggekamp 68 A-2, 2592VB 's-Gravenhage");
    });

    it('Escape closes the list, Enter without a highlighted option does not submit-block', async () => {
        const dom = build();
        const ctx = run(dom, () => ({ addresses: [ADDRESS] }));
        dom.parts.q.value = 'roggekamp';
        dom.parts.q.fire('input');
        ctx.timers[0]();
        await ctx.flush();

        const enter = { key: 'Enter' };
        dom.parts.q.fire('keydown', enter);
        expect(enter.prevented).toBeUndefined();

        dom.parts.q.fire('keydown', { key: 'Escape' });
        expect(dom.parts.list.hidden).toBe(true);
    });

    it('shows "no results" for an empty answer and for a failing request', async () => {
        const dom = build();
        const ctx = run(dom, () => ({ addresses: [] }));
        dom.parts.q.value = 'zzzzzz';
        dom.parts.q.fire('input');
        ctx.timers[0]();
        await ctx.flush();
        expect(dom.parts.list.children[0].textContent).toBe('Geen');

        const down = build();
        const ctx2 = run(down, () => null);
        down.parts.q.value = 'roggekamp';
        down.parts.q.fire('input');
        ctx2.timers[0]();
        await ctx2.flush();
        // A failing request reads as "no results": the message points to the manual fields.
        expect(down.parts.list.children[0].textContent).toBe('Geen');
    });

    it('opens the manual fields with a required marker and fills street and city from a postcode lookup', async () => {
        const dom = build({ required: true });
        const ctx = run(dom, (url) => (url.startsWith('/l') ? { address: ADDRESS } : null));

        dom.parts.manualBtn.fire('click');
        expect(dom.parts.manual.hidden).toBe(false);
        expect(dom.fields.street.required).toBe(true);
        expect(dom.parts.q.required).toBe(false);

        dom.fields.postcode.value = '2592 vb';
        dom.fields.houseNumber.value = '68';
        dom.fields.postcode.fire('change');
        await ctx.flush();

        expect(ctx.requests).toEqual(['/l?postcode=2592%20vb&number=68']);
        expect(dom.fields.street.value).toBe('Roggekamp');
        expect(dom.fields.city.value).toBe("'s-Gravenhage");

        dom.fields.street.value = 'Mijn eigen straat';
        dom.fields.postcode.fire('change');
        expect(ctx.requests.length).toBe(1);
    });

    it('shows a prefilled address as the summary and "Change" returns to the search', () => {
        const dom = build({ filled: true, required: true });
        run(dom, () => null);

        expect(dom.parts.summary.hidden).toBe(false);
        expect(dom.parts.summaryText.textContent).toBe('Roggekamp 68, 2592VB Den Haag');
        dom.parts.changeBtn.fire('click');
        expect(dom.parts.search.hidden).toBe(false);
        expect(dom.parts.q.required).toBe(false);
        expect(dom.parts.q.focused).toBe(true);
    });
});

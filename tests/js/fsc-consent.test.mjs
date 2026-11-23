// Tests of resources/assets/fsc-consent.js with a minimal fake browser (no dependencies). Run: `bun test packages/content-sdk-php/tests/js`.
import { describe, expect, it } from 'bun:test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(join(import.meta.dir, '../../resources/assets/fsc-consent.js'), 'utf8');

function el(attrs = {}, extra = {}) {
    const listeners = {};
    const node = {
        attrs: { ...attrs },
        children: [],
        hidden: 'hidden' in attrs,
        checked: false,
        value: attrs.value || '',
        focused: false,
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        fire: (type, event = {}) => (listeners[type] || []).forEach((fn) => fn(Object.assign(event, { type, preventDefault() { this.prevented = true; } }))),
        focus: () => { node.focused = true; },
        querySelector: (selector) => node.querySelectorAll(selector)[0] || null,
        querySelectorAll: (selector) => {
            const name = /^\[([a-z-]+)\]$/.exec(selector)[1];
            const out = [];
            const walk = (n) => n.children.forEach((c) => { if (name in c.attrs) out.push(c); walk(c); });
            walk(node);
            return out;
        },
        ...extra,
    };
    node.dataset = new Proxy({}, {
        get: (_, prop) => {
            const attr = 'data-' + String(prop).replace(/[A-Z]/g, (m) => '-' + m.toLowerCase());
            return attr in node.attrs ? node.attrs[attr] : undefined;
        },
    });
    return node;
}

function setup({ stored = null, preview = false, open = true, version = '3', failFetch = false } = {}) {
    const box = el({ id: 'fsc-consent', 'data-version': version, 'data-key': 'fsc-consent', 'data-event': 'fsc:consent', 'data-endpoint': '/_fsc/c', hidden: '' });
    const choices = el({ 'data-fsc-choices': '', hidden: '' });
    const analytics = el({ 'data-fsc-category': '', value: 'analytics' });
    const marketing = el({ 'data-fsc-category': '', value: 'marketing' });
    choices.children.push(analytics, marketing);
    const customize = el({ 'data-fsc-customize': '' });
    const save = el({ 'data-fsc-save': '', hidden: '' });
    const none = el({ 'data-fsc-choice': 'none' });
    const all = el({ 'data-fsc-choice': 'all' });
    box.children.push(choices, customize, none, all, save);
    const link = el({ 'data-fsc-consent-open': '' });

    const storage = {};
    if (stored) storage['fsc-consent'] = JSON.stringify(stored);
    const events = [];
    const fetches = [];
    const html = el({ ...(preview ? { 'data-fsc-preview': '' } : {}) });
    html.hasAttribute = (name) => name in html.attrs;
    const document = {
        documentElement: html,
        getElementById: (id) => (id === 'fsc-consent' && open ? box : null),
        querySelectorAll: (selector) => (selector === '[data-fsc-consent-open]' ? [link] : []),
    };
    const window = {
        localStorage: { getItem: (k) => (k in storage ? storage[k] : null), setItem: (k, v) => { storage[k] = v; } },
        dispatchEvent: (e) => { events.push(e); },
        fetch: (url, init) => { fetches.push({ url, init }); return failFetch ? Promise.reject(new Error('down')) : Promise.resolve({}); },
    };
    class CustomEvent { constructor(type, init) { this.type = type; this.detail = init.detail; } }
    new Function('window', 'document', 'CustomEvent', source)(window, document, CustomEvent);

    return { box, choices, analytics, marketing, customize, save, none, all, link, storage, events, fetches };
}

describe('fsc-consent.js', () => {
    it('shows the banner on a first visit and sets nothing before a choice', () => {
        const t = setup();
        expect(t.box.hidden).toBe(false);
        expect(t.events).toEqual([]);
        expect(t.fetches).toEqual([]);
        expect(t.storage['fsc-consent']).toBeUndefined();
    });

    it('stays hidden when the stored choice has the current version', () => {
        const t = setup({ stored: { choice: 'none', version: '3' } });
        expect(t.box.hidden).toBe(true);
    });

    it('asks again when the Hub version changed', () => {
        const t = setup({ stored: { choice: 'all', version: '2' } });
        expect(t.box.hidden).toBe(false);
    });

    it('survives corrupt storage', () => {
        const t = setup();
        expect(t.box.hidden).toBe(false);
        const broken = setup({ stored: null });
        broken.storage['fsc-consent'] = '{nope';
        expect(broken.box.hidden).toBe(false);
    });

    it('accept all: stores, dispatches, hides and posts the proof', () => {
        const t = setup();
        t.all.fire('click');
        expect(JSON.parse(t.storage['fsc-consent'])).toEqual({ choice: 'all', version: '3' });
        expect(t.events).toHaveLength(1);
        expect(t.events[0].type).toBe('fsc:consent');
        expect(t.events[0].detail).toEqual({ choice: 'all', version: '3' });
        expect(t.box.hidden).toBe(true);
        expect(t.fetches).toHaveLength(1);
        expect(t.fetches[0].url).toBe('/_fsc/c');
        expect(t.fetches[0].init.method).toBe('POST');
        expect(JSON.parse(t.fetches[0].init.body)).toEqual({ choice: 'all', version: '3' });
    });

    it('necessary only: same weight, own choice', () => {
        const t = setup();
        t.none.fire('click');
        expect(JSON.parse(t.storage['fsc-consent'])).toEqual({ choice: 'none', version: '3' });
        expect(t.events[0].detail.choice).toBe('none');
        expect(t.box.hidden).toBe(true);
    });

    it('custom: opens the panel, then stores the checked categories', () => {
        const t = setup();
        expect(t.choices.hidden).toBe(true);
        t.customize.fire('click');
        expect(t.choices.hidden).toBe(false);
        expect(t.save.hidden).toBe(false);
        expect(t.customize.hidden).toBe(true);
        expect(t.analytics.focused).toBe(true);
        t.marketing.checked = true;
        t.save.fire('click');
        expect(JSON.parse(t.storage['fsc-consent'])).toEqual({ choice: 'custom', categories: ['marketing'], version: '3' });
        expect(t.events[0].detail).toEqual({ choice: 'custom', categories: ['marketing'], version: '3' });
        expect(JSON.parse(t.fetches[0].init.body).categories).toEqual(['marketing']);
        expect(t.box.hidden).toBe(true);
    });

    it('custom with nothing checked stores an empty list', () => {
        const t = setup();
        t.customize.fire('click');
        t.save.fire('click');
        expect(JSON.parse(t.storage['fsc-consent'])).toEqual({ choice: 'custom', categories: [], version: '3' });
    });

    it('reopen link shows the banner again, prechecks the earlier choice, focuses the box and returns focus after', () => {
        const t = setup({ stored: { choice: 'custom', categories: ['analytics'], version: '3' } });
        expect(t.box.hidden).toBe(true);
        const event = {};
        t.link.fire('click', event);
        expect(event.prevented).toBe(true);
        expect(t.box.hidden).toBe(false);
        expect(t.box.focused).toBe(true);
        t.customize.fire('click');
        expect(t.analytics.checked).toBe(true);
        expect(t.marketing.checked).toBe(false);
        t.none.fire('click');
        expect(t.link.focused).toBe(true);
    });

    it('Escape closes a reopened banner but not a first-time one', () => {
        const first = setup();
        first.box.fire('keydown', { key: 'Escape' });
        expect(first.box.hidden).toBe(false);

        const reopened = setup({ stored: { choice: 'none', version: '3' } });
        reopened.link.fire('click', {});
        reopened.box.fire('keydown', { key: 'Escape' });
        expect(reopened.box.hidden).toBe(true);
        expect(reopened.events).toEqual([]);
    });

    it('does nothing in the portal preview', () => {
        const t = setup({ preview: true });
        expect(t.box.hidden).toBe(true);
        t.all.fire('click');
        expect(t.events).toEqual([]);
        expect(t.fetches).toEqual([]);
    });

    it('does nothing without a banner element', () => {
        expect(() => setup({ open: false })).not.toThrow();
    });

    it('a failing proof request does not undo the choice', async () => {
        const t = setup({ failFetch: true });
        t.all.fire('click');
        await Promise.resolve();
        expect(JSON.parse(t.storage['fsc-consent']).choice).toBe('all');
        expect(t.box.hidden).toBe(true);
    });
});

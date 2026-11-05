// Tests for resources/assets/fsc-devmode.js (the dev panel) with a minimal fake browser. Run with: bun test packages/content-sdk-php/tests/js
import { describe, expect, it } from 'bun:test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(join(import.meta.dir, '../../resources/assets/fsc-devmode.js'), 'utf8');

function node(tag) {
    const listeners = {};
    const self = {
        tagName: tag, children: [], attrs: {}, className: '', hidden: false, _text: '', type: '', disabled: false,
        get textContent() { return self._text + self.children.map((c) => c.textContent).join(''); },
        set textContent(value) { self._text = String(value); self.children = []; },
        setAttribute(name, value) { self.attrs[name] = String(value); },
        getAttribute(name) { return name in self.attrs ? self.attrs[name] : null; },
        removeAttribute(name) { delete self.attrs[name]; },
        append(...nodes) { nodes.forEach((n) => self.children.push(n)); },
        replaceChildren(...nodes) { self.children = [...nodes]; self._text = ''; },
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        click() { (listeners.click || []).forEach((fn) => fn({})); },
        focus() { self.focused = true; },
        get firstChild() { return self.children[0] ?? null; },
        get lastChild() { return self.children[self.children.length - 1] ?? null; },
        all(match) { return [self, ...self.children.flatMap((c) => c.all(match))].filter(match); },
    };
    return self;
}

/** Runs the panel script in a fake browser. */
function run({ attrs = {}, flags = '', lang = 'nl', me = null, top = true } = {}) {
    const store = flags ? { 'fsc-dev-flags': flags } : {};
    const session = {};
    const log = { reloads: 0, cookies: [], copied: [], fetches: [] };
    const doc = {
        documentElement: { getAttribute: (name) => (name === 'lang' ? lang : null) },
        head: node('head'), body: node('body'), cookie: '',
        createElement: node, createElementNS: (ns, tag) => node(tag),
        querySelectorAll: () => [], addEventListener() {},
        currentScript: { getAttribute: (name) => attrs[`data-${name.slice(5)}`] ?? attrs[name] ?? null, nonce: '' },
    };
    Object.defineProperty(doc, 'cookie', { get: () => '', set: (value) => log.cookies.push(value) });
    const win = { top: top ? null : {}, self: null, document: doc };
    win.self = top ? win.top ?? win : win;
    if (top) { win.top = win.self; }
    const location = { host: 'www.example.nl', pathname: '/over-ons', protocol: 'https:', reload: () => { log.reloads++; } };
    const storage = (data) => ({ getItem: (k) => data[k] ?? null, setItem: (k, v) => { data[k] = String(v); }, removeItem: (k) => { delete data[k]; } });
    const fetch = (url, init) => {
        log.fetches.push({ url, init });
        return Promise.resolve({ ok: true, json: () => Promise.resolve(me) });
    };
    new Function('window', 'document', 'location', 'localStorage', 'sessionStorage', 'navigator', 'fetch', 'requestAnimationFrame', 'cancelAnimationFrame', 'performance', source)(
        win, doc, location, storage(store), storage(session), { clipboard: { writeText: (v) => { log.copied.push(v); return Promise.resolve(); } } },
        fetch, () => 1, () => {}, { now: () => 0 },
    );

    return { win, doc, store, session, log, root: doc.body.children[0] };
}

const toggles = (root) => root.all((n) => n.attrs.role === 'switch');
const labelOf = (toggle) => toggle.children[0].children[0].textContent;
const data = (extra = {}) => ({ 'data-v': '0.17.0', 'data-cookie': 'fsc_devmode', 'data-aliases': '["devmenu"]', ...extra });

describe('the panel', () => {
    it('builds a collapsed pill and a closed dialog, and defines devmode() and its aliases only now', () => {
        const { win, root } = run({ attrs: data() });

        expect(root.className).toBe('fscdev');
        const pill = root.children[0];
        expect(pill._text).toBe('Dev');
        expect(pill.attrs['aria-expanded']).toBe('false');
        expect(root.children[1].attrs.role).toBe('dialog');
        expect(root.children[1].hidden).toBe(true);
        expect(typeof win.devmode).toBe('function');
        expect(win.devmenu).toBe(win.devmode);
    });

    it('opens from the pill with the three sections and moves focus into the panel', () => {
        const { root } = run({ attrs: data() });
        root.children[0].click();

        const headings = root.all((n) => n.tagName === 'h2').map((n) => n.textContent);
        expect(headings).toEqual(['Bezoeker', 'Weergave', 'Deze pagina']);
        expect(root.attrs['data-open']).toBe('');
        expect(root.children[1].hidden).toBe(false);
    });

    it('shows English strings under an English html lang', () => {
        const { root } = run({ attrs: data(), lang: 'en' });
        root.children[0].click();

        expect(root.all((n) => n.tagName === 'h2').map((n) => n.textContent)).toEqual(['Visitor', 'Display', 'This page']);
    });

    it('lists the four display flags as switches; clicking one stores it and reloads', () => {
        const { root, store, log } = run({ attrs: data() });
        root.children[0].click();

        expect(toggles(root).map(labelOf)).toEqual(['Reduced motion', 'Save-Data', 'Geen WebGL', 'Zwak toestel']);
        toggles(root)[0].click();
        expect(store['fsc-dev-flags']).toBe('reduce');
        expect(log.reloads).toBe(1);
    });

    it('shows active flags as checked and in the pill count; switching the last one off removes the key', () => {
        const { root, store } = run({ attrs: data(), flags: 'save' });
        root.children[0].click();

        expect(toggles(root)[1].attrs['aria-checked']).toBe('true');
        expect(root.children[0].lastChild.textContent).toBe('1');
        toggles(root)[1].click();
        expect('fsc-dev-flags' in store).toBe(false);
    });

    it('devmode(false) clears the cookie and the flags and reloads', () => {
        const { win, store, session, log } = run({ attrs: data(), flags: 'reduce' });
        win.devmode(false);

        expect(log.cookies).toEqual(['fsc_devmode=; max-age=0; path=/; SameSite=Lax; Secure']);
        expect('fsc-dev-flags' in store).toBe(false);
        expect(log.reloads).toBe(1);
        expect(session['devmode-open']).toBeUndefined();
    });

    it('takes extensions: a section, extra flags and a mount hook, also when pushed later', () => {
        const mounted = [];
        const { win, root } = run({ attrs: data() });
        win.fscDevExtensions.push({
            id: 'site', title: 'Mijn sectie', flags: [{ id: 'phone', label: 'Mobiele ervaring', hint: 'x' }],
            mount: (ctx) => mounted.push(ctx.lang), render: (host, ctx) => host.append(ctx.row('Hoofdstukken', '4')),
        });
        root.children[0].click();

        expect(mounted).toEqual(['nl']);
        expect(toggles(root).map(labelOf)).toContain('Mobiele ervaring');
        expect(root.all((n) => n.tagName === 'h2').map((n) => n.textContent)).toContain('Mijn sectie');
        expect(root.textContent).toContain('Hoofdstukken');
    });

    it('loads the listed extension scripts', () => {
        const { doc } = run({ attrs: data({ 'data-scripts': '["/js/dev-site.js"]' }) });

        expect(doc.head.all((n) => n.tagName === 'script').map((n) => n.src)).toEqual(['/js/dev-site.js']);
    });

    it('asks for the Intelligence profile only when the site has the route, and links to the portal only when configured', async () => {
        const me = { consent: true, recognized: false, profile: { alias: 'Blauwe Vos', warmth: 'warm', stage: 'oriëntatie', hotTopics: [{ label: 'VoIP' }], visits: 3, ref: 'r/1' } };
        const without = run({ attrs: data(), me });
        without.root.children[0].click();
        expect(without.log.fetches.length).toBe(0);

        const withRoute = run({ attrs: data({ 'data-me': '/_fsc/i/me', 'data-portal': 'https://portal.test/p/{ref}' }), me });
        withRoute.root.children[0].click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(withRoute.log.fetches[0].url).toBe('/_fsc/i/me');
        expect(withRoute.log.fetches[0].init.method).toBe('POST');
        const link = withRoute.root.all((n) => n.tagName === 'a')[0];
        expect(link.href).toBe('https://portal.test/p/r%2F1');

        const noPortal = run({ attrs: data({ 'data-me': '/_fsc/i/me' }), me });
        noPortal.root.children[0].click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(noPortal.root.all((n) => n.tagName === 'a').length).toBe(0);
    });

    it('stays out of a frame', () => {
        const { doc } = run({ attrs: data(), top: false });

        expect(doc.body.children.length).toBe(0);
    });
});

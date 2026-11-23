// Tests for resources/assets/preview.js (the portal preview helper) with a small fake browser (no dependencies).
// Run with: bun test packages/content-sdk-php/tests/js
import { describe, expect, it } from 'bun:test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(join(import.meta.dir, '../../resources/assets/preview.js'), 'utf8');
const PORTAL = 'https://portal.fullstackstudio.nl';
const TOKEN = 'fscpv_' + 'A'.repeat(43);

/* A fake DOM: elements with child nodes, text nodes, rects, focus, contenteditable, a closed shadow root. -------------------------------- */

let current = null; // the fake document of the running test (focus lives there)

function txt(data) {
    return { nodeType: 3, data, parentNode: null, get textContent() { return this.data; } };
}

function detach(node) {
    if (node.parentNode) {
        const list = node.parentNode.childNodes;
        list.splice(list.indexOf(node), 1);
        node.parentNode = null;
    }
}

function el(tag, attrs = {}, kids = []) {
    const self = {
        nodeType: 1, tagName: tag.toUpperCase(), attrs: { ...attrs }, childNodes: [], parentNode: null, listeners: {}, rect: null, scrolled: false,
        style: { setProperty(name, value, priority) { this[name] = value + (priority ? ' !' + priority : ''); } },
        get children() { return self.childNodes.filter((n) => n.nodeType === 1); },
        get nextElementSibling() { if (!self.parentNode) { return null; } const list = self.parentNode.children; return list[list.indexOf(self) + 1] ?? null; },
        get textContent() { return self.childNodes.map((n) => n.textContent).join(''); },
        get innerHTML() { return self.childNodes.map((n) => (n.nodeType === 3 ? n.data.replace(/</g, '&lt;') : n.outerHTML)).join(''); },
        // Only what preview.js writes itself: the first paragraph of an empty rich field.
        set innerHTML(value) {
            self.childNodes.forEach((n) => { n.parentNode = null; });
            self.childNodes = [];
            if (value === '<p><br></p>') { self.appendChild(el('p', {}, [el('br')])); } else if (value !== '') { throw new Error('unexpected innerHTML ' + value); }
        },
        set textContent(value) { self.childNodes.forEach((n) => { n.parentNode = null; }); self.childNodes = []; if (value !== '') { self.appendChild(txt(String(value))); } },
        get className() { return self.attrs.class ?? ''; },
        set className(value) { self.attrs.class = String(value); },
        get contentEditable() { return self.attrs.contenteditable ?? 'inherit'; },
        set contentEditable(value) {
            if (value === 'plaintext-only' && current && current.noPlaintextOnly) { throw new SyntaxError('invalid'); }
            self.attrs.contenteditable = String(value);
        },
        get isContentEditable() { return 'contenteditable' in self.attrs; },
        get outerHTML() {
            const attributes = Object.entries(self.attrs).map(([k, v]) => ` ${k}="${String(v).replace(/"/g, '&quot;')}"`).join('');
            return `<${tag}${attributes}>${self.childNodes.map((n) => (n.nodeType === 3 ? n.data.replace(/</g, '&lt;') : n.outerHTML)).join('')}</${tag}>`;
        },
        getAttribute(name) { return name in self.attrs ? self.attrs[name] : null; },
        setAttribute(name, value) { self.attrs[name] = String(value); },
        removeAttribute(name) { delete self.attrs[name]; },
        appendChild(child) { detach(child); child.parentNode = self; self.childNodes.push(child); return child; },
        insertBefore(child, ref) {
            if (ref === null) { return self.appendChild(child); }
            detach(child);
            child.parentNode = self;
            self.childNodes.splice(self.childNodes.indexOf(ref), 0, child);
            self.moves = (self.moves || 0) + 1;
            return child;
        },
        removeChild(child) { detach(child); return child; },
        replaceChild(next, old) { detach(next); const i = self.childNodes.indexOf(old); next.parentNode = self; self.childNodes[i] = next; old.parentNode = null; return old; },
        contains(node) { for (let n = node; n; n = n.parentNode) { if (n === self) { return true; } } return false; },
        getBoundingClientRect() { const r = self.rect || { left: 0, top: 0, width: 0, height: 0 }; return { ...r, right: r.left + r.width, bottom: r.top + r.height }; },
        focus() { if (current) { current.activeElement = self; } },
        blur() { if (current && current.activeElement === self) { current.activeElement = current.body; } },
        addEventListener(type, fn) { (self.listeners[type] ||= []).push(fn); },
        dispatch(type, extra = {}) {
            const event = { type, target: self, prevented: false, preventDefault() { event.prevented = true; }, stopPropagation() { event.stopped = true; }, ...extra };
            (self.listeners[type] || []).forEach((fn) => fn(event));
            return event;
        },
        attachShadow(options) { self.shadowMode = options.mode; self.shadow = el('#shadow-root'); return self.shadow; },
        scrollIntoView(options) { self.scrolled = options || true; },
        submit() { self.submitted = true; },
        all() { return [self, ...self.children.flatMap((c) => c.all())]; },
        querySelectorAll(selector) { return self.all().slice(1).filter(selectorMatch(selector)); },
        querySelector(selector) { return self.querySelectorAll(selector)[0] ?? null; },
    };
    kids.forEach((c) => self.appendChild(c));
    return self;
}

function selectorMatch(selector) {
    const match = /^([a-z0-9]*)((?:\[[a-z-]+\])*)$/.exec(selector);
    if (!match) { throw new Error('unsupported selector ' + selector); }
    const names = [...match[2].matchAll(/\[([a-z-]+)\]/g)].map((m) => m[1]);
    return (n) => (!match[1] || n.tagName === match[1].toUpperCase()) && names.every((name) => n.getAttribute(name) !== null);
}

function fakeDoc(root, title = '') {
    return {
        title,
        documentElement: root,
        querySelectorAll: (selector) => root.all().filter(selectorMatch(selector)),
        querySelector: (selector) => root.all().find(selectorMatch(selector)) ?? null,
    };
}

/** One block wrapper: a section with a plain heading, a rich paragraph and a link. */
function block(id, i, { text = 'Kop ' + id, top = 100 + i * 200, type = 'hero' } = {}) {
    const heading = el('h1', { 'data-fsc-edit': 'heading', 'data-fsc-edit-kind': 'plain' }, [txt(text)]);
    const rich = el('div', { 'data-fsc-edit': 'html', 'data-fsc-edit-kind': 'rich' }, [txt('Rijke tekst')]);
    const section = el('section', { class: 'fsc-block' }, [heading, rich, el('a', { href: '/x' })]);
    section.rect = { left: 40, top, width: 800, height: 180 };
    return el('div', { 'data-fsc-block': id, 'data-fsc-block-index': String(i), 'data-fsc-block-type': type, 'data-fsc-block-default': '1', 'data-fsc-layouts': 'standard image-right' }, [section]);
}

/** A page with one blocks root holding the given block ids (or [id, options] pairs). */
function page(ids = ['b1', 'b2'], version = 3) {
    const blocks = el('div', { 'data-fsc-preview-blocks': '' }, ids.map((entry, i) => (Array.isArray(entry) ? block(entry[0], i, entry[1]) : block(entry, i))));
    const script = el('script', { 'data-endpoint': '/_fsc/p', 'data-version': String(version) });
    return { blocks, script, root: el('html', {}, [el('head', {}, [script]), el('body', {}, [blocks])]) };
}

/** A clock for the script's timers and performance.now(); promises still settle on the real event loop. */
function makeClock() {
    let t = 1000;
    let seq = 0;
    const timers = new Map();
    return {
        now: () => t,
        setTimeout: (fn, ms = 0) => { seq += 1; timers.set(seq, { at: t + Math.max(0, ms || 0), fn }); return seq; },
        clearTimeout: (id) => { timers.delete(id); },
        tick(ms = 0) {
            const end = t + ms;
            for (;;) {
                let next = null;
                for (const [id, timer] of timers) { if (timer.at <= end && (!next || timer.at < next[1].at)) { next = [id, timer]; } }
                if (!next) { break; }
                timers.delete(next[0]);
                t = next[1].at;
                next[1].fn();
            }
            t = end;
        },
    };
}

class FakeRange {
    setStart(node, offset) { this.startContainer = node; this.startOffset = offset; if (!this.endContainer) { this.setEnd(node, offset); } }
    setEnd(node, offset) { this.endContainer = node; this.endOffset = offset; }
    selectNodeContents(node) { this.startContainer = node; this.startOffset = 0; this.endContainer = node; this.endOffset = node.childNodes.length; }
    collapse(toStart) {
        if (toStart) { this.endContainer = this.startContainer; this.endOffset = this.startOffset; } else { this.startContainer = this.endContainer; this.startOffset = this.endOffset; }
    }
    get collapsed() { return this.startContainer === this.endContainer && this.startOffset === this.endOffset; }
    toString() {
        const out = [];
        let done = false;
        const walk = (node) => {
            for (const child of node.childNodes) {
                if (done) { return; }
                if (child === this.endContainer) { out.push(child.nodeType === 3 ? child.data.slice(0, this.endOffset) : ''); done = true; return; }
                if (child.nodeType === 3) { out.push(child.data); } else { walk(child); }
            }
        };
        walk(this.startContainer);
        return out.join('');
    }
}

const COLORS = {
    'var(--fsc-accent)': 'rgb(29, 78, 216)',
    'var(--fsc-accent-contrast)': 'rgb(255, 255, 255)',
    'var(--fsc-tone-soft-bg)': 'rgba(29, 78, 216, 0.08)',
    'var(--fsc-surface)': 'rgba(0, 0, 0, 0)',
    'var(--fsc-text)': 'rgb(17, 17, 17)',
    'var(--fsc-muted)': 'rgba(17, 17, 17, 0.75)',
    'var(--fsc-border)': 'color(srgb 0.1 0.1 0.1 / 0.25)',
};

function run({ framed = true, opener = false, origin = PORTAL, fetchReply = null, nextPage = null, storage = {}, ids, project = null, noStorage = false, noPlaintextOnly = false } = {}) {
    const { root, blocks } = page(ids);
    const listeners = {};
    const sent = [];
    const fetches = [];
    const clock = makeClock();
    const parent = { postMessage: (message, target) => sent.push({ message, target }) };
    const selection = { ranges: [], get rangeCount() { return this.ranges.length; }, getRangeAt(i) { return this.ranges[i]; }, removeAllRanges() { this.ranges = []; }, addRange(r) { this.ranges.push(r); } };
    const win = {
        scrollX: 0, scrollY: 420, scrolledTo: null, name: '',
        addEventListener: (type, fn) => { (listeners['win:' + type] ||= []).push(fn); },
        scrollTo(x, y) { win.scrolledTo = [x, y]; },
        requestAnimationFrame: (fn) => fn(),
        getSelection: () => selection,
        getComputedStyle: (node) => ({ color: COLORS[node.style.color] ?? 'rgb(17, 17, 17)', backgroundColor: node.tagName === 'BODY' ? 'rgb(250, 250, 250)' : 'rgba(0, 0, 0, 0)' }),
    };
    win.parent = framed ? parent : win;
    win.opener = opener ? parent : null;
    const attrs = { 'data-endpoint': project ? 'https://hub.test/_fsc/fp' : '/_fsc/p', 'data-token': TOKEN, 'data-origin': origin, 'data-version': '3', 'data-sdk': '0.31.0' };
    if (project) { attrs['data-project'] = project; }
    const tag = el('script', attrs);
    const body = root.children[1];
    const inserted = [];
    const doc = {
        ...fakeDoc(root),
        body, readyState: 'complete', currentScript: tag, activeElement: body, noPlaintextOnly,
        hasFocus: () => true,
        addEventListener: (type, fn, capture) => { (listeners['doc:' + type] ||= []).push({ fn, capture }); },
        createElement: (name) => el(name),
        createTextNode: (data) => txt(data),
        createRange: () => new FakeRange(),
        importNode: (node) => node,
        execCommand: (command, ui, value) => { inserted.push([command, value]); return true; },
        caretPoint: null,
        caretPositionFromPoint: (x, y) => doc.caretPoint && doc.caretPoint(x, y),
    };
    current = doc;
    const fetch = (url, init) => {
        fetches.push({ url, init, token: init.body.get('token'), body: Object.fromEntries(init.body) });
        const reply = fetchReply ?? { ok: true, status: 200, headers: { get: () => null }, text: () => Promise.resolve('html') };
        return Promise.resolve(typeof reply === 'function' ? reply() : reply);
    };
    let parsed = nextPage;
    const pasted = new Map(); // markup on the clipboard -> its parsed body (a fake DOMParser for the paste cleaning)
    class DOMParser {
        parseFromString(text) {
            if (pasted.has(text)) { return { body: pasted.get(text) }; }
            const p = typeof parsed === 'function' ? parsed() : parsed;
            return p ? fakeDoc(p.root, 'Nieuwe titel') : fakeDoc(el('html'));
        }
    }
    const session = noStorage
        ? { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('SecurityError'); }, removeItem() { throw new Error('SecurityError'); } }
        : { getItem: (k) => storage[k] ?? null, setItem: (k, v) => { storage[k] = String(v); }, removeItem: (k) => { delete storage[k]; } };
    new Function('window', 'document', 'fetch', 'sessionStorage', 'DOMParser', 'URLSearchParams', 'setTimeout', 'clearTimeout', 'performance', source)(
        win, doc, fetch, session, DOMParser, URLSearchParams, clock.setTimeout, clock.clearTimeout, { now: clock.now },
    );

    const message = (data, from = { origin: PORTAL, source: parent }) => (listeners['win:message'] || []).forEach((fn) => fn({ data, ...from }));
    const fire = (type, target, extra = {}) => {
        const event = { type, target, prevented: false, stopped: false, preventDefault() { event.prevented = true; }, stopImmediatePropagation() { event.stopped = true; }, ...extra };
        (listeners['doc:' + type] || []).forEach(({ fn }) => fn(event));
        return event;
    };
    const flush = async () => { for (let i = 0; i < 4; i++) { await new Promise((resolve) => setTimeout(resolve, 0)); } };
    const setNext = (value) => { parsed = value; };
    const init = (extra = {}) => message({ ns: 'fsc-preview', v: 1, type: 'init', proto: 2, inline: true, labels: { b1: 'Hero', b2: 'Tekst' }, texts: { add: 'Sectie toevoegen' }, ...extra });

    return { win, doc, root, blocks, tag, sent, fetches, message, fire, flush, storage, listeners, parent, clock, selection, inserted, setNext, init, pasted };
}

const types = (sent) => sent.map((s) => s.message.type);
const last = (sent, type) => sent.filter((s) => s.message.type === type).at(-1)?.message;
const strip = ({ ns, v, ...rest }) => rest;
const heading = (blocks, i) => blocks.children[i].children[0].children[0];
const msg = (type, extra = {}) => ({ ns: 'fsc-preview', v: 1, type, ...extra });

function uiOf(root) {
    const host = root.children.find((c) => c.tagName === 'FSC-PREVIEW-UI');
    if (!host) { return null; }
    const parts = host.shadow.children;
    const frames = parts.filter((p) => p.className.split(' ')[0] === 'f');
    const line = parts.find((p) => p.className === 'i');
    return { host, sel: frames.find((f) => !f.className.split(' ').includes('h')), hov: frames.find((f) => f.className.split(' ').includes('h')), line, plus: line.children[0] };
}

/* ---------------------------------------------------------------------------------------------------------------------------------------- */

describe('preview.js on load', () => {
    it('marks the page at once, hides the token from the DOM and says ready (protocol 2 capable) to the exact portal origin', () => {
        const { win, root, tag, sent } = run();

        expect(root.getAttribute('data-fsc-preview')).toBe('');
        expect(win.fscPreviewMode).toBe(true);
        expect(tag.getAttribute('data-token')).toBe(null);
        expect(sent).toHaveLength(1);
        expect(sent[0].target).toBe(PORTAL);
        expect(sent[0].message).toEqual({
            ns: 'fsc-preview', v: 1, type: 'ready', version: 3, sdk: '0.31.0', proto: 2,
            caps: ['blocks', 'select', 'hover', 'insert', 'order', 'edit-plain', 'edit-rich', 'format', 'shortcut', 'theme', 'timing'],
        });
    });

    it('restores the scroll position saved before a full reload, once', () => {
        const storage = { 'fsc-preview-scroll': JSON.stringify({ x: 0, y: 900 }) };
        const { win } = run({ storage });

        expect(win.scrolledTo).toEqual([0, 900]);
        expect(storage['fsc-preview-scroll']).toBeUndefined();
    });

    it('works in its own window without a parent or opener: marks the page, posts nothing', () => {
        const { root, sent, fire, blocks } = run({ framed: false });

        expect(root.getAttribute('data-fsc-preview')).toBe('');
        expect(sent).toEqual([]);
        expect(fire('click', blocks.children[0].children[0]).prevented).toBe(false);
        expect(sent).toEqual([]);
    });

    it('talks to the opener when opened from the portal in a new window', () => {
        const { sent } = run({ framed: false, opener: true });

        expect(types(sent)).toEqual(['ready']);
    });
});

describe('protocol 1 (no init): exactly as before', () => {
    it('blocks every form submit in the capture phase', () => {
        const { fire, listeners } = run();
        const event = fire('submit', el('form'));

        expect(listeners['doc:submit'][0].capture).toBe(true);
        expect(event.prevented).toBe(true);
        expect(event.stopped).toBe(true);
    });

    it('posts select for a click inside a block and keeps a framed preview from following its links', () => {
        const { fire, blocks, sent } = run();
        const link = blocks.children[1].children[0].children[2];
        const event = fire('click', link);

        expect(event.prevented).toBe(true);
        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'select', blockId: 'b2', index: 1 });
    });

    it('a click on a marked field only selects: no editing, no field, no frame drawn', () => {
        const { fire, blocks, sent, root } = run();
        const field = heading(blocks, 0);
        fire('click', field);

        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'select', blockId: 'b1', index: 0 });
        expect(field.getAttribute('contenteditable')).toBe(null);
        expect(uiOf(root)).toBe(null);
    });

    it('keeps a framed preview on every link, also outside blocks, without a select', () => {
        const { fire, sent } = run();
        const menu = el('nav', {}, [el('a', { href: '/contact' })]);
        const event = fire('click', menu.children[0]);

        expect(event.prevented).toBe(true);
        expect(types(sent)).toEqual(['ready']);
    });

    it('ignores clicks on plain text outside blocks', () => {
        const { fire, sent } = run();
        const event = fire('click', el('p'));

        expect(event.prevented).toBe(false);
        expect(types(sent)).toEqual(['ready']);
    });

    it('ignores the messages of protocol 2 and posts nothing of it', async () => {
        const { message, sent, fire, blocks, flush } = run();

        message(msg('select', { blockId: 'b1', scroll: true }));
        message(msg('order', { ids: ['b2', 'b1'] }));
        message(msg('hover', { blockId: 'b1' }));
        fire('mousemove', heading(blocks, 0), { clientX: 50, clientY: 120 });
        fire('keydown', el('body'), { key: 'z', metaKey: true });
        await flush();

        expect(types(sent)).toEqual(['ready']);
        expect(blocks.children.map((b) => b.getAttribute('data-fsc-block'))).toEqual(['b1', 'b2']);
    });
});

describe('messages from the portal', () => {
    it('ignores a message from another origin, another window or another namespace', async () => {
        const { message, fetches, flush, parent } = run();

        message(msg('refresh', { version: 4 }), { origin: 'https://evil.test', source: parent });
        message(msg('refresh', { version: 4 }), { origin: PORTAL, source: {} });
        message({ ns: 'other', v: 1, type: 'refresh', version: 4 });
        message({ ns: 'fsc-preview', v: 2, type: 'refresh', version: 4 });
        message('refresh');
        await flush();

        expect(fetches).toEqual([]);
    });

    it('accepts nothing when the page has no portal origin', async () => {
        const { message, fetches, sent, flush } = run({ origin: '' });

        message(msg('refresh', { version: 4 }), { origin: '', source: undefined });
        await flush();

        expect(fetches).toEqual([]);
        expect(sent).toEqual([]);
    });

    it('soft swaps the blocks root on refresh when the count matches, keeps the scroll and reports the new version', async () => {
        const next = page(['b1', 'b2', 'b3'], 5);
        const { message, fetches, flush, root, doc, win, sent } = run({ nextPage: next });

        message(msg('refresh', { version: 5 }));
        await flush();

        expect(fetches).toHaveLength(1);
        expect(fetches[0].url).toBe('/_fsc/p');
        expect(fetches[0].init.method).toBe('POST');
        expect(fetches[0].init.credentials).toBe('omit');
        expect(fetches[0].body).toEqual({ token: TOKEN });
        expect(root.children[1].children[0]).toBe(next.blocks);
        expect(doc.title).toBe('Nieuwe titel');
        expect(win.scrolledTo).toEqual([0, 420]);
        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'rendered', version: 5 });
    });

    it('reloads with a form POST (token in the body) when the number of roots changed, and saves the scroll', async () => {
        const next = { root: el('html', {}, [el('body')]) };
        const { message, flush, root, storage } = run({ nextPage: next });

        message(msg('refresh', { version: 6 }));
        await flush();

        const form = root.children[1].children.at(-1);
        expect(form.tagName).toBe('FORM');
        expect(form.method).toBe('POST');
        expect(form.action).toBe('/_fsc/p');
        expect(form.children.map((i) => [i.name, i.value])).toEqual([['token', TOKEN]]);
        expect(form.submitted).toBe(true);
        expect(JSON.parse(storage['fsc-preview-scroll'])).toEqual({ x: 0, y: 420 });
    });

    it('reports an expired token as an error and changes nothing', async () => {
        const { message, flush, sent, root, blocks } = run({ fetchReply: { ok: false, status: 404, text: () => Promise.resolve('') } });

        message(msg('refresh', { version: 6 }));
        await flush();

        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'error', code: 'expired' });
        expect(root.children[1].children[0]).toBe(blocks);
    });

    it('reports a rate limit with the retry delay of the site', async () => {
        const { message, flush, sent } = run({ fetchReply: { ok: false, status: 429, headers: { get: (name) => (name === 'Retry-After' ? '17' : null) }, text: () => Promise.resolve('') } });

        message(msg('refresh', { version: 6 }));
        await flush();

        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'error', code: 'rate_limited', retryAfter: 17 });
    });

    it('outlines and scrolls to the highlighted block, and clears the previous one', () => {
        const { message, blocks } = run();
        const first = blocks.children[0].children[0];
        const second = blocks.children[1].children[0];

        message(msg('highlight', { blockId: 'b1' }));
        expect(first.style.outline).toBe('2px solid #2563eb');
        expect(first.scrolled).toBeTruthy();

        message(msg('highlight', { blockId: 'b2' }));
        expect(first.style.outline).toBe(undefined);
        expect(second.style.outline).toBe('2px solid #2563eb');
    });
});

describe('html blocks in the preview (SDK 0.28)', () => {
    function withCode(win) {
        const grants = [];
        let scans = 0;
        win.fscCode = { grant: (value) => grants.push(value), scan: () => { scans += 1; } };
        return { grants, scans: () => scans };
    }

    it('grants consent from the portal inside the frame only, with known categories only', () => {
        const { win, message } = run();
        const code = withCode(win);

        message(msg('consent', { categories: ['marketing', 'necessary', 'evil', 'marketing', 'analytics'] }));

        expect(code.grants).toEqual([{ categories: ['marketing', 'analytics'] }]);
    });

    it('ignores consent from another origin or source, and malformed consent', () => {
        const { win, message } = run();
        const code = withCode(win);

        message(msg('consent', { categories: ['marketing'] }), { origin: 'https://evil.test', source: win.parent });
        message(msg('consent', { categories: ['marketing'] }), { origin: PORTAL, source: {} });
        message(msg('consent', { categories: 'marketing' }));
        message(msg('consent', { categories: ['necessary'] }));

        expect(code.grants).toEqual([]);
    });

    it('after a soft swap the loader scans the new blocks with the consent granted so far', async () => {
        const next = page(['b1', 'b2'], 5);
        const { win, message, flush } = run({ nextPage: next });
        const code = withCode(win);
        message(msg('consent', { categories: ['preferences'] }));

        message(msg('refresh', { version: 5 }));
        await flush();

        expect(code.grants.at(-1)).toEqual({ categories: ['preferences'] });
        expect(code.scans()).toBe(1);
    });

    it('reloads instead of swapping when a new html block needs the loader or the frame sizer the page lacks', async () => {
        for (const extra of [el('template', { 'data-fsc-code': '', 'data-consent': 'marketing' }), el('iframe', { 'data-fsc-html-frame': '' })]) {
            const next = page(['b1'], 5);
            next.blocks.children[0].appendChild(extra);
            const { message, flush, root } = run({ nextPage: next });

            message(msg('refresh', { version: 5 }));
            await flush();

            const form = root.children[1].children.at(-1);
            expect(form.tagName).toBe('FORM');
            expect(form.submitted).toBe(true);
        }
    });

    it('swaps softly when the page already has the loader and the sizer', async () => {
        const next = page(['b1'], 5);
        next.blocks.children[0].appendChild(el('iframe', { 'data-fsc-html-frame': '' }));
        const { win, message, flush, root } = run({ nextPage: next });
        withCode(win);
        win.fscHtmlBlock = { frames: () => [] };

        message(msg('refresh', { version: 5 }));
        await flush();

        expect(root.children[1].children[0]).toBe(next.blocks);
    });
});

describe('handshake (protocol 2)', () => {
    it('turns on the editor only after a valid init: closed shadow UI on <html>, theme once, then the blocks report', () => {
        const t = run();
        expect(uiOf(t.root)).toBe(null);

        t.init();

        const ui = uiOf(t.root);
        expect(ui.host.shadowMode).toBe('closed');
        expect(ui.host.style['pointer-events']).toBe('none !important');
        expect(ui.host.style['--z']).toBe('1');
        expect(ui.plus.getAttribute('aria-label')).toBe('Sectie toevoegen');
        expect(types(t.sent)).toEqual(['ready', 'theme', 'blocks']);
        expect(strip(last(t.sent, 'theme'))).toEqual({ type: 'theme', colors: {
            accent: 'rgb(29, 78, 216)', accentContrast: 'rgb(255, 255, 255)', toneSoft: 'rgb(232, 236, 247)', surface: 'rgb(250, 250, 250)',
            text: 'rgb(17, 17, 17)', muted: 'rgb(75, 75, 75)',
        } });
        expect(strip(last(t.sent, 'blocks'))).toEqual({ type: 'blocks', version: 3, items: [
            { id: 'b1', index: 0, type: 'hero', default: true, layouts: ['standard', 'image-right'], editable: ['heading', 'html'], top: 520, height: 180 },
            { id: 'b2', index: 1, type: 'hero', default: true, layouts: ['standard', 'image-right'], editable: ['heading', 'html'], top: 720, height: 180 },
        ] });

        // A second init (new labels, a zoom) updates, without a second theme.
        t.init({ scale: 0.5, labels: { b1: 'Kop' } });
        expect(uiOf(t.root).host.style['--z']).toBe('0.5');
        expect(t.sent.filter((s) => s.message.type === 'theme')).toHaveLength(1);
    });

    it('refuses an init of another protocol, origin or window', () => {
        const t = run();

        t.init({ proto: 3 });
        t.message(msg('init', { proto: 2, inline: true }), { origin: 'https://evil.test', source: t.parent });
        t.message(msg('init', { proto: 2, inline: true }), { origin: PORTAL, source: {} });

        expect(uiOf(t.root)).toBe(null);
        expect(types(t.sent)).toEqual(['ready']);
    });

    it('after init refuses select, hover, order and edit-cancel from another origin or window', () => {
        const t = run();
        t.init();
        const before = t.sent.length;

        for (const from of [{ origin: 'https://evil.test', source: t.parent }, { origin: PORTAL, source: {} }, { origin: 'null', source: t.parent }]) {
            t.message(msg('select', { blockId: 'b2', scroll: true }), from);
            t.message(msg('order', { ids: ['b2', 'b1'] }), from);
            t.message(msg('hover', { blockId: 'b1' }), from);
        }

        expect(uiOf(t.root).sel.style.display).toBe('none');
        expect(t.blocks.children.map((b) => b.getAttribute('data-fsc-block'))).toEqual(['b1', 'b2']);
        expect(t.sent.length).toBe(before);
    });

    it('sets labels as text only', () => {
        const t = run();
        t.init({ labels: { b1: '<img src=x onerror=alert(1)>' } });

        t.fire('click', t.blocks.children[0].children[0]);

        const chip = uiOf(t.root).sel.children[0];
        expect(chip.childNodes).toHaveLength(1);
        expect(chip.childNodes[0].nodeType).toBe(3);
        expect(chip.textContent).toBe('<img src=x onerror=alert(1)>');
    });
});

describe('selection, hover and insert (protocol 2)', () => {
    it('a click selects: the frame covers the union of the wrapper children, the chip names the block', () => {
        const t = run();
        t.init();
        const wrapper = t.blocks.children[1];
        wrapper.appendChild(Object.assign(el('div'), { rect: { left: 20, top: 400, width: 100, height: 300 } }));

        t.fire('click', wrapper.children[0]);

        const { sel } = uiOf(t.root);
        expect(strip(last(t.sent, 'select'))).toEqual({ type: 'select', blockId: 'b2', index: 1 });
        expect([sel.style.display, sel.style.left, sel.style.top, sel.style.width, sel.style.height]).toEqual(['block', '20px', '300px', '820px', '400px']);
        expect(sel.children[0].textContent).toBe('Tekst');
    });

    it('hover draws a light outline and tells the portal (throttled), the portal can hover and select without an echo', () => {
        const t = run();
        t.init();

        const hovers = () => t.sent.filter((s) => s.message.type === 'hover').map((s) => s.message.blockId);
        t.fire('mousemove', heading(t.blocks, 0), { clientX: 100, clientY: 150 });
        t.clock.tick(60);
        t.fire('mousemove', heading(t.blocks, 1), { clientX: 100, clientY: 350 });
        t.fire('mousemove', heading(t.blocks, 0), { clientX: 100, clientY: 150 });
        t.fire('mousemove', heading(t.blocks, 1), { clientX: 100, clientY: 350 });
        expect(hovers()).toEqual(['b1']);
        t.clock.tick(60);
        expect(hovers()).toEqual(['b1', 'b2']);
        expect(uiOf(t.root).hov.style.display).toBe('block');
        t.fire('mouseout', t.doc.body, { relatedTarget: null });
        t.clock.tick(60);
        expect(hovers()).toEqual(['b1', 'b2', null]);
        expect(uiOf(t.root).hov.style.display).toBe('none');

        const before = t.sent.length;
        t.message(msg('hover', { blockId: 'b1' }));
        t.message(msg('select', { blockId: 'b2', scroll: true }));
        expect(t.sent.length).toBe(before);
        expect(uiOf(t.root).sel.style.top).toBe('300px');
        expect(t.blocks.children[1].children[0].scrolled).toEqual({ block: 'center', behavior: 'smooth' });

        t.message(msg('select', { blockId: null }));
        expect(uiOf(t.root).sel.style.display).toBe('none');
    });

    it('shows the "+" line near the gap between two blocks and posts insert with the position', () => {
        const t = run();
        t.init();
        const { line, plus } = uiOf(t.root);

        t.fire('mousemove', t.blocks.children[0].children[0], { clientX: 300, clientY: 285 });
        expect(line.style.display).toBe('block');
        expect(line.style.top).toBe('290px');

        const click = plus.dispatch('click');
        expect(click.prevented).toBe(true);
        expect(strip(last(t.sent, 'insert'))).toEqual({ type: 'insert', index: 1 });

        t.fire('mousemove', t.blocks.children[0].children[0], { clientX: 300, clientY: 200 });
        expect(line.style.display).toBe('none');
        t.fire('mousemove', t.blocks.children[1].children[0], { clientX: 300, clientY: 478 });
        plus.dispatch('click');
        expect(strip(last(t.sent, 'insert'))).toEqual({ type: 'insert', index: 2 });
    });

    it('a click on the UI host itself does not select a block', () => {
        const t = run();
        t.init();
        const before = t.sent.length;

        t.fire('click', uiOf(t.root).host);

        expect(t.sent.length).toBe(before);
    });
});

describe('plain inline editing (protocol 2, AD7)', () => {
    function editing(options) {
        const t = run(options);
        t.init();
        const field = heading(t.blocks, 0);
        t.doc.caretPoint = () => ({ offsetNode: field.childNodes[0], offset: 2 });
        t.fire('click', field, { clientX: 60, clientY: 130 });
        return { ...t, field };
    }

    const type = (t, field, value) => { field.textContent = value; t.fire('input', field); };

    it('a click on a plain field selects with the field and edits at the click point', () => {
        const t = editing();

        expect(t.sent.slice(-2).map((s) => strip(s.message))).toEqual([
            { type: 'select', blockId: 'b1', index: 0, field: 'heading' },
            { type: 'edit-start', blockId: 'b1', field: 'heading', kind: 'plain' },
        ]);
        expect(t.field.getAttribute('contenteditable')).toBe('plaintext-only');
        expect(t.doc.activeElement).toBe(t.field);
        expect([t.selection.ranges[0].startContainer, t.selection.ranges[0].startOffset]).toEqual([t.field.childNodes[0], 2]);
    });

    it('falls back to contenteditable="true" where plaintext-only is not supported', () => {
        const t = editing({ noPlaintextOnly: true });

        expect(t.field.getAttribute('contenteditable')).toBe('true');
    });

    it('a rich field or a portal without inline only selects', () => {
        const t = run();
        t.init();
        const rich = t.blocks.children[0].children[0].children[1];
        t.fire('click', rich);
        expect(strip(last(t.sent, 'select'))).toEqual({ type: 'select', blockId: 'b1', index: 0, field: 'html' });
        expect(rich.getAttribute('contenteditable')).toBe(null);

        t.init({ inline: false });
        t.fire('click', heading(t.blocks, 1));
        expect(heading(t.blocks, 1).getAttribute('contenteditable')).toBe(null);
        expect(types(t.sent)).not.toContain('edit-start');
    });

    it('on touch the first tap selects, the second tap edits', () => {
        const t = run();
        t.init();
        const field = heading(t.blocks, 1);

        t.fire('pointerdown', field, { pointerType: 'touch' });
        t.fire('click', field);
        expect(field.getAttribute('contenteditable')).toBe(null);
        expect(strip(last(t.sent, 'select'))).toEqual({ type: 'select', blockId: 'b2', index: 1, field: 'heading' });

        t.fire('pointerdown', field, { pointerType: 'touch' });
        t.fire('click', field);
        expect(field.getAttribute('contenteditable')).toBe('plaintext-only');
    });

    it('sends edit with a normalized text value and a monotonic seq, throttled to 100 ms', () => {
        const t = editing();

        type(t, t.field, '  Nieuwe\n  kop ');
        type(t, t.field, 'Nieuwe kop!');
        type(t, t.field, 'Nieuwe kop!!');
        expect(t.sent.filter((s) => s.message.type === 'edit').map((s) => strip(s.message))).toEqual([
            { type: 'edit', blockId: 'b1', field: 'heading', kind: 'plain', value: 'Nieuwe kop', seq: 1 },
        ]);

        t.clock.tick(100);
        expect(strip(last(t.sent, 'edit'))).toEqual({ type: 'edit', blockId: 'b1', field: 'heading', kind: 'plain', value: 'Nieuwe kop!!', seq: 2 });
    });

    it('sends nothing during IME composition and one edit at compositionend', () => {
        const t = editing();

        t.fire('compositionstart', t.field);
        type(t, t.field, 'Kop にほ');
        t.clock.tick(500);
        type(t, t.field, 'Kop 日本');
        t.clock.tick(500);
        expect(types(t.sent)).not.toContain('edit');

        t.fire('keydown', t.field, { key: 'Enter', isComposing: true, keyCode: 229 });
        expect(t.field.getAttribute('contenteditable')).toBe('plaintext-only');

        t.fire('compositionend', t.field);
        expect(t.sent.filter((s) => s.message.type === 'edit').map((s) => s.message.value)).toEqual(['Kop 日本']);
    });

    it('Enter ends the edit (flushing the last value), Escape ends it and says escape', () => {
        const t = editing();
        type(t, t.field, 'Een');
        type(t, t.field, 'Een twee');

        const enter = t.fire('keydown', t.field, { key: 'Enter' });
        expect(enter.prevented).toBe(true);
        expect(t.sent.slice(-2).map((s) => strip(s.message))).toEqual([
            { type: 'edit', blockId: 'b1', field: 'heading', kind: 'plain', value: 'Een twee', seq: 2 },
            { type: 'edit-end', blockId: 'b1', field: 'heading', kind: 'plain', seq: 2, reason: 'enter' },
        ]);
        expect(t.field.getAttribute('contenteditable')).toBe(null);

        t.fire('click', t.field, { clientX: 60, clientY: 130 });
        t.fire('keydown', t.field, { key: 'Escape' });
        expect(t.sent.slice(-2).map((s) => strip(s.message))).toEqual([
            { type: 'edit-end', blockId: 'b1', field: 'heading', kind: 'plain', seq: 2, reason: 'escape' },
            { type: 'shortcut', name: 'escape' },
        ]);
    });

    it('a soft keyboard Enter and rich formatting never reach the field', () => {
        const t = editing();

        expect(t.fire('beforeinput', t.field, { inputType: 'formatBold' }).prevented).toBe(true);
        expect(t.fire('beforeinput', t.field, { inputType: 'insertFromDrop' }).prevented).toBe(true);
        expect(t.fire('beforeinput', t.field, { inputType: 'insertText' }).prevented).toBe(false);
        expect(t.fire('beforeinput', t.field, { inputType: 'insertParagraph' }).prevented).toBe(true);
        expect(last(t.sent, 'edit-end').reason).toBe('enter');
    });

    it('pastes text only, on one line', () => {
        const t = editing();

        const paste = t.fire('paste', t.field, { clipboardData: { getData: (kind) => (kind === 'text/plain' ? 'Regel een\nregel <b>twee</b>' : '<img src=x onerror=alert(1)>') } });

        expect(paste.prevented).toBe(true);
        expect(t.inserted).toEqual([['insertText', 'Regel een regel <b>twee</b>']]);
    });

    it('a blur ends the edit', () => {
        const t = editing();
        type(t, t.field, 'Weg');

        t.doc.activeElement = t.doc.body;
        t.fire('focusout', t.field);
        t.clock.tick(0);

        expect(strip(last(t.sent, 'edit-end'))).toEqual({ type: 'edit-end', blockId: 'b1', field: 'heading', kind: 'plain', seq: 1, reason: 'blur' });
    });
});

describe('rich inline editing (protocol 2, Task 16)', () => {
    const richOf = (blocks, i) => blocks.children[i].children[0].children[1];
    const commands = (t) => t.inserted.map((c) => c[0]);
    const select = (t, node, start, end = start) => { const r = new FakeRange(); r.setStart(node, start); r.setEnd(node, end); t.selection.removeAllRanges(); t.selection.addRange(r); };

    function richEditing(initExtra = {}) {
        const t = run();
        t.init({ rich: true, ...initExtra });
        const field = richOf(t.blocks, 0);
        t.doc.caretPoint = () => ({ offsetNode: field.childNodes[0], offset: 4 });
        t.fire('click', field, { clientX: 60, clientY: 140 });
        return { ...t, field };
    }

    it('edits a rich field only when the portal said rich: true (an older portal would drop the text)', () => {
        const t = run();
        t.init();
        t.fire('click', richOf(t.blocks, 0));
        expect(richOf(t.blocks, 0).getAttribute('contenteditable')).toBe(null);

        t.init({ rich: true, inline: false });
        t.fire('click', richOf(t.blocks, 1));
        expect(richOf(t.blocks, 1).getAttribute('contenteditable')).toBe(null);
        expect(types(t.sent)).not.toContain('edit-start');
    });

    it('a click on a rich field edits it as rich text, with paragraphs and the formats at the caret', () => {
        const t = richEditing();

        expect(t.field.getAttribute('contenteditable')).toBe('true');
        expect(t.doc.activeElement).toBe(t.field);
        expect(t.inserted.slice(0, 2)).toEqual([['defaultParagraphSeparator', 'p'], ['styleWithCSS', false]]);
        expect(t.sent.slice(-2).map((s) => strip(s.message))).toEqual([
            { type: 'select', blockId: 'b1', index: 0, field: 'html' },
            { type: 'edit-start', blockId: 'b1', field: 'html', kind: 'rich', state: { block: 'p', bold: false, italic: false, link: false } },
        ]);
    });

    it('an empty rich field starts with a paragraph', () => {
        const t = run();
        t.init({ rich: true });
        const field = richOf(t.blocks, 1);
        field.textContent = '';

        t.fire('click', field);

        expect(field.innerHTML).toBe('<p><br></br></p>');
        expect(t.selection.ranges[0].startContainer).toBe(field.children[0]);
    });

    it('sends the markup with every edit (kind rich), Enter stays in the field, Escape ends it', () => {
        const t = richEditing();

        t.field.appendChild(el('strong', {}, [txt(' vet')]));
        t.fire('input', t.field);
        expect(strip(last(t.sent, 'edit'))).toEqual({ type: 'edit', blockId: 'b1', field: 'html', kind: 'rich', value: 'Rijke tekst<strong> vet</strong>', seq: 1, state: { block: 'p', bold: false, italic: false, link: false } });

        expect(t.fire('keydown', t.field, { key: 'Enter' }).prevented).toBe(false);
        expect(t.fire('beforeinput', t.field, { inputType: 'insertParagraph' }).prevented).toBe(false);
        expect(t.fire('beforeinput', t.field, { inputType: 'insertLineBreak' }).prevented).toBe(false);
        expect(types(t.sent)).not.toContain('edit-end');

        t.fire('keydown', t.field, { key: 'Escape' });
        expect(t.sent.slice(-2).map((s) => strip(s.message))).toEqual([
            { type: 'edit-end', blockId: 'b1', field: 'html', kind: 'rich', seq: 1, reason: 'escape' },
            { type: 'shortcut', name: 'escape' },
        ]);
    });

    it('a field without visible text is sent as an empty value', () => {
        const t = richEditing();
        t.field.textContent = '';
        t.field.appendChild(el('p', {}, [txt('  ')]));

        t.fire('input', t.field);

        expect(last(t.sent, 'edit').value).toBe('');
    });

    it('Cmd/Ctrl+B and I format, K asks the portal for an address, U does nothing', () => {
        const t = richEditing();
        t.inserted.length = 0;

        expect(t.fire('keydown', t.field, { key: 'b', metaKey: true }).prevented).toBe(true);
        expect(t.fire('keydown', t.field, { key: 'i', ctrlKey: true }).prevented).toBe(true);
        expect(t.fire('keydown', t.field, { key: 'u', metaKey: true }).prevented).toBe(true);
        expect(t.fire('keydown', t.field, { key: 'k', metaKey: true }).prevented).toBe(true);

        expect(commands(t)).toEqual(['bold', 'italic']);
        expect(strip(last(t.sent, 'shortcut'))).toEqual({ type: 'shortcut', name: 'link' });
        expect(t.field.getAttribute('contenteditable')).toBe('true');
    });

    it('applies the format commands of the portal, only from the portal, only the known ones', () => {
        const t = richEditing();
        t.inserted.length = 0;

        for (const command of ['bold', 'italic', 'h2', 'h3', 'p', 'ul', 'ol', 'unlink', 'underline', 'insertHTML']) { t.message(msg('format', { command })); }
        t.message(msg('format', { command: 'bold' }), { origin: 'https://evil.test', source: t.parent });
        t.message(msg('format', { command: 'bold' }), { origin: PORTAL, source: {} });

        expect(t.inserted).toEqual([
            ['bold', undefined], ['italic', undefined], ['formatBlock', '<h2>'], ['formatBlock', '<h3>'], ['formatBlock', '<p>'],
            ['insertUnorderedList', undefined], ['insertOrderedList', undefined], ['unlink', undefined],
        ]);
    });

    it('a list is made of paragraphs: a heading becomes one first, and a list Chrome left inside its paragraph takes its place', () => {
        const t = richEditing();
        const heading = el('h2', {}, [txt('Kop')]);
        t.field.appendChild(heading);
        select(t, heading.childNodes[0], 1);
        t.inserted.length = 0;

        t.message(msg('format', { command: 'ul' }));
        expect(t.inserted).toEqual([['formatBlock', '<p>'], ['insertUnorderedList', undefined]]);

        // What Chrome made of it: <p><ul><li>…</li></ul></p>.
        const item = el('li', {}, [txt('Punt')]);
        const wrapper = el('p', {}, [el('ol', {}, [item])]);
        t.field.textContent = '';
        t.field.appendChild(wrapper);
        select(t, item.childNodes[0], 2);
        t.inserted.length = 0;
        t.message(msg('format', { command: 'ol' }));

        expect(t.inserted).toEqual([['insertOrderedList', undefined]]);
        expect(t.field.innerHTML).toBe('<ol><li>Punt</li></ol>');
        expect([t.selection.ranges[0].startContainer, t.selection.ranges[0].startOffset]).toEqual([item.childNodes[0], 2]);
    });

    it('a link takes only https, mailto, tel, a path or an anchor', () => {
        const t = richEditing();
        select(t, t.field.childNodes[0], 0, 5);
        t.inserted.length = 0;

        for (const value of ['javascript:alert(1)', 'http://plain.test', '//evil.test', 'data:text/html,x', ' https://a b', 'https://ok.nl/pagina', '/contact', '#over', 'mailto:info@ok.nl', 'tel:+31201234567']) {
            t.message(msg('format', { command: 'link', value }));
        }

        expect(t.inserted).toEqual([['createLink', 'https://ok.nl/pagina'], ['createLink', '/contact'], ['createLink', '#over'], ['createLink', 'mailto:info@ok.nl'], ['createLink', 'tel:+31201234567']]);
    });

    it('a link at a caret without a selection is inserted with the address as its text (escaped)', () => {
        const t = richEditing();
        select(t, t.field.childNodes[0], 3);
        t.inserted.length = 0;

        t.message(msg('format', { command: 'link', value: 'https://ok.nl/?a=1&b=2' }));

        expect(t.inserted).toEqual([['insertHTML', '<a href="https://ok.nl/?a=1&amp;b=2">https://ok.nl/?a=1&amp;b=2</a>']]);
    });

    it('a link after the portal took the focus (its address popover) resumes the field with the same selection', () => {
        const t = richEditing();
        select(t, t.field.childNodes[0], 0, 5);
        t.fire('selectionchange', t.doc);
        t.clock.tick(60);

        t.doc.activeElement = t.doc.body;
        t.fire('focusout', t.field);
        t.clock.tick(0);
        expect(strip(last(t.sent, 'edit-end'))).toMatchObject({ blockId: 'b1', field: 'html', kind: 'rich', reason: 'blur' });
        expect(t.field.getAttribute('contenteditable')).toBe(null);
        t.inserted.length = 0;

        // Another field, or no rich field left a moment ago: nothing happens.
        t.message(msg('format', { command: 'bold', blockId: 'b2', field: 'html' }));
        expect(t.inserted).toEqual([]);

        t.message(msg('format', { command: 'link', value: 'https://ok.nl', blockId: 'b1', field: 'html' }));

        expect(t.field.getAttribute('contenteditable')).toBe('true');
        expect(last(t.sent, 'edit-start')).toMatchObject({ blockId: 'b1', field: 'html', kind: 'rich' });
        const range = t.selection.ranges[0];
        expect([range.startContainer, range.startOffset, range.endContainer, range.endOffset]).toEqual([t.field.childNodes[0], 0, t.field.childNodes[0], 5]);
        expect(commands(t)).toContain('createLink');
    });

    it('reports the formats at the caret when they change (edit-state)', () => {
        const t = richEditing();
        const strong = el('strong', {}, [txt('vet')]);
        t.field.appendChild(el('h2', {}, [strong]));

        select(t, strong.childNodes[0], 1);
        t.fire('selectionchange', t.doc);
        t.clock.tick(60);
        t.fire('selectionchange', t.doc);
        t.clock.tick(60);

        expect(t.sent.filter((s) => s.message.type === 'edit-state').map((s) => strip(s.message))).toEqual([
            { type: 'edit-state', blockId: 'b1', field: 'html', state: { block: 'h2', bold: true, italic: false, link: false } },
        ]);
    });

    it('refuses the browser formats it does not offer, drops and raw HTML', () => {
        const t = richEditing();

        for (const inputType of ['formatUnderline', 'formatStrikeThrough', 'formatFontColor', 'formatJustifyCenter', 'insertFromDrop', 'insertHTML', 'insertFromPaste', 'insertLink']) {
            expect(t.fire('beforeinput', t.field, { inputType }).prevented).toBe(true);
        }
        for (const inputType of ['formatBold', 'formatItalic', 'insertText', 'deleteContentBackward', 'historyUndo']) {
            expect(t.fire('beforeinput', t.field, { inputType }).prevented).toBe(false);
        }
        expect(t.fire('drop', t.field).prevented).toBe(true);
    });

    it('reduces pasted markup to the allowed tags: no script, no image, no event handler, no style, no unsafe link', () => {
        const t = richEditing();
        const markup = '<img src=x onerror=alert(1)><script>alert(1)</script><p style="color:red" onclick="steal()">Hoi <b>vet</b> <i>schuin</i> '
            + '<a href="javascript:alert(1)">slecht</a> <a href="https://ok.nl" target="_blank" onmouseover="x()">goed</a></p><h1>Titel</h1><div>los</div><ul><li>een</li></ul>';
        t.pasted.set(markup, el('body', {}, [
            el('img', { src: 'x', onerror: 'alert(1)' }),
            el('script', {}, [txt('alert(1)')]),
            el('p', { style: 'color:red', onclick: 'steal()' }, [
                txt('Hoi '), el('b', {}, [txt('vet')]), txt(' '), el('i', {}, [txt('schuin')]), txt(' '),
                el('a', { href: 'javascript:alert(1)' }, [txt('slecht')]), txt(' '), el('a', { href: 'https://ok.nl', target: '_blank', onmouseover: 'x()' }, [txt('goed')]),
            ]),
            el('h1', {}, [txt('Titel')]),
            el('div', {}, [txt('los <b>')]),
            el('ul', {}, [el('li', {}, [txt('een')])]),
        ]));
        t.inserted.length = 0;

        const paste = t.fire('paste', t.field, { clipboardData: { getData: (kind) => (kind === 'text/html' ? markup : 'platte tekst') } });

        expect(paste.prevented).toBe(true);
        expect(t.inserted).toEqual([['insertHTML', '<p>Hoi <strong>vet</strong> <em>schuin</em> slecht <a href="https://ok.nl">goed</a></p><h2>Titel</h2><p>los &lt;b&gt;</p><ul><li>een</li></ul>']]);
        expect(JSON.stringify(t.inserted)).not.toMatch(/onerror|script|javascript|onclick|onmouseover|style|target|img/i);
    });

    it('a Google Docs paste (one <b style="font-weight:normal"> around it all) is not made bold', () => {
        const t = richEditing();
        const markup = '<b style="font-weight:normal;" id="docs-internal-guid-1"><p>Gewoon</p></b>';
        t.pasted.set(markup, el('body', {}, [el('b', { style: 'font-weight:normal;', id: 'docs-internal-guid-1' }, [el('p', {}, [txt('Gewoon')])])]));
        t.inserted.length = 0;

        t.fire('paste', t.field, { clipboardData: { getData: (kind) => (kind === 'text/html' ? markup : '') } });

        expect(t.inserted).toEqual([['insertHTML', '<p>Gewoon</p>']]);
    });

    it('pastes plain text as paragraphs and line breaks, escaped', () => {
        const t = richEditing();
        t.inserted.length = 0;

        t.fire('paste', t.field, { clipboardData: { getData: (kind) => (kind === 'text/plain' ? 'Een <b>\n\nTwee\ndrie' : '') } });
        t.fire('paste', t.field, { clipboardData: { getData: (kind) => (kind === 'text/plain' ? 'los & kort' : '') } });

        expect(t.inserted).toEqual([['insertHTML', '<p>Een &lt;b&gt;</p><p>Twee<br>drie</p>'], ['insertHTML', 'los &amp; kort']]);
    });

    it('a swap waits for the ack while a rich field has the caret, like plain text', async () => {
        const t = richEditing();
        t.field.appendChild(txt('!'));
        t.fire('input', t.field);
        const next = page(['b1', 'b2'], 4);
        richOf(next.blocks, 0).textContent = 'Van de server';
        t.setNext(next);

        t.message(msg('refresh', { version: 4, ack: 1 }));
        await t.flush();
        expect(t.blocks.children[0].children[0].children[1]).toBe(t.field);

        t.fire('keydown', t.field, { key: 'Escape' });
        expect(richOf(t.blocks, 0).textContent).toBe('Van de server');
    });
});

describe('keyed swap, ack and reload (protocol 2, AD7)', () => {
    function typing(options = {}) {
        const t = run(options);
        t.init();
        const field = heading(t.blocks, 0);
        t.fire('click', field);
        field.textContent = 'Getypt';
        t.fire('input', field);
        return { ...t, field };
    }

    const refresh = async (t, next, ack, extra = {}) => { t.setNext(next); t.message(msg('refresh', { version: 9, ack, ...extra })); await t.flush(); };

    it('swaps only changed blocks, by id, and never the block with the caret', async () => {
        const t = typing();
        const b1 = t.blocks.children[0];
        const b2 = t.blocks.children[1];
        const next = page([['b1', { text: 'Getypt' }], ['b2', { text: 'Nieuw van de server' }]], 9);
        const freshB2 = next.blocks.children[1];

        await refresh(t, next, 1);

        expect(t.blocks.children[0]).toBe(b1);
        expect(t.blocks.children[1]).toBe(freshB2);
        expect(t.blocks.children[1]).not.toBe(b2);
        expect(t.root.children[1].children[0]).toBe(t.blocks);
        expect(t.doc.activeElement).toBe(t.field);
        expect(t.field.getAttribute('contenteditable')).toBe('plaintext-only');
        expect(strip(last(t.sent, 'rendered'))).toEqual({ type: 'rendered', version: 9, timing: { fetch: 0, server: null, swap: 0 } });
    });

    it('an unchanged block is not touched', async () => {
        const t = run();
        t.init();
        const b1 = t.blocks.children[0];

        await refresh(t, page(['b1', ['b2', { text: 'Anders' }]], 9), 0);

        expect(t.blocks.children[0]).toBe(b1);
    });

    it('the block with the caret takes the held markup after blur, when its ack covers the last seq', async () => {
        const t = typing();
        const next = page([['b1', { text: 'Getypt' }], 'b2'], 9);
        next.blocks.children[0].setAttribute('data-server', 'ja');
        const fresh = next.blocks.children[0];

        await refresh(t, next, 1);
        expect(t.blocks.children[0]).not.toBe(fresh);

        t.fire('keydown', t.field, { key: 'Enter' });
        expect(t.blocks.children[0]).toBe(fresh);
    });

    it('ack rule: a response without the last seq does not replace the block after blur; the next one with it does', async () => {
        const t = typing();
        t.clock.tick(100);
        t.field.textContent = 'Getypt meer';
        t.fire('input', t.field);
        expect(last(t.sent, 'edit').seq).toBe(2);

        const stale = page([['b1', { text: 'Getypt' }], 'b2'], 9);
        await refresh(t, stale, 1);
        t.fire('keydown', t.field, { key: 'Enter' });
        expect(t.blocks.children[0].children[0].children[0]).toBe(t.field);
        expect(t.field.textContent).toBe('Getypt meer');

        await refresh(t, page([['b1', { text: 'Ouder' }], 'b2'], 9), 1);
        expect(t.field.textContent).toBe('Getypt meer');

        const good = page([['b1', { text: 'Getypt meer' }], 'b2'], 10);
        const fresh = good.blocks.children[0];
        fresh.setAttribute('data-server', 'ja');
        await refresh(t, good, 2);
        expect(t.blocks.children[0]).toBe(fresh);
    });

    it('edit-cancel blurs the field and accepts the next swap whatever its ack', async () => {
        const t = typing();

        t.message(msg('edit-cancel', { blockId: 'b1' }));
        expect(strip(last(t.sent, 'edit-end'))).toEqual({ type: 'edit-end', blockId: 'b1', field: 'heading', kind: 'plain', seq: 1, reason: 'cancel' });
        expect(t.field.getAttribute('contenteditable')).toBe(null);

        const undone = page(['b1', 'b2'], 9);
        const fresh = undone.blocks.children[0];
        await refresh(t, undone, 0);
        expect(t.blocks.children[0]).toBe(fresh);
    });

    it('inserts and removes blocks by id from a refresh', async () => {
        const t = run();
        t.init();
        const b2 = t.blocks.children[1];
        const next = page(['b3', 'b2'], 9);

        await refresh(t, next, 0);

        expect(t.blocks.children.map((b) => b.getAttribute('data-fsc-block'))).toEqual(['b3', 'b2']);
        expect(t.blocks.children[1]).toBe(b2);
        expect(strip(last(t.sent, 'blocks')).items).toBeDefined();
        t.clock.tick(150);
        expect(last(t.sent, 'blocks').items.map((i) => i.id)).toEqual(['b3', 'b2']);
    });

    it('reports fetch, server (Server-Timing fsc-*) and swap times', async () => {
        const t = run({ fetchReply: { ok: true, status: 200, headers: { get: (name) => (name === 'Server-Timing' ? 'fsc-hub;dur=12.4, other;dur=99, fsc-render;desc="x";dur=30.2' : null) }, text: () => Promise.resolve('html') } });
        t.init();

        await refresh(t, page(['b1', 'b2'], 9), 0);

        expect(last(t.sent, 'rendered').timing).toEqual({ fetch: 0, server: 43, swap: 0 });
    });

    it('defers a full reload while a field has the caret, until blur', async () => {
        const t = typing();

        await refresh(t, { root: el('html', {}, [el('body')]) }, 1);
        expect(t.root.children[1].children.some((c) => c.tagName === 'FORM')).toBe(false);

        t.fire('keydown', t.field, { key: 'Enter' });
        const form = t.root.children[1].children.at(-1);
        expect(form.tagName).toBe('FORM');
        expect(form.submitted).toBe(true);
        expect(last(t.sent, 'edit-end').reason).toBe('enter');
    });

    it('defers a full reload until 2 s without input', async () => {
        const t = typing();
        await refresh(t, { root: el('html', {}, [el('body')]) }, 1);

        t.clock.tick(1500);
        t.field.textContent = 'Getypt nog';
        t.fire('input', t.field);
        t.clock.tick(1900);
        expect(t.root.children[1].children.some((c) => c.tagName === 'FORM')).toBe(false);

        t.clock.tick(200);
        expect(last(t.sent, 'edit').value).toBe('Getypt nog');
        expect(last(t.sent, 'edit-end').reason).toBe('reload');
        expect(t.root.children[1].children.at(-1).submitted).toBe(true);
    });
});

describe('order (protocol 2)', () => {
    it('moves the wrappers by id, renumbers them and keeps the caret in the moved field', () => {
        const t = run({ ids: ['b1', 'b2', 'b3'] });
        t.init();
        const field = heading(t.blocks, 0);
        t.fire('click', field);
        // Caret after "Kop" (3 characters).
        const range = new FakeRange();
        range.setStart(field.childNodes[0], 3);
        t.selection.removeAllRanges();
        t.selection.addRange(range);
        t.doc.activeElement = t.doc.body; // moving a node in the DOM drops its focus

        t.message(msg('order', { ids: ['b2', 'b3', 'b1', 'bad id!', 'b9'] }));
        t.clock.tick(0);

        expect(t.blocks.children.map((b) => [b.getAttribute('data-fsc-block'), b.getAttribute('data-fsc-block-index')])).toEqual([['b2', '0'], ['b3', '1'], ['b1', '2']]);
        expect(t.blocks.children[2].children[0].children[0]).toBe(field);
        expect(t.doc.activeElement).toBe(field);
        expect(field.getAttribute('contenteditable')).toBe('plaintext-only');
        expect([t.selection.ranges[0].startContainer, t.selection.ranges[0].startOffset]).toEqual([field.childNodes[0], 3]);
        expect(types(t.sent)).not.toContain('edit-end');
    });

    it('uses moveBefore where the browser has it, and still puts the caret back (Chrome loses it)', () => {
        const t = run({ ids: ['b1', 'b2'] });
        t.init();
        const field = heading(t.blocks, 0);
        t.fire('click', field);
        const range = new FakeRange();
        range.setStart(field.childNodes[0], 4);
        t.selection.removeAllRanges();
        t.selection.addRange(range);
        const calls = [];
        // Keeps the focus, drops the caret to the start.
        t.blocks.moveBefore = (node, ref) => {
            calls.push(node.getAttribute('data-fsc-block'));
            t.blocks.insertBefore(node, ref);
            const start = new FakeRange();
            start.setStart(field.childNodes[0], 0);
            t.selection.removeAllRanges();
            t.selection.addRange(start);
        };

        t.message(msg('order', { ids: ['b2', 'b1'] }));

        expect(calls).toEqual(['b1']);
        expect(t.blocks.children.map((b) => b.getAttribute('data-fsc-block'))).toEqual(['b2', 'b1']);
        expect(t.doc.activeElement).toBe(field);
        expect(t.selection.ranges[0].startOffset).toBe(4);
    });

    it('ignores a malformed order', () => {
        const t = run();
        t.init();

        t.message(msg('order', { ids: 'b2,b1' }));
        t.message(msg('order', { ids: new Array(501).fill('b1') }));

        expect(t.blocks.children.map((b) => b.getAttribute('data-fsc-block'))).toEqual(['b1', 'b2']);
    });
});

describe('keys inside the frame (protocol 2)', () => {
    const names = (t) => t.sent.filter((s) => s.message.type === 'shortcut').map((s) => s.message.name);

    it('maps undo, redo, save, escape and delete outside an edit', () => {
        const t = run();
        t.init();
        const body = t.doc.body;

        expect(t.fire('keydown', body, { key: 'z', metaKey: true }).prevented).toBe(true);
        t.fire('keydown', body, { key: 'Z', ctrlKey: true, shiftKey: true });
        t.fire('keydown', body, { key: 's', ctrlKey: true });
        t.fire('keydown', body, { key: 'Escape' });
        t.fire('keydown', body, { key: 'Delete' });
        t.fire('click', heading(t.blocks, 1).parentNode.children[2]);
        t.fire('keydown', body, { key: 'Backspace' });

        expect(names(t)).toEqual(['undo', 'redo', 'save', 'escape', 'delete']);
    });

    it('inside an edit the browser keeps undo and Backspace; save still works', () => {
        const t = run();
        t.init();
        const field = heading(t.blocks, 0);
        t.fire('click', field);

        expect(t.fire('keydown', field, { key: 'z', metaKey: true }).prevented).toBe(false);
        expect(t.fire('keydown', field, { key: 'Backspace' }).prevented).toBe(false);
        expect(t.fire('keydown', field, { key: 's', metaKey: true }).prevented).toBe(true);
        expect(names(t)).toEqual(['save']);
    });
});

describe('the Hub fallback (opaque origin)', () => {
    it('sends project with every request and refresh=1 with the fetch, and keeps the scroll in the window name', async () => {
        const t = run({ project: 'proj-1', noStorage: true, nextPage: { root: el('html', {}, [el('body')]) } });

        t.message(msg('refresh', { version: 4 }));
        await t.flush();

        expect(t.fetches[0].url).toBe('https://hub.test/_fsc/fp');
        expect(t.fetches[0].body).toEqual({ token: TOKEN, project: 'proj-1', refresh: '1' });
        const form = t.root.children[1].children.at(-1);
        expect(form.action).toBe('https://hub.test/_fsc/fp');
        expect(form.children.map((i) => [i.name, i.value])).toEqual([['token', TOKEN], ['project', 'proj-1']]);
        expect(t.win.name).toBe('fsc-preview-scroll:{"x":0,"y":420}');
    });

    it('restores the scroll from the window name when there is no storage', () => {
        const t = run({ noStorage: true });
        // A fresh load in the same frame: the name survived.
        const again = run({ noStorage: true });
        expect(t.win.scrolledTo).toBe(null);
        expect(again.win.scrolledTo).toBe(null);

        const win = { name: 'fsc-preview-scroll:{"x":0,"y":640}' };
        const t2 = runWithName(win);
        expect(t2.scrolledTo).toEqual([0, 640]);
        expect(t2.name).toBe('');
    });
});

/** Loads the script into a window that already carries a saved scroll in its name (no storage). */
function runWithName({ name }) {
    const { root } = page();
    const win = { name, scrollX: 0, scrollY: 0, scrolledTo: null, addEventListener() {}, scrollTo(x, y) { win.scrolledTo = [x, y]; } };
    win.parent = { postMessage() {} };
    const doc = { ...fakeDoc(root), body: root.children[1], readyState: 'complete', currentScript: el('script', { 'data-origin': PORTAL }), addEventListener() {}, createElement: (n) => el(n) };
    const session = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('SecurityError'); }, removeItem() { throw new Error('SecurityError'); } };
    new Function('window', 'document', 'sessionStorage', source)(win, doc, session);
    return win;
}

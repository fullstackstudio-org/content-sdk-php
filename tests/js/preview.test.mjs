// Tests for resources/assets/preview.js (the portal preview helper) with a minimal fake browser. Run with: bun test packages/content-sdk-php/tests/js
import { describe, expect, it } from 'bun:test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(join(import.meta.dir, '../../resources/assets/preview.js'), 'utf8');
const PORTAL = 'https://portal.fullstackstudio.nl';
const TOKEN = 'fscpv_' + 'A'.repeat(43);

function el(tag, attrs = {}, children = []) {
    const self = {
        nodeType: 1, tagName: tag.toUpperCase(), attrs: { ...attrs }, children: [], parentNode: null, style: {}, scrolled: false,
        getAttribute(name) { return name in self.attrs ? self.attrs[name] : null; },
        setAttribute(name, value) { self.attrs[name] = String(value); },
        removeAttribute(name) { delete self.attrs[name]; },
        appendChild(child) { child.parentNode = self; self.children.push(child); return child; },
        replaceChild(next, old) { const i = self.children.indexOf(old); next.parentNode = self; self.children[i] = next; old.parentNode = null; return old; },
        scrollIntoView() { self.scrolled = true; },
        submit() { self.submitted = true; },
        all() { return [self, ...self.children.flatMap((c) => c.all())]; },
    };
    children.forEach((c) => self.appendChild(c));
    return self;
}

function selectorMatch(selector) {
    if (selector === '[data-fsc-preview-blocks]') { return (n) => n.getAttribute('data-fsc-preview-blocks') !== null; }
    if (selector === '[data-fsc-block]') { return (n) => n.getAttribute('data-fsc-block') !== null; }
    if (selector === 'script[data-endpoint][data-version]') { return (n) => n.tagName === 'SCRIPT' && n.getAttribute('data-endpoint') !== null && n.getAttribute('data-version') !== null; }
    throw new Error('unsupported selector ' + selector);
}

function fakeDoc(root, title = '') {
    return {
        title,
        documentElement: root,
        querySelectorAll: (selector) => root.all().filter(selectorMatch(selector)),
        querySelector: (selector) => root.all().find(selectorMatch(selector)) ?? null,
    };
}

/** A page with one blocks root holding the given block ids. */
function page(ids = ['b1', 'b2'], version = 3) {
    const blocks = el('div', { 'data-fsc-preview-blocks': '' }, ids.map((id, i) => el('div', { 'data-fsc-block': id, 'data-fsc-block-index': String(i) }, [el('section'), el('a', { href: '/x' })])));
    const script = el('script', { 'data-endpoint': '/_fsc/p', 'data-version': String(version) });
    return { blocks, script, root: el('html', {}, [el('head', {}, [script]), el('body', {}, [blocks])]) };
}

function run({ framed = true, opener = false, origin = PORTAL, fetchReply = null, nextPage = null, storage = {} } = {}) {
    const { root, blocks } = page();
    const listeners = {};
    const sent = [];
    const fetches = [];
    const parent = { postMessage: (message, target) => sent.push({ message, target }) };
    const win = { scrollX: 0, scrollY: 420, scrolledTo: null, addEventListener: (type, fn) => { (listeners['win:' + type] ||= []).push(fn); }, scrollTo(x, y) { win.scrolledTo = [x, y]; } };
    win.parent = framed ? parent : win;
    win.opener = opener ? parent : null;
    const tag = el('script', { 'data-endpoint': '/_fsc/p', 'data-token': TOKEN, 'data-origin': origin, 'data-version': '3', 'data-sdk': '0.27.0' });
    const body = root.children[1];
    const doc = {
        ...fakeDoc(root),
        body, readyState: 'complete', currentScript: tag,
        addEventListener: (type, fn, capture) => { (listeners['doc:' + type] ||= []).push({ fn, capture }); },
        createElement: (name) => el(name),
        importNode: (node) => node,
    };
    const fetch = (url, init) => {
        fetches.push({ url, init, token: init.body.get('token') });
        const reply = fetchReply ?? { ok: true, status: 200, text: () => Promise.resolve('html') };
        return Promise.resolve(reply);
    };
    class DOMParser { parseFromString() { return nextPage ? fakeDoc(nextPage.root, 'Nieuwe titel') : fakeDoc(el('html')); } }
    const session = {
        getItem: (k) => storage[k] ?? null, setItem: (k, v) => { storage[k] = String(v); }, removeItem: (k) => { delete storage[k]; },
    };
    new Function('window', 'document', 'fetch', 'sessionStorage', 'DOMParser', 'URLSearchParams', source)(win, doc, fetch, session, DOMParser, URLSearchParams);

    const message = (data, from = { origin: PORTAL, source: parent }) => (listeners['win:message'] || []).forEach((fn) => fn({ data, ...from }));
    const fire = (type, target) => {
        const event = { target, prevented: false, stopped: false, preventDefault() { event.prevented = true; }, stopImmediatePropagation() { event.stopped = true; } };
        (listeners['doc:' + type] || []).forEach(({ fn }) => fn(event));
        return event;
    };
    const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

    return { win, doc, root, blocks, tag, sent, fetches, message, fire, flush, storage, listeners, parent };
}

const types = (sent) => sent.map((s) => s.message.type);

describe('preview.js on load', () => {
    it('marks the page at once, hides the token from the DOM and says ready to the exact portal origin', () => {
        const { win, root, tag, sent } = run();

        expect(root.getAttribute('data-fsc-preview')).toBe('');
        expect(win.fscPreviewMode).toBe(true);
        expect(tag.getAttribute('data-token')).toBe(null);
        expect(sent).toEqual([{ message: { ns: 'fsc-preview', v: 1, type: 'ready', version: 3, sdk: '0.27.0' }, target: PORTAL }]);
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

describe('form guard and block selection', () => {
    it('blocks every form submit in the capture phase', () => {
        const { fire, listeners } = run();
        const event = fire('submit', el('form'));

        expect(listeners['doc:submit'][0].capture).toBe(true);
        expect(event.prevented).toBe(true);
        expect(event.stopped).toBe(true);
    });

    it('posts select for a click inside a block and keeps a framed preview from following its links', () => {
        const { fire, blocks, sent } = run();
        const link = blocks.children[1].children[1];
        const event = fire('click', link);

        expect(event.prevented).toBe(true);
        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'select', blockId: 'b2', index: 1 });
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
});

describe('messages from the portal', () => {
    it('ignores a message from another origin, another window or another namespace', async () => {
        const { message, fetches, flush, parent } = run();

        message({ ns: 'fsc-preview', v: 1, type: 'refresh', version: 4 }, { origin: 'https://evil.test', source: parent });
        message({ ns: 'fsc-preview', v: 1, type: 'refresh', version: 4 }, { origin: PORTAL, source: {} });
        message({ ns: 'other', v: 1, type: 'refresh', version: 4 });
        message({ ns: 'fsc-preview', v: 2, type: 'refresh', version: 4 });
        message('refresh');
        await flush();

        expect(fetches).toEqual([]);
    });

    it('accepts nothing when the page has no portal origin', async () => {
        const { message, fetches, sent, flush } = run({ origin: '' });

        message({ ns: 'fsc-preview', v: 1, type: 'refresh', version: 4 }, { origin: '', source: undefined });
        await flush();

        expect(fetches).toEqual([]);
        expect(sent).toEqual([]);
    });

    it('soft swaps the blocks root on refresh when the count matches, keeps the scroll and reports the new version', async () => {
        const next = page(['b1', 'b2', 'b3'], 5);
        const { message, fetches, flush, root, doc, win, sent } = run({ nextPage: next });

        message({ ns: 'fsc-preview', v: 1, type: 'refresh', version: 5 });
        await flush();
        await flush();

        expect(fetches).toHaveLength(1);
        expect(fetches[0].url).toBe('/_fsc/p');
        expect(fetches[0].init.method).toBe('POST');
        expect(fetches[0].init.credentials).toBe('omit');
        expect(fetches[0].token).toBe(TOKEN);
        expect(root.children[1].children[0]).toBe(next.blocks);
        expect(doc.title).toBe('Nieuwe titel');
        expect(win.scrolledTo).toEqual([0, 420]);
        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'rendered', version: 5 });
    });

    it('reloads with a form POST (token in the body) when the number of roots changed, and saves the scroll', async () => {
        const next = { root: el('html', {}, [el('body')]) };
        const { message, flush, root, storage } = run({ nextPage: next });

        message({ ns: 'fsc-preview', v: 1, type: 'refresh', version: 6 });
        await flush();
        await flush();

        const form = root.children[1].children.at(-1);
        expect(form.tagName).toBe('FORM');
        expect(form.method).toBe('POST');
        expect(form.action).toBe('/_fsc/p');
        expect(form.children[0].name).toBe('token');
        expect(form.children[0].value).toBe(TOKEN);
        expect(form.submitted).toBe(true);
        expect(JSON.parse(storage['fsc-preview-scroll'])).toEqual({ x: 0, y: 420 });
    });

    it('reports an expired token as an error and changes nothing', async () => {
        const { message, flush, sent, root, blocks } = run({ fetchReply: { ok: false, status: 404, text: () => Promise.resolve('') } });

        message({ ns: 'fsc-preview', v: 1, type: 'refresh', version: 6 });
        await flush();
        await flush();

        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'error', code: 'expired' });
        expect(root.children[1].children[0]).toBe(blocks);
    });

    it('reports a rate limit with the retry delay of the site', async () => {
        const { message, flush, sent } = run({ fetchReply: { ok: false, status: 429, headers: { get: (name) => (name === 'Retry-After' ? '17' : null) }, text: () => Promise.resolve('') } });

        message({ ns: 'fsc-preview', v: 1, type: 'refresh', version: 6 });
        await flush();
        await flush();

        expect(sent.at(-1).message).toEqual({ ns: 'fsc-preview', v: 1, type: 'error', code: 'rate_limited', retryAfter: 17 });
    });

    it('outlines and scrolls to the highlighted block, and clears the previous one', () => {
        const { message, blocks } = run();
        const first = blocks.children[0].children[0];
        const second = blocks.children[1].children[0];

        message({ ns: 'fsc-preview', v: 1, type: 'highlight', blockId: 'b1' });
        expect(first.style.outline).toBe('2px solid #2563eb');
        expect(first.scrolled).toBe(true);

        message({ ns: 'fsc-preview', v: 1, type: 'highlight', blockId: 'b2' });
        expect(first.style.outline).toBe(undefined);
        expect(second.style.outline).toBe('2px solid #2563eb');
    });
});

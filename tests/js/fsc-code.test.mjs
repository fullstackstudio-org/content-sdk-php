// Tests of resources/assets/fsc-code.js (consent loader) and fsc-clicks.js (contact click events) with a minimal fake DOM (no dependencies).
// Run: `node --test tests/js` or `bun test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const read = (file) => readFileSync(fileURLToPath(new URL('../../resources/assets/' + file, import.meta.url)), 'utf8').replace(/^\s*\/\*[\s\S]*?\*\/\s*/, '');
const CONFIG = { key: 'fsc-consent', event: 'fsc:consent', all: ['analytics', 'marketing', 'preferences'] };
const LOADER = read('fsc-code.js').replace('__CONFIG__', JSON.stringify(CONFIG));
const CLICKS = read('fsc-clicks.js');

function node(tag, attrs = {}, text = '', children = []) {
    const el = {
        tagName: tag.toUpperCase(),
        attributes: Object.entries(attrs).map(([name, value]) => ({ name, value })),
        text,
        nonce: '',
        async: true,
        children: [],
        parentNode: null,
        fresh: false,
        getAttribute(name) { const a = el.attributes.find((x) => x.name === name); return a ? a.value : null; },
        setAttribute(name, value) { el.attributes = el.attributes.filter((x) => x.name !== name).concat([{ name, value: String(value) }]); },
        hasAttribute(name) { return el.attributes.some((x) => x.name === name); },
        replaceChild(next, old) { const i = el.children.indexOf(old); el.children[i] = next; next.parentNode = el; old.parentNode = null; },
        insertBefore(child, ref) {
            const items = child.tagName === '#FRAGMENT' ? child.children.splice(0) : [child];
            const i = el.children.indexOf(ref);
            el.children.splice(i, 0, ...items);
            items.forEach((c) => { c.parentNode = el; });
        },
        removeChild(child) { el.children = el.children.filter((c) => c !== child); child.parentNode = null; },
        querySelectorAll(selector) {
            const tags = selector.split(',').map((s) => s.trim().toUpperCase());
            const out = [];
            const walk = (n) => n.children.forEach((c) => { if (tags.includes(c.tagName)) out.push(c); walk(c); });
            walk(el);
            return out;
        },
    };
    children.forEach((c) => { c.parentNode = el; el.children.push(c); });
    return el;
}

function clone(n) {
    const c = node(n.tagName.toLowerCase(), Object.fromEntries(n.attributes.map((a) => [a.name, a.value])), n.text, n.children.map(clone));
    return c;
}

function template(consent, children) {
    const t = node('template', { 'data-fsc-code': '', 'data-consent': consent });
    t.content = node('#fragment', {}, '', children);
    return t;
}

function page({ stored = null, templates = [], nonce = 'n0nce' } = {}) {
    const body = node('body', {}, '', templates);
    const created = [];
    const timers = [];
    const listeners = {};
    const storage = { data: stored === null ? {} : { 'fsc-consent': stored }, getItem(k) { return k in this.data ? this.data[k] : null; } };
    const document = {
        readyState: 'complete',
        currentScript: { nonce, getAttribute: () => nonce },
        createElement(tag) { const el = node(tag); el.fresh = true; created.push(el); return el; },
        createComment() { return node('#comment'); },
        importNode(content) { const f = node('#fragment', {}, '', content.children.map(clone)); return f; },
        querySelectorAll(selector) { return selector === 'template[data-fsc-code]' ? body.children.filter((c) => c.tagName === 'TEMPLATE' && c.hasAttribute('data-fsc-code')) : []; },
        addEventListener() {},
    };
    const window = {
        setTimeout(fn, ms) { timers.push([fn, ms]); return timers.length; },
        localStorage: storage,
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        dispatch(type, detail) { (listeners[type] || []).forEach((fn) => fn({ detail })); },
        listeners,
    };
    const run = () => vm.runInNewContext(LOADER, { window, document });
    return { body, window, document, run, created, timers };
}

const scripts = (body) => body.children.filter((c) => c.tagName === 'SCRIPT');
/** Lets every external script that is waited for finish loading (in order), like the browser would. */
function settle(p) {
    for (let i = 0; i < 20; i++) {
        const waiting = p.created.filter((el) => el.onload && !el.loadedOnce);
        if (waiting.length === 0) return;
        waiting.forEach((el) => { el.loadedOnce = true; el.onload(); });
    }
}
const remaining = (body) => body.children.filter((c) => c.tagName === 'TEMPLATE').map((t) => t.getAttribute('data-consent'));

function standardTemplates() {
    return [
        template('analytics', [node('script', { src: 'https://www.googletagmanager.com/gtag/js', 'data-x': '1' }), node('script', { nonce: 'pasted' }, 'window.dataLayer=[];')]),
        template('marketing', [node('noscript', {}, '', [node('img', { src: 'https://px.test/p' })]), node('style', {}, 'p{}')]),
    ];
}

test('without a choice nothing runs', () => {
    const p = page({ templates: standardTemplates() });
    p.run();
    assert.deepEqual(remaining(p.body), ['analytics', 'marketing']);
    assert.equal(scripts(p.body).length, 0);
});

test('a stored "accepted" activates every category with fresh, nonced scripts in order', () => {
    const p = page({ stored: 'accepted', templates: standardTemplates() });
    p.run();
    settle(p);
    assert.deepEqual(remaining(p.body), []);
    const s = scripts(p.body);
    assert.equal(s.length, 2);
    assert.ok(s.every((x) => x.fresh), 'scripts are re-created so the browser runs them');
    assert.ok(s.every((x) => x.nonce === 'n0nce'));
    assert.equal(s[0].getAttribute('src'), 'https://www.googletagmanager.com/gtag/js');
    assert.equal(s[0].getAttribute('data-x'), '1');
    assert.equal(s[0].async, false, 'order is kept for scripts without async');
    assert.equal(s[1].getAttribute('nonce'), null, 'a pasted nonce is not copied');
    assert.equal(s[1].text, 'window.dataLayer=[];');
    assert.equal(p.body.children.find((c) => c.tagName === 'STYLE').nonce, 'n0nce');
});

test('the event activates only the accepted categories', () => {
    const p = page({ templates: standardTemplates() });
    p.run();
    p.window.dispatch('fsc:consent', { categories: ['analytics'] });
    assert.deepEqual(remaining(p.body), ['marketing']);
    p.window.dispatch('fsc:consent', { choice: 'custom', categories: ['marketing'] });
    assert.deepEqual(remaining(p.body), []);
});

test('declining, unknown categories and garbage activate nothing', () => {
    for (const detail of ['declined', 'necessary', false, { choice: 'none', categories: ['analytics'] }, { categories: ['necessary', 'foo'] }, { analytics: false }, 'garbage', null, '{broken']) {
        const p = page({ templates: standardTemplates() });
        p.run();
        p.window.dispatch('fsc:consent', detail);
        assert.deepEqual(remaining(p.body), ['analytics', 'marketing'], JSON.stringify(detail));
    }
});

test('stored JSON and object forms are understood', () => {
    const cases = [
        ['{"marketing":true,"analytics":false}', ['analytics']],
        ['{"statistics":true}', ['marketing']],
        ['["marketing"]', ['analytics']],
        ['{"choice":"all"}', []],
        ['true', []],
    ];
    for (const [stored, left] of cases) {
        const p = page({ stored, templates: standardTemplates() });
        p.run();
        assert.deepEqual(remaining(p.body), left, stored);
    }
});

test('a choice made in another tab applies through the storage event', () => {
    const p = page({ templates: standardTemplates() });
    p.run();
    p.window.listeners.storage.forEach((fn) => fn({ key: 'other', newValue: 'accepted' }));
    assert.deepEqual(remaining(p.body), ['analytics', 'marketing']);
    p.window.listeners.storage.forEach((fn) => fn({ key: 'fsc-consent', newValue: 'accepted' }));
    assert.deepEqual(remaining(p.body), []);
});

test('a second copy of the loader only scans again', () => {
    const p = page({ templates: standardTemplates() });
    p.run();
    p.run();
    assert.equal(p.window.listeners['fsc:consent'].length, 1);
    p.window.dispatch('fsc:consent', 'all');
    settle(p);
    assert.equal(scripts(p.body).length, 2, 'each template is activated once');
});

test('scripts run in source order: an inline script waits for the external one before it, across templates', () => {
    const p = page({ templates: [
        template('analytics', [node('script', { src: 'https://cdn.test/lib.js' }), node('script', {}, 'Lib.init()')]),
        template('analytics', [node('script', { src: 'https://cdn.test/async.js', async: '' }), node('script', {}, 'after()')]),
    ] });
    p.run();
    p.window.dispatch('fsc:consent', { categories: ['analytics'] });

    const texts = () => scripts(p.body).map((x) => x.getAttribute('src') || x.text);
    assert.deepEqual(texts(), ['https://cdn.test/lib.js'], 'Lib.init() waits for lib.js');
    assert.equal(p.body.children.filter((c) => c.tagName === '#COMMENT').length, 3, 'the others keep their place');

    p.created[0].onload();
    assert.deepEqual(texts(), ['https://cdn.test/lib.js', 'Lib.init()', 'https://cdn.test/async.js', 'after()'], 'an async script is not waited for');
    assert.equal(p.body.children.filter((c) => c.tagName === '#COMMENT').length, 0);
});

test('a failing or hanging external script does not block the rest', () => {
    const p = page({ stored: 'all', templates: [template('marketing', [node('script', { src: 'https://blocked.test/a.js' }), node('script', { src: 'https://slow.test/b.js' }), node('script', {}, 'c()')])] });
    p.run();
    p.created[0].onerror();
    assert.equal(scripts(p.body).length, 2);
    const [fallback, ms] = p.timers[1];
    assert.equal(ms, 15000);
    fallback();
    assert.deepEqual(scripts(p.body).map((x) => x.getAttribute('src') || x.text), ['https://blocked.test/a.js', 'https://slow.test/b.js', 'c()']);
    p.created[1].onload();
    assert.equal(scripts(p.body).length, 3, 'a late load after the fallback runs nothing twice');
});

test('click events: tel, mailto and WhatsApp without query; nothing for other links; safe without Umami', () => {
    const tracked = [];
    let handler = null;
    let options = null;
    const document = { addEventListener(type, fn, opts) { if (type === 'click') { handler = fn; options = opts; } } };
    const window = {};
    vm.runInNewContext(CLICKS, { window, document });
    vm.runInNewContext(CLICKS, { window, document });
    assert.equal(options.capture, true);

    const click = (href) => handler({ target: { closest: () => (href === null ? null : { getAttribute: () => href }) } });

    click('tel:+31701234567');
    window.umami = { track: (name, data) => tracked.push([name, data]) };
    click('tel:+31701234567');
    click('mailto:info@jansen.nl?subject=Offerte&body=Hoi');
    click('https://wa.me/31612345678?text=Hallo');
    click('https://api.whatsapp.com/send?phone=316');
    click('https://jansen.nl/contact');
    click(null);
    window.umami = { track: () => { throw new Error('blocked'); } };
    click('tel:1');

    assert.deepEqual(JSON.parse(JSON.stringify(tracked)), [
        ['contact-click', { kind: 'tel', href: 'tel:+31701234567' }],
        ['contact-click', { kind: 'mailto', href: 'mailto:info@jansen.nl' }],
        ['contact-click', { kind: 'whatsapp', href: 'https://wa.me/31612345678' }],
        ['contact-click', { kind: 'whatsapp', href: 'https://api.whatsapp.com/send' }],
    ]);
});

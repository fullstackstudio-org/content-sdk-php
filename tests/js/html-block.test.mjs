// Tests of the page builder's `html` block scripts: resources/assets/html-block.js (the parent's frame sizer), html-block-frame.js (the height
// reporter inside the sandboxed frame) and the consent gate (fsc-code.js removes the placeholder when it activates a template). Minimal fake
// DOM, no dependencies. Run: `bun test tests/js` or `node --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const read = (file) => readFileSync(fileURLToPath(new URL('../../resources/assets/' + file, import.meta.url)), 'utf8').replace(/^\s*\/\*[\s\S]*?\*\/\s*/, '');
const SIZER = read('html-block.js');
const REPORTER = read('html-block-frame.js');
const LOADER = read('fsc-code.js').replace('__CONFIG__', JSON.stringify({ key: 'fsc-consent', event: 'fsc:consent', all: ['analytics', 'marketing', 'preferences'] }));

// --- the parent's frame sizer -------------------------------------------------------------------------------------------------

function sizerPage(frameCount = 2) {
    const frames = Array.from({ length: frameCount }, (_, i) => ({ contentWindow: { id: 'frame' + i }, style: {} }));
    const listeners = [];
    const timers = [];
    const clock = { now: 1_000_000 };
    const window = {
        addEventListener: (type, fn) => { if (type === 'message') listeners.push(fn); },
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
    };
    const document = { querySelectorAll: (selector) => (selector === 'iframe[data-fsc-html-frame]' ? frames : []) };
    const run = () => vm.runInNewContext(SIZER, { window, document, isFinite, Math, Date: { now: () => clock.now } });
    const post = (data, from) => listeners.forEach((fn) => fn({ data, origin: 'null', source: frames[0].contentWindow, ...from }));
    const fireTimers = () => timers.splice(0).forEach((t) => t.fn());
    return { frames, window, run, post, listeners, clock, timers, fireTimers };
}

const height = (h) => ({ ns: 'fsc-html', v: 1, type: 'height', height: h });

test('the sizer resizes only the frame whose contentWindow sent the message', () => {
    const p = sizerPage();
    p.run();
    p.post(height(321.2), { source: p.frames[1].contentWindow });
    assert.equal(p.frames[1].style.height, '322px');
    assert.equal(p.frames[0].style.height, undefined);
});

test('the sizer ignores other origins, unknown sources and malformed messages', () => {
    const p = sizerPage();
    p.run();
    p.post(height(400), { origin: 'https://evil.test' });
    p.post(height(400), { origin: 'http://localhost' });
    p.post(height(400), { source: { id: 'some other window' } });
    p.post(height(400), { source: null });
    for (const bad of [null, 'height:400', { ...height(400), ns: 'other' }, { ...height(400), v: 2 }, { ...height(400), type: 'resize' }, height('400'), height(-1), height(NaN), height(Infinity)]) {
        p.post(bad);
    }
    assert.deepEqual(p.frames.map((f) => f.style.height), [undefined, undefined]);
});

test('the sizer caps the height and installs itself once', () => {
    const p = sizerPage(1);
    p.run();
    p.run();
    assert.equal(p.listeners.length, 1, 'a second copy does nothing');
    p.post(height(999999));
    assert.equal(p.frames[0].style.height, '20000px');
});

test('damping: growth by the same delta stops after a few repeats, shrinking always applies', () => {
    const p = sizerPage(1);
    p.run();
    p.post(height(300));
    // A feedback loop: the content is always 20 px taller than its frame.
    for (let h = 320; h <= 520; h += 20) { p.clock.now += 50; p.post(height(h)); }
    const stopped = parseInt(p.frames[0].style.height, 10);
    assert.ok(stopped < 520 && stopped >= 380, 'the loop is cut off after a few identical steps: ' + stopped);
    p.post(height(200));
    assert.equal(p.frames[0].style.height, '200px', 'shrinking applies and resets the damping');
    p.post(height(260));
    assert.equal(p.frames[0].style.height, '260px');
});

test('damping: the frame grows at most 4000 px per second, the rest follows a second later', () => {
    const p = sizerPage(1);
    p.run();
    p.post(height(500));
    assert.equal(p.frames[0].style.height, '500px', 'the first height applies at once, whatever its size');
    p.clock.now += 100;
    p.post(height(9000));
    assert.equal(p.frames[0].style.height, '4500px');
    assert.equal(p.timers.length, 1);
    assert.equal(p.timers[0].ms, 1000);
    p.clock.now += 1000;
    p.fireTimers();
    assert.equal(p.frames[0].style.height, '8500px');
    p.clock.now += 1000;
    p.fireTimers();
    assert.equal(p.frames[0].style.height, '9000px');
});

// --- the reporter inside the frame ------------------------------------------------------------------------------------------------

function framePage({ framed = true, contentHeight = 240 } = {}) {
    const sent = [];
    const listeners = {};
    const observed = [];
    let callback = null;
    const root = { getBoundingClientRect: () => ({ height: contentHeight }) };
    const body = { scrollHeight: 0 };
    const window = {
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        ResizeObserver: class { constructor(fn) { callback = fn; } observe(el) { observed.push(el); } },
    };
    window.parent = framed ? { postMessage: (message, target) => sent.push({ message, target }) } : window;
    const document = { documentElement: root, body, addEventListener: (type, fn) => { (listeners['doc:' + type] ||= []).push(fn); } };
    vm.runInNewContext(REPORTER, { window, document, Math });
    return { sent, root, body, observed, grow: (h) => { contentHeight = h; callback && callback(); }, listeners, set: (h) => { contentHeight = h; } };
}

test('the reporter posts the content height to the parent, only when it changes', () => {
    const p = framePage({ contentHeight: 240.4 });
    assert.deepEqual(JSON.parse(JSON.stringify(p.sent)), [{ message: { ns: 'fsc-html', v: 1, type: 'height', height: 241 }, target: '*' }]);
    p.grow(241);
    assert.equal(p.sent.length, 1, 'same height: no message');
    p.grow(500);
    assert.equal(p.sent.at(-1).message.height, 500);
    p.body.scrollHeight = 650;
    p.listeners.load.forEach((fn) => fn());
    assert.equal(p.sent.at(-1).message.height, 650, 'overflowing content counts too');
    assert.ok(p.observed.length >= 1);
});

test('the reporter does nothing without a parent', () => {
    const p = framePage({ framed: false });
    assert.deepEqual(p.sent, []);
});

// --- the consent gate -------------------------------------------------------------------------------------------------------------

function node(tag, attrs = {}, children = []) {
    const el = {
        tagName: tag.toUpperCase(),
        attributes: Object.entries(attrs).map(([name, value]) => ({ name, value })),
        children: [],
        parentNode: null,
        getAttribute(name) { const a = el.attributes.find((x) => x.name === name); return a ? a.value : null; },
        hasAttribute(name) { return el.attributes.some((x) => x.name === name); },
        get previousElementSibling() { const list = el.parentNode ? el.parentNode.children : []; return list[list.indexOf(el) - 1] || null; },
        insertBefore(child, ref) {
            const items = child.tagName === '#FRAGMENT' ? child.children.splice(0) : [child];
            el.children.splice(el.children.indexOf(ref), 0, ...items);
            items.forEach((c) => { c.parentNode = el; });
        },
        removeChild(child) { el.children = el.children.filter((c) => c !== child); child.parentNode = null; },
        querySelectorAll() { return []; },
    };
    children.forEach((c) => { c.parentNode = el; el.children.push(c); });
    return el;
}

function gatedBlock(consent) {
    const placeholder = node('div', { class: 'fsc-html__placeholder', 'data-fsc-code-placeholder': '' });
    const template = node('template', { 'data-fsc-code': '', 'data-consent': consent });
    template.content = node('#fragment', {}, [node('iframe', { 'data-fsc-html-frame': '', sandbox: 'allow-scripts allow-popups allow-forms' })]);
    return node('div', { class: 'fsc-block fsc-block--html' }, [placeholder, template]);
}

function gatePage(blocks, stored = null) {
    const listeners = {};
    const templates = () => blocks.flatMap((b) => b.children.filter((c) => c.tagName === 'TEMPLATE'));
    const document = {
        readyState: 'complete',
        currentScript: { nonce: 'n0nce', getAttribute: () => 'n0nce' },
        importNode: (content) => node('#fragment', {}, content.children.map((c) => node(c.tagName.toLowerCase(), Object.fromEntries(c.attributes.map((a) => [a.name, a.value]))))),
        querySelectorAll: (selector) => (selector === 'template[data-fsc-code]' ? templates() : []),
        addEventListener() {},
        createElement: (tag) => node(tag),
        createComment: () => node('#comment'),
    };
    const window = {
        localStorage: { getItem: (k) => (k === 'fsc-consent' ? stored : null) },
        setTimeout() {},
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        dispatch: (type, detail) => (listeners[type] || []).forEach((fn) => fn({ detail })),
    };
    vm.runInNewContext(LOADER, { window, document });
    return window;
}

const kinds = (block) => block.children.map((c) => c.tagName + (c.hasAttribute('data-fsc-code-placeholder') ? ':placeholder' : ''));

test('a gated html block keeps its placeholder and stays inert until that category is accepted', () => {
    const marketing = gatedBlock('marketing');
    const analytics = gatedBlock('analytics');
    const window = gatePage([marketing, analytics]);
    assert.deepEqual(kinds(marketing), ['DIV:placeholder', 'TEMPLATE']);

    window.dispatch('fsc:consent', { categories: ['analytics'] });
    assert.deepEqual(kinds(analytics), ['IFRAME'], 'the frame is in, the placeholder gone');
    assert.deepEqual(kinds(marketing), ['DIV:placeholder', 'TEMPLATE'], 'another category stays gated');

    window.dispatch('fsc:consent', 'accepted');
    assert.deepEqual(kinds(marketing), ['IFRAME']);
    assert.equal(marketing.children[0].getAttribute('sandbox'), 'allow-scripts allow-popups allow-forms');
});

test('a stored choice activates a gated block at once, and declining keeps it gated', () => {
    const block = gatedBlock('preferences');
    gatePage([block], JSON.stringify({ preferences: true }));
    assert.deepEqual(kinds(block), ['IFRAME']);

    const declined = gatedBlock('marketing');
    gatePage([declined], 'declined');
    assert.deepEqual(kinds(declined), ['DIV:placeholder', 'TEMPLATE']);
});

test('only a placeholder right before the template is removed', () => {
    const other = node('p', { class: 'site-text' });
    const template = node('template', { 'data-fsc-code': '', 'data-consent': 'marketing' });
    template.content = node('#fragment', {}, [node('div', { id: 'snippet' })]);
    const block = node('div', {}, [other, template]);
    gatePage([block], 'accepted');
    assert.deepEqual(block.children.map((c) => c.tagName), ['P', 'DIV'], 'a site snippet without placeholder keeps its neighbours');
});

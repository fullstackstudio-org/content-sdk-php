// Tests of resources/assets/fsa-overlay.js (the heatmap overlay) with a minimal fake DOM (no dependencies).
// Run: `bun test tests/js` (or `node --test tests/js/fsa-overlay.test.mjs`).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const SOURCE = readFileSync(fileURLToPath(new URL('../../resources/assets/fsa-overlay.js', import.meta.url)), 'utf8');
const TOKEN = 'v1.eyJwIjoiYWJjIn0abcdefgh.sigsigsigsigsigsigsigsigsigsigsigsigsigsig';

function node(tag) {
    const n = {
        tagName: tag.toUpperCase(),
        style: {},
        attrs: {},
        children: [],
        parentNode: null,
        listeners: {},
        textContent: '',
        get firstChild() { return n.children[0] || null; },
        appendChild(child) { child.parentNode = n; n.children.push(child); return child; },
        removeChild(child) { n.children.splice(n.children.indexOf(child), 1); child.parentNode = null; return child; },
        setAttribute(name, value) { n.attrs[name] = String(value); },
        addEventListener(type, fn) { (n.listeners[type] ||= []).push(fn); },
        getContext: () => ({ scale() {}, createRadialGradient: () => ({ addColorStop() {} }), fillRect() { n.fills = (n.fills || 0) + 1; }, set fillStyle(v) {} }),
    };
    Object.defineProperty(n, 'innerHTML', { set() { throw new Error('innerHTML is not allowed'); } });
    return n;
}

function text(n) {
    return [n.textContent, ...n.children.map(text)].join(' ');
}

function all(n, tag) {
    return [...(n.tagName === tag ? [n] : []), ...n.children.flatMap((c) => all(c, tag))];
}

async function setup({ hash = `#fsa_overlay=${TOKEN}`, status = 200, result, width = 1280, path = '/prijzen', handed } = {}) {
    const body = node('body');
    const elements = {
        'button#offerte': { getBoundingClientRect: () => ({ left: 100, top: 50, width: 200, height: 40 }) },
    };
    const doc = {
        readyState: 'complete',
        currentScript: { src: 'https://studio.example/_fsc/a/overlay.js' },
        body,
        documentElement: { scrollWidth: 1280, scrollHeight: 3000, clientWidth: 1280, clientHeight: 800 },
        createElement: (tag) => node(tag),
        querySelector: (sel) => { if (sel.includes('(')) throw new Error('bad selector'); return elements[sel] || null; },
        addEventListener() {},
    };
    const replaced = [];
    const fetched = [];
    const win = {
        location: { hash, pathname: path, search: '?a=1' },
        history: { state: null, replaceState: (s, t, url) => replaced.push(url) },
        innerWidth: width,
        scrollX: 0,
        scrollY: 0,
        devicePixelRatio: 1,
        setTimeout: () => 0,
        clearTimeout() {},
        addEventListener() {},
        removeEventListener() {},
        __fsaOverlayToken: handed,
        fetch: (url, init) => {
            fetched.push({ url, init });
            return Promise.resolve({ status, json: () => Promise.resolve(status === 200 ? { result } : { error: 'forbidden' }) });
        },
    };
    new Function('window', 'document', SOURCE)(win, doc);
    await new Promise((r) => setTimeout(r, 0));
    await new Promise((r) => setTimeout(r, 0));
    return { body, replaced, fetched, win };
}

const RESULT = {
    path: '/prijzen', viewport: 'desktop', range: '30d', grid: 10, clicks: 9, consentShare: 0.5,
    elements: [
        { selector: 'button#offerte', clicks: 4, rage: 1, dead: 0, cells: [[1, 5, 2], [8, 4, 1]] },
        { selector: 'div.gone', clicks: 2, rage: 0, dead: 2, cells: [[2, 2, 2]] },
    ],
    scroll: [{ depth: 0, reach: 1 }, { depth: 20, reach: 0.75 }, { depth: 40, reach: 0.5 }],
};

test('removes the token from the address bar and posts it to the site itself, in the body', async () => {
    const { replaced, fetched } = await setup({ hash: `#top&fsa_overlay=${TOKEN}`, result: RESULT });
    assert.deepEqual(replaced, ['/prijzen?a=1#top']);
    assert.equal(fetched.length, 1);
    assert.equal(fetched[0].url, 'https://studio.example/_fsc/a/overlay');
    assert.equal(fetched[0].init.method, 'POST');
    assert.equal(fetched[0].init.credentials, 'omit');
    assert.deepEqual(JSON.parse(fetched[0].init.body), { t: TOKEN });
    assert.ok(!fetched[0].url.includes(TOKEN), 'never in a URL');
});

test('draws heat over the current box of each element found and lists the elements that are gone', async () => {
    const { body } = await setup({ result: RESULT });
    const canvases = all(body, 'CANVAS');
    assert.equal(canvases.length, 1);
    assert.equal(canvases[0].style.left, '100px');
    assert.equal(canvases[0].style.top, '50px');
    assert.equal(canvases[0].style.width, '200px');
    assert.equal(canvases[0].fills, 2, 'one blob per cell');
    const all_ = text(body);
    assert.match(all_, /#1 · 4 · 1 rage/);
    assert.match(all_, /Niet \(meer\) op de pagina:/);
    assert.match(all_, /div\.gone · 2/);
    assert.match(all_, /50% van de weergaven/);
    assert.match(all_, /75% van de weergaven komt hier/);
    // Overlay layer never takes clicks; only the panel does.
    const root = body.children[0];
    assert.equal(root.style.pointerEvents, 'none');
});

test('warns when the window does not match the viewport class or the page', async () => {
    const { body } = await setup({ result: { ...RESULT, viewport: 'mobile' }, width: 1280, path: '/anders' });
    const all_ = text(body);
    assert.match(all_, /Dit venster is 1280 px breed/);
    assert.match(all_, /hoort bij \/prijzen, niet bij deze pagina/);
});

test('says so when the token is refused or the answer is unusable', async () => {
    let { body } = await setup({ status: 403 });
    assert.match(text(body), /verlopen of hoort niet bij deze site/);
    ({ body } = await setup({ status: 502 }));
    assert.match(text(body), /kon niet geladen worden/);
    assert.equal(all(body, 'CANVAS').length, 0);
});

test('does nothing without a token in the fragment', async () => {
    const { fetched, body } = await setup({ hash: '#fsa_overlay=kort', result: RESULT });
    assert.equal(fetched.length, 0);
    assert.equal(body.children.length, 0);
});

test('takes the token fsa.js handed over in memory, and forgets it', async () => {
    const { fetched, replaced, win } = await setup({ hash: '', handed: TOKEN, result: RESULT });
    assert.deepEqual(JSON.parse(fetched[0].init.body), { t: TOKEN });
    assert.deepEqual(replaced, [], 'the URL was already clean');
    assert.equal(win.__fsaOverlayToken, undefined);
    assert.equal(win.fsaOverlayMode, true);
});

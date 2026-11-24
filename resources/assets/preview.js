/*
 * Portal preview helper of the FullStack Hub SDK, served at /_fsc/p/preview.js. The server inserts the tag right after <head> ONLY into a preview
 * response (POST /_fsc/p with a valid preview token, see src/Laravel/Preview/PreviewRequest.php), with its config as data-* attributes:
 * endpoint, token, origin (the exact portal origin allowed to frame the page), version, sdk; in the Hub fallback (an opaque origin) also
 * project, sent as a form field with every request (the refresh fetch adds refresh=1).
 *
 * - Marks the page at once: html[data-fsc-preview] (a cookie banner hides itself on it) and window.fscPreviewMode = true.
 * - Blocks every form submit (the SDK forms are disabled server-side as well) and, when framed, every link click (a menu link would move the
 *   frame to the live site, and the preview token does not travel with it).
 * - Talks to the portal with postMessage ({ns: 'fsc-preview', v: 1, type, ...}), exact origin and source checks on every message:
 *     to the portal:   ready {version, sdk, proto: 2, caps}, rendered {version}, select {blockId, index}, error {code, retryAfter?}
 *     from the portal: refresh {version}, highlight {blockId}, consent {categories}
 *   `consent` grants consent categories (analytics, marketing, preferences) inside this frame only, through the consent loader
 *   (window.fscCode.grant), so consent gated `html` blocks and snippets can be seen while the cookie banner is hidden. Nothing is stored:
 *   the portal sends it again after every `ready`.
 *   No content travels over postMessage: on refresh the page asks its own server again (POST with the token) and swaps the
 *   [data-fsc-preview-blocks] roots when their count matches (scroll kept), else it reloads with a form POST (scroll restored).
 * - Protocol 2 (page builder; plan page-builder-v2, AD6, AD7, AD10 and its message table) acts only after a valid `init {proto: 2}`.
 *   Rich text (Task 16, AD9) is typed on the page only when `init` also says `rich: true`: `contenteditable` on `data-fsc-edit-kind="rich"`,
 *   `edit` carries the field's innerHTML (untrusted until the Hub sanitized it), `edit-start`/`edit` and `edit-state {blockId, field, state}`
 *   report the active formats for the portal's format bar, `format {command, value?, blockId?, field?}` applies bold, italic, link, unlink,
 *   h2, h3, p, ul, ol, and Cmd/Ctrl+K asks the portal for an address (`shortcut link`). Pasted markup is reduced to those tags (no attribute
 *   but a checked `href`) before it is inserted; nothing pasted ever runs.
 *   F6 and Shift+F6 pressed in the site are forwarded as `shortcut {name: 'pane-next'}` / `'pane-prev'` so the portal can move the focus on.
 * - Works without a parent or opener (opened in its own window): no messages, the rest still applies.
 */
(function () {
    var w = window;
    var d = document;
    if (w.__fscPreview) { return; }
    w.__fscPreview = true;

    var html = d.documentElement;
    html.setAttribute('data-fsc-preview', '');
    w.fscPreviewMode = true;

    var NS = 'fsc-preview';
    var V = 1;
    var SCROLL = 'fsc-preview-scroll';
    var ID = /^[A-Za-z0-9_-]{1,40}$/;
    var FIELD = /^[A-Za-z0-9_.-]{1,80}$/;
    var FORMATS = ['bold', 'italic', 'link', 'unlink', 'h2', 'h3', 'p', 'ul', 'ol'];
    var own = function (o, k) { return Object.prototype.hasOwnProperty.call(o, k); };
    var tag = d.currentScript;
    var attr = function (name) { return tag ? tag.getAttribute('data-' + name) || '' : ''; };
    var cfg = {
        endpoint: attr('endpoint') || '/_fsc/p',
        token: attr('token'),
        origin: attr('origin'),
        version: parseInt(attr('version'), 10) || 0,
        sdk: attr('sdk'),
        project: FIELD.test(attr('project')) ? attr('project') : '',
        nonce: tag ? tag.nonce || '' : '',
    };
    // The token stays in this closure only, out of the DOM.
    if (tag) { tag.removeAttribute('data-token'); }

    var framed = w.parent && w.parent !== w;
    var target = framed ? w.parent : (w.opener || null);

    function post(type, extra) {
        if (!target || !cfg.origin) { return; }
        var message = { ns: NS, v: V, type: type };
        for (var key in extra) { if (own(extra, key)) { message[key] = extra[key]; } }
        try { target.postMessage(message, cfg.origin); } catch (error) { /* the portal went away */ }
    }

    function closest(node, test) {
        while (node && node !== d) {
            if (node.nodeType === 1 && test(node)) { return node; }
            node = node.parentNode;
        }
        return null;
    }

    var isBlock = function (node) { return node.getAttribute('data-fsc-block') !== null; };
    var isLink = function (node) { return String(node.tagName).toLowerCase() === 'a'; };
    var isEdit = function (node) { return node.getAttribute('data-fsc-edit') !== null; };
    var list = function (nodes) { var out = []; for (var i = 0; i < (nodes || []).length; i++) { out.push(nodes[i]); } return out; };
    var now = function () { return typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now(); };

    // Nothing is ever submitted from a preview.
    d.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);

    d.addEventListener('click', function (event) {
        if (ui && event.target === ui.host) { return; }
        if (framed && closest(event.target, isLink)) { event.preventDefault(); }
        var block = closest(event.target, isBlock);
        if (!block) { return; }
        if (editor) { editorClick(event, block); return; }
        post('select', { blockId: block.getAttribute('data-fsc-block'), index: parseInt(block.getAttribute('data-fsc-block-index'), 10) || 0 });
    }, true);

    var lit = [];

    function highlight(blockId) {
        lit.forEach(function (entry) { entry[0].style.outline = entry[1]; entry[0].style.outlineOffset = entry[2]; });
        lit = [];
        var found = blockById(blockId);
        if (!found) { return; }
        // The wrapper is display:contents (no box of its own), so its children carry the outline and the scroll.
        var children = found.children || [];
        for (var j = 0; j < children.length; j++) {
            var el = children[j];
            lit.push([el, el.style.outline, el.style.outlineOffset]);
            el.style.outline = '2px solid #2563eb';
            el.style.outlineOffset = '2px';
        }
        if (children[0] && children[0].scrollIntoView) { children[0].scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    function wrappers() { return list(d.querySelectorAll('[data-fsc-block]')); }

    function blockById(blockId) {
        var all = wrappers();
        for (var i = 0; i < all.length; i++) { if (all[i].getAttribute('data-fsc-block') === String(blockId)) { return all[i]; } }
        return null;
    }

    function fields(refresh) {
        var body = new URLSearchParams();
        body.set('token', cfg.token);
        if (cfg.project) { body.set('project', cfg.project); if (refresh) { body.set('refresh', '1'); } }
        return body;
    }

    function reload() {
        // Never under the caret: a reload waits for blur or 2 s without input (AD7 rule 5).
        if (editing) { reloadWaiting = true; armIdle(); return; }
        var scroll = JSON.stringify({ x: w.scrollX || 0, y: w.scrollY || 0 });
        try { sessionStorage.setItem(SCROLL, scroll); } catch (error) {
            // An opaque origin (the Hub fallback) has no storage; the window name survives a reload of the frame.
            try { w.name = SCROLL + ':' + scroll; } catch (ignored) { /* nowhere to keep it */ }
        }
        var form = d.createElement('form');
        form.method = 'POST';
        form.action = cfg.endpoint;
        form.target = '_self';
        form.style.display = 'none';
        fields(false).forEach(function (value, name) {
            var input = d.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        (d.body || html).appendChild(form);
        // form.submit() fires no submit event, so the guard above does not stop it.
        form.submit();
    }

    function restoreScroll() {
        var saved = null;
        try {
            saved = JSON.parse(sessionStorage.getItem(SCROLL) || 'null');
            sessionStorage.removeItem(SCROLL);
        } catch (error) { saved = null; }
        try {
            if (String(w.name).indexOf(SCROLL + ':') === 0) { saved = saved || JSON.parse(w.name.slice(SCROLL.length + 1)); w.name = ''; }
        } catch (error) { /* a foreign window name */ }
        if (saved && typeof saved.y === 'number' && w.scrollTo) { w.scrollTo(saved.x || 0, saved.y); }
    }

    function versionOf(doc) {
        var script = doc.querySelector('script[data-endpoint][data-version]');
        var value = script ? parseInt(script.getAttribute('data-version'), 10) : NaN;
        return isNaN(value) ? null : value;
    }

    var sequence = 0;
    var granted = null;
    var CATEGORIES = ['analytics', 'marketing', 'preferences'];

    // Swapped-in markup cannot start scripts of its own: a block that needs the consent loader or the frame sizer of the `html` block while
    // the page has none yet is shown through a full reload instead.
    function needsRuntime(roots) {
        for (var i = 0; i < roots.length; i++) {
            if (!w.fscCode && roots[i].querySelector('template[data-fsc-code]')) { return true; }
            if (!w.fscHtmlBlock && roots[i].querySelector('iframe[data-fsc-html-frame]')) { return true; }
        }
        return false;
    }

    function consent(categories) {
        if (!Array.isArray(categories)) { return; }
        var list = [];
        for (var i = 0; i < categories.length; i++) {
            if (CATEGORIES.indexOf(categories[i]) !== -1 && list.indexOf(categories[i]) === -1) { list.push(categories[i]); }
        }
        granted = list;
        if (w.fscCode && list.length) { w.fscCode.grant({ categories: list }); }
    }

    /** Sum of the `fsc-*` durations of the Server-Timing header in ms, or null (none, or not readable cross-origin). */
    function serverTiming(response) {
        var total = null;
        String((response.headers && response.headers.get && response.headers.get('Server-Timing')) || '').split(',').forEach(function (entry) {
            var match = /^\s*fsc-[a-z-]+\s*;(?:.*;)?\s*dur=([0-9.]+)/i.exec(entry);
            if (match) { total = (total || 0) + parseFloat(match[1]); }
        });
        return total === null ? null : Math.round(total);
    }

    function refresh(version, ack) {
        var mine = ++sequence;
        var started = now();
        var server = null;
        fetch(cfg.endpoint, { method: 'POST', body: fields(true), credentials: 'omit', cache: 'no-store', redirect: 'error' }).then(function (response) {
            if (!response.ok) {
                var failure = new Error(String(response.status));
                var retry = response.headers && response.headers.get ? parseInt(response.headers.get('Retry-After') || '', 10) : NaN;
                failure.retryAfter = isNaN(retry) ? 60 : retry;
                throw failure;
            }
            if (editor) { server = serverTiming(response); }
            return response.text();
        }).then(function (text) {
            if (mine !== sequence) { return; }
            var fetched = now();
            var doc = new DOMParser().parseFromString(text, 'text/html');
            var fresh = doc.querySelectorAll('[data-fsc-preview-blocks]');
            var old = d.querySelectorAll('[data-fsc-preview-blocks]');
            if (!old.length || fresh.length !== old.length || needsRuntime(fresh)) { reload(); return; }
            var x = w.scrollX || 0;
            var y = w.scrollY || 0;
            for (var i = 0; i < old.length; i++) {
                if (editor) { swapRoot(old[i], fresh[i], ack); } else { old[i].parentNode.replaceChild(d.importNode(fresh[i], true), old[i]); }
            }
            // Swapped-in scripts do not run: let the consent loader pick up new templates (with the consent granted so far).
            if (w.fscCode) { if (granted) { w.fscCode.grant({ categories: granted }); } w.fscCode.scan(); }
            if (doc.title) { d.title = doc.title; }
            if (w.scrollTo) { w.scrollTo(x, y); }
            var next = versionOf(doc);
            cfg.version = next !== null ? next : (version || cfg.version);
            if (!editor) { post('rendered', { version: cfg.version }); return; }
            post('rendered', { version: cfg.version, timing: { fetch: Math.round(fetched - started), server: server, swap: Math.round(now() - fetched) } });
            changed();
        }).catch(function (error) {
            if (mine !== sequence) { return; }
            var status = error && error.message;
            if (status === '429') {
                post('error', { code: 'rate_limited', retryAfter: error.retryAfter || 60 });
                return;
            }
            post('error', { code: status === '404' ? 'expired' : 'refresh_failed' });
        });
    }

    /* Protocol 2: nothing below acts before a valid `init`. */

    var editor = null; // {inline, rich, labels, scale}
    var ui = null;
    var editing = null; // the field with the caret: {el, id, field, kind, sent, timer, last, composing, state}
    var lastCaret = null; // the rich field edited last and its selection as text offsets: a `format` after a blur (the link popover) resumes there
    var needAck = {}; // blockId -> last seq sent; swapped only from a refresh with ack >= it
    var held = {}; // blockId -> {node, ack}: fresh markup for the block with the caret, applied after blur
    var selectedId = null, hoveredId = null, insertAt = null, mouse = null, lastPointer = 'mouse', seqNo = 0, reloadWaiting = false, moving = false;
    var idleTimer, lastInput, drawQueued, blocksTimer, hoverTimer, hoverSent, observer, canvas;
    var raf = w.requestAnimationFrame ? function (fn) { w.requestAnimationFrame(fn); } : function (fn) { setTimeout(fn, 16); };

    // Style guide in the portal's page-builder.css. `@n` is n px divided by the portal's zoom (--z): the same size on screen at any zoom.
    var FONT = ' system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
    var CSS = ('.f{position:absolute;box-sizing:border-box;display:none;pointer-events:none;border:@2 solid #1a5fe0}'
        + '.h{border:@1 solid rgba(26,95,224,.55)}'
        + '.c{position:absolute;left:@-2;bottom:100%;box-sizing:border-box;height:@22;max-width:@320;padding:0 @8;overflow:hidden;'
        + 'text-overflow:ellipsis;white-space:nowrap;border-radius:@4 @4 0 0;background:#1a5fe0;color:#fff;font:600 @12/@22' + FONT + ';cursor:pointer;pointer-events:auto}'
        + '.in>.c{bottom:auto;top:0;left:0;border-radius:0 0 @4 0}.h>.c{left:@-1;background:#e8effc;color:#1a5fe0;pointer-events:none}.h.in>.c{left:0}'
        + '.i{position:absolute;display:none;height:@2;margin-top:@-1;background:#1a5fe0}'
        + '.p{position:absolute;left:50%;top:50%;width:@24;height:@24;margin:@-12 0 0 @-12;padding:0;border:0;border-radius:50%;background:#1a5fe0;'
        + 'color:#fff;font:600 @18/@24' + FONT + ';box-shadow:0 1px 3px rgba(0,0,0,.25);cursor:pointer;pointer-events:auto}'
        + '.p:focus-visible{outline:@2 solid #fff;outline-offset:@-4}').replace(/@(-?\d+)/g, 'calc($1px / var(--z))');
    var PAGE_CSS = '[data-fsc-edit-placeholder]:empty::before{content:attr(data-fsc-edit-placeholder);opacity:.45}'
        + '[data-fsc-edit-kind]{cursor:text}[data-fsc-edit][contenteditable]{outline:1px dashed rgba(26,95,224,.6);outline-offset:2px}';

    /** A constructed stylesheet where possible (not subject to a CSP style-src), else a <style> with the script's nonce. */
    function sheet(scope, css, parent) {
        try {
            if (typeof CSSStyleSheet === 'function' && 'adoptedStyleSheets' in scope) {
                var constructed = new CSSStyleSheet();
                constructed.replaceSync(css);
                scope.adoptedStyleSheets = scope.adoptedStyleSheets.concat([constructed]);
                return;
            }
        } catch (error) { /* a style element then */ }
        var style = d.createElement('style');
        if (cfg.nonce) { style.setAttribute('nonce', cfg.nonce); }
        style.textContent = css;
        parent.appendChild(style);
    }

    function buildUi() {
        var host = d.createElement('fsc-preview-ui');
        if (!host.attachShadow) { return null; }
        var root = host.attachShadow({ mode: 'closed' });
        'position:absolute;top:0;left:0;width:0;height:0;margin:0;padding:0;border:0;display:block;overflow:visible;z-index:2147483647;pointer-events:none;transform:none'
            .split(';').forEach(function (rule) { var pair = rule.split(':'); host.style.setProperty(pair[0], pair[1], 'important'); });
        sheet(root, CSS, root);
        var make = function (name, parent, kind) { var node = d.createElement(kind || 'div'); node.className = name; parent.appendChild(node); return node; };
        var parts = { host: host, sel: make('f', root), hov: make('f h', root), line: make('i', root) };
        parts.selChip = make('c', parts.sel);
        parts.hovChip = make('c', parts.hov);
        parts.plus = make('p', parts.line, 'button');
        parts.plus.type = 'button';
        parts.plus.textContent = '+';
        var on = function (node, fn) {
            node.addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); fn(); });
        };
        on(parts.selChip, function () { if (selectedId !== null) { postSelect(selectedId); } });
        on(parts.plus, function () { if (insertAt !== null) { post('insert', { index: insertAt }); } });
        parts.plus.addEventListener('mousedown', function (event) { event.preventDefault(); });
        html.appendChild(host);
        sheet(d, PAGE_CSS, d.head || html);
        return parts;
    }

    /** Union of the client rects of a wrapper's children (the wrapper is display:contents), or null when nothing has a box. */
    function boxOf(node, depth) {
        var box = null;
        list(node && node.children).forEach(function (child) {
            if (!child.getBoundingClientRect || (ui && child === ui.host)) { return; }
            var r = child.getBoundingClientRect();
            r = r.width || r.height ? { left: r.left, top: r.top, right: r.left + r.width, bottom: r.top + r.height } : ((depth || 0) < 2 ? boxOf(child, (depth || 0) + 1) : null);
            if (r) { box = box ? { left: Math.min(box.left, r.left), top: Math.min(box.top, r.top), right: Math.max(box.right, r.right), bottom: Math.max(box.bottom, r.bottom) } : r; }
        });
        return box;
    }

    function queueDraw() {
        if (!ui || drawQueued) { return; }
        drawQueued = true;
        raf(function () { drawQueued = false; draw(); });
    }

    function draw() {
        if (!ui) { return; }
        var origin = ui.host.getBoundingClientRect();
        frame(ui.sel, ui.selChip, selectedId, origin);
        frame(ui.hov, ui.hovChip, hoveredId !== selectedId ? hoveredId : null, origin);
        drawInsert(origin);
    }

    function place(style, box, origin) {
        style.left = (box.left - origin.left) + 'px';
        style.top = (box.top - origin.top) + 'px';
        style.width = Math.max(0, box.right - box.left) + 'px';
    }

    function frame(node, chip, id, origin) {
        var box = id !== null ? boxOf(blockById(id)) : null;
        if (!box) { node.style.display = 'none'; return; }
        node.style.display = 'block';
        place(node.style, box, origin);
        node.style.height = Math.max(0, box.bottom - box.top) + 'px';
        var block = blockById(id);
        var label = editor.labels[id] || block.getAttribute('data-fsc-block-type') || '';
        if (chip.textContent !== label) { chip.textContent = label; }
        // At the very top of the page the chip goes inside the frame.
        node.className = (node === ui.hov ? 'f h' : 'f') + (box.top - origin.top < 22 / editor.scale ? ' in' : '');
    }

    /** The "+" line, on hover only: at the gap between two blocks (or before the first, after the last) near the pointer. */
    function drawInsert(origin) {
        var boxes = [];
        insertAt = null;
        if (mouse && !editing) { wrappers().forEach(function (node) { var box = boxOf(node); if (box) { boxes.push(box); } }); }
        for (var k = 0; k <= boxes.length && boxes.length; k++) {
            var a = boxes[k - 1];
            var b = boxes[k];
            var y = a && b ? (a.bottom + b.top) / 2 : (b ? b.top : a.bottom);
            var span = { left: Math.min(a ? a.left : Infinity, b ? b.left : Infinity), right: Math.max(a ? a.right : -Infinity, b ? b.right : -Infinity), top: y };
            if (Math.abs(mouse.y - y) <= 14 / editor.scale && mouse.x >= span.left && mouse.x <= span.right) {
                insertAt = k;
                place(ui.line.style, span, origin);
                break;
            }
        }
        ui.line.style.display = insertAt === null ? 'none' : 'block';
    }

    function scheduleBlocks() {
        clearTimeout(blocksTimer);
        blocksTimer = setTimeout(reportBlocks, 150);
    }

    function reportBlocks() {
        clearTimeout(blocksTimer);
        var items = [];
        wrappers().slice(0, 500).forEach(function (node) {
            var id = node.getAttribute('data-fsc-block');
            if (!ID.test(id)) { return; }
            var editable = [];
            list(node.querySelectorAll('[data-fsc-edit]')).forEach(function (field) {
                var name = field.getAttribute('data-fsc-edit');
                if (FIELD.test(name) && editable.indexOf(name) === -1) { editable.push(name); }
            });
            var box = boxOf(node);
            items.push({
                id: id,
                index: parseInt(node.getAttribute('data-fsc-block-index'), 10) || 0,
                type: node.getAttribute('data-fsc-block-type') || '',
                default: node.getAttribute('data-fsc-block-default') === '1',
                layouts: String(node.getAttribute('data-fsc-layouts') || '').split(/[\s,]+/).filter(function (key) { return /^[a-z0-9-]{1,40}$/i.test(key); }),
                editable: editable,
                top: box ? Math.round(box.top + (w.scrollY || 0)) : 0,
                height: box ? Math.round(box.bottom - box.top) : 0,
            });
        });
        post('blocks', { version: cfg.version, items: items });
    }

    /** After a swap or a move: watch every block's boxes (images, fonts, embeds), redraw, report. */
    function changed() {
        if (w.ResizeObserver) {
            observer = observer || new w.ResizeObserver(function () { queueDraw(); scheduleBlocks(); });
            observer.disconnect();
            wrappers().forEach(function (node) { list(node.children).forEach(function (child) { observer.observe(child); }); });
        }
        queueDraw();
        scheduleBlocks();
    }

    /** A computed colour as [r, g, b] over `base` (without: opaque rgb() only); other syntaxes through a 1x1 canvas. */
    function rgbOf(css, base) {
        var m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)$/.exec(String(css || '').trim());
        if (m) {
            var alpha = m[4] === undefined ? 1 : parseFloat(m[4]);
            if (!base && alpha < 1) { return null; }
            return [1, 2, 3].map(function (i) { return Math.round(alpha * parseFloat(m[i]) + (1 - alpha) * (base ? base[i - 1] : 0)); });
        }
        try {
            canvas = canvas || d.createElement('canvas').getContext('2d');
            canvas.fillStyle = 'rgb(' + base.join(',') + ')';
            canvas.fillRect(0, 0, 1, 1);
            canvas.fillStyle = css;
            canvas.fillRect(0, 0, 1, 1);
            return list(canvas.getImageData(0, 0, 1, 1).data).slice(0, 3);
        } catch (error) { return null; }
    }

    /** The site's colours for the portal's swatches, from a probe in the blocks root. */
    function theme() {
        var root = d.querySelector('[data-fsc-preview-blocks]') || d.body;
        var base = null;
        for (var node = root; node && node.nodeType === 1 && !base; node = node.parentNode) { base = rgbOf(w.getComputedStyle(node).backgroundColor, null); }
        base = base || [255, 255, 255];
        var probe = d.createElement('span');
        probe.style.position = 'absolute';
        probe.style.visibility = 'hidden';
        root.appendChild(probe);
        var colors = {};
        var names = { accent: 'accent', accentContrast: 'accent-contrast', toneSoft: 'tone-soft-bg', surface: 'surface', text: 'text', muted: 'muted', border: 'border' };
        for (var key in names) {
            if (!own(names, key)) { continue; }
            probe.style.color = 'var(--fsc-' + names[key] + ')';
            var rgb = rgbOf(w.getComputedStyle(probe).color, base);
            if (rgb) { colors[key] = 'rgb(' + rgb.join(', ') + ')'; }
        }
        root.removeChild(probe);
        post('theme', { colors: colors });
    }

    /* Selection and hover */

    function postSelect(id, field) {
        var block = blockById(id);
        var message = { blockId: id, index: block ? parseInt(block.getAttribute('data-fsc-block-index'), 10) || 0 : 0 };
        if (field) { message.field = field; }
        post('select', message);
    }

    /** Tells the portal which block is hovered: at once when nothing was sent in the last 60 ms, else once more at the end of that window (the latest value). */
    function postHover() {
        if (hoverTimer) { return; }
        if (hoveredId !== hoverSent) { hoverSent = hoveredId; post('hover', { blockId: hoveredId }); }
        hoverTimer = setTimeout(function () {
            hoverTimer = null;
            if (hoveredId !== hoverSent) { hoverSent = hoveredId; post('hover', { blockId: hoveredId }); }
        }, 60);
    }

    function editorClick(event, block) {
        var id = block.getAttribute('data-fsc-block');
        var field = closest(event.target, isEdit);
        if (!ID.test(id)) { return; }
        if (field && closest(field, isBlock) !== block) { field = null; }
        // A click inside the field being edited only moves the caret (the browser does that).
        if (editing && field === editing.el) { return; }
        var name = field && FIELD.test(field.getAttribute('data-fsc-edit')) ? field.getAttribute('data-fsc-edit') : null;
        // Touch: the first tap selects, a tap on the selected block edits.
        var start = name && canEdit(field) && (lastPointer !== 'touch' || selectedId === id);
        if (editing) { endEdit('blur'); }
        selectedId = id;
        queueDraw();
        postSelect(id, name);
        if (start) { beginEdit(field, event.clientX, event.clientY); }
    }

    function selectFromPortal(id, scroll, field) {
        var node = typeof id === 'string' && ID.test(id) ? blockById(id) : null;
        if (id !== null && !node) { return; }
        if (editing && editing.id !== id) { endEdit('blur'); }
        selectedId = id;
        queueDraw();
        if (!node) { return; }
        var first = node.children[0];
        if (scroll === true && first && first.scrollIntoView) {
            first.scrollIntoView({ block: 'center', behavior: w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        }
        list(node.querySelectorAll('[data-fsc-edit]')).some(function (el) {
            var match = el.getAttribute('data-fsc-edit') === field && canEdit(el);
            if (match) { beginEdit(el); }
            return match;
        });
    }

    /* Inline editing (AD7): plain text, and rich text once the portal said `rich: true` (Task 16) */

    var kindOf = function (el) { return el.getAttribute('data-fsc-edit-kind') === 'rich' ? 'rich' : 'plain'; };
    /** Whether the portal lets this marked element be typed in: plain with `inline`, rich only with `inline` and `rich`. */
    var canEdit = function (el) { return !!editor && editor.inline && (kindOf(el) === 'plain' || editor.rich); };
    var textOf = function (el) { return String(el.textContent || '').replace(/[\s\u00a0]+/g, ' ').trim(); };
    /** Plain: the normalized text. Rich: the markup as it is (the Hub sanitizes it), or '' when nothing visible is left. */
    var valueOf = function (el, kind) { return kind === 'rich' ? (textOf(el) === '' ? '' : String(el.innerHTML || '')) : textOf(el); };
    var selection = function () { return w.getSelection ? w.getSelection() : null; };

    function focusEl(el) {
        try { el.focus({ preventScroll: true }); } catch (error) { el.focus(); }
    }

    function setRange(range) {
        var current = selection();
        if (current) { current.removeAllRanges(); current.addRange(range); }
    }

    function placeCaret(el, x, y) {
        var range = null;
        if (typeof x === 'number' && d.caretPositionFromPoint) {
            var position = d.caretPositionFromPoint(x, y);
            if (position && position.offsetNode) { range = d.createRange(); range.setStart(position.offsetNode, position.offset); }
        } else if (typeof x === 'number' && d.caretRangeFromPoint) {
            range = d.caretRangeFromPoint(x, y);
        }
        if (!range || !el.contains(range.startContainer)) { range = d.createRange(); range.selectNodeContents(el); range.collapse(false); }
        range.collapse(true);
        setRange(range);
    }

    /** The caret as a character offset in the field's text, or null when it is not in there. */
    function caretOffset(el) {
        var current = selection();
        if (!current || !current.rangeCount || !el.contains(current.getRangeAt(0).endContainer)) { return null; }
        var before = d.createRange();
        before.selectNodeContents(el);
        before.setEnd(current.getRangeAt(0).endContainer, current.getRangeAt(0).endOffset);
        return before.toString().length;
    }

    function setCaretOffset(el, offset) {
        var range = d.createRange();
        var walk = function (node) {
            return list(node.childNodes).some(function (child) {
                if (child.nodeType !== 3) { return walk(child); }
                if (offset <= child.data.length) { range.setStart(child, offset); return true; }
                offset -= child.data.length;
                return false;
            });
        };
        if (!walk(el)) { range.selectNodeContents(el); range.collapse(false); }
        range.collapse(true);
        setRange(range);
    }

    function beginEdit(el, x, y) {
        var block = closest(el, isBlock);
        var id = block ? block.getAttribute('data-fsc-block') : '';
        var field = el.getAttribute('data-fsc-edit');
        if (!ID.test(id) || !FIELD.test(field) || (editing && editing.el === el)) { return; }
        if (editing) { endEdit('switch'); }
        // From here the block's DOM is the user's, not the markup it was adopted from: drop that signature, so a later refresh with the
        // same server markup (after undo, edit-cancel or a refused edit) is compared with the live DOM and swaps the typing away.
        if (block) { block.__fscSig = null; }
        var kind = kindOf(el);
        if (kind === 'rich') {
            el.contentEditable = 'true';
            command('defaultParagraphSeparator', 'p');
            command('styleWithCSS', false);
        } else {
            try { el.contentEditable = 'plaintext-only'; } catch (error) { /* not supported */ }
            if (el.contentEditable !== 'plaintext-only') { el.contentEditable = 'true'; }
        }
        editing = { el: el, id: id, field: field, kind: kind, sent: valueOf(el, kind), timer: null, last: -Infinity, composing: false, state: null };
        lastInput = now();
        queueDraw();
        focusEl(el);
        // An empty rich field starts with a paragraph, so the first line is one too (Enter then makes the next).
        if (kind === 'rich' && textOf(el) === '' && !el.querySelector('p')) { el.innerHTML = '<p><br></p>'; placeCaret(el.querySelector('p') || el); } else { placeCaret(el, x, y); }
        var start = { blockId: id, field: field, kind: kind };
        if (kind === 'rich') { editing.state = formatState(); start.state = editing.state; remember(); }
        post('edit-start', start);
    }

    /** document.execCommand, quietly (deprecated, but the only way that keeps the browser's own undo of the field). */
    function command(name, value) {
        try { return d.execCommand ? d.execCommand(name, false, value) : false; } catch (error) { return false; }
    }

    function sendEdit() {
        var e = editing;
        if (!e || e.composing) { return; }
        clearTimeout(e.timer);
        e.timer = null;
        var value = valueOf(e.el, e.kind);
        if (value === e.sent) { return; }
        e.sent = value;
        e.last = now();
        needAck[e.id] = ++seqNo;
        var message = { blockId: e.id, field: e.field, kind: e.kind, value: value, seq: seqNo };
        if (e.kind === 'rich') { e.state = formatState(); message.state = e.state; }
        post('edit', message);
    }

    function onInput() {
        var e = editing;
        lastInput = now();
        if (reloadWaiting) { armIdle(); }
        if (e.composing) { return; }
        var wait = 100 - (now() - e.last);
        if (wait <= 0) { sendEdit(); } else if (!e.timer) { e.timer = setTimeout(sendEdit, wait); }
    }

    function armIdle() {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () { if (reloadWaiting && editing) { endEdit('reload'); } }, Math.max(0, 2000 - (now() - lastInput)));
    }

    /** Ends the edit; then a held swap or a waiting reload may run. */
    function endEdit(reason, accept) {
        var e = editing;
        if (!e) { return; }
        e.composing = false;
        sendEdit();
        if (e.kind === 'rich') { remember(e); }
        editing = null;
        clearTimeout(e.timer);
        clearTimeout(idleTimer);
        e.el.removeAttribute('contenteditable');
        if (d.activeElement === e.el && e.el.blur) { e.el.blur(); }
        post('edit-end', { blockId: e.id, field: e.field, kind: e.kind, seq: needAck[e.id] || 0, reason: reason });
        if (accept) { delete needAck[e.id]; delete held[e.id]; }
        var h = held[e.id];
        if (h && (needAck[e.id] || 0) <= h.ack) {
            delete held[e.id];
            delete needAck[e.id];
            var old = blockById(e.id);
            if (old && old.parentNode) { old.parentNode.replaceChild(h.node, old); changed(); }
        }
        queueDraw();
        if (reloadWaiting) { reloadWaiting = false; reload(); }
    }

    var inField = function (event) { return editing && editing.el.contains(event.target); };
    var listen = function (type, fn) { d.addEventListener(type, function (event) { if (inField(event)) { fn(event); } }, true); };

    listen('input', onInput);
    listen('compositionstart', function () { editing.composing = true; });
    listen('compositionend', function () { editing.composing = false; sendEdit(); });
    listen('beforeinput', function (event) {
        var kind = String(event.inputType || '');
        if (editing.kind === 'rich') {
            // Our own commands (format, cleaned paste) pass; of the browser's own only bold and italic (a context menu), never a drop or raw HTML.
            if (ownCommand) { return; }
            var allowed = kind === 'formatBold' || kind === 'formatItalic';
            if ((kind.indexOf('format') === 0 && !allowed) || kind === 'insertFromDrop' || kind === 'insertHTML' || kind === 'insertFromPaste' || kind === 'insertLink') { event.preventDefault(); }
            return;
        }
        if (kind === 'insertParagraph' || kind === 'insertLineBreak') { event.preventDefault(); endEdit('enter'); }
        if (kind.indexOf('format') === 0 || kind === 'insertFromDrop' || kind === 'insertHTML') { event.preventDefault(); }
    });
    listen('paste', function (event) {
        event.preventDefault();
        var data = event.clipboardData && event.clipboardData.getData ? event.clipboardData : null;
        var text = data ? String(data.getData('text/plain') || '') : '';
        if (editing.kind === 'rich') {
            var markup = data ? String(data.getData('text/html') || '') : '';
            var clean = markup && markup.length <= PASTE_MAX ? cleanHtml(markup) : '';
            if (clean === '' && text !== '') { clean = textToHtml(text); }
            if (clean !== '') { ours(function () { command('insertHTML', clean); }); sendEdit(); }
            return;
        }
        if (text && d.execCommand) { d.execCommand('insertText', false, text.replace(/\s+/g, ' ')); }
    });
    listen('drop', function (event) { event.preventDefault(); });
    listen('focusout', function (event) {
        var el = editing.el;
        if (event.target !== el || moving) { return; }
        // Later: a move of the block may blur for a moment.
        setTimeout(function () {
            if (editing && editing.el === el && !(d.activeElement === el && (!d.hasFocus || d.hasFocus()))) { endEdit('blur'); }
        }, 0);
    });

    /* Rich text: format state, format commands, paste cleaning (Task 16) */

    var PASTE_MAX = 200000;
    var ownCommand = false;
    var TAGS = { p: 'p', h1: 'h2', h2: 'h2', h3: 'h3', h4: 'h3', h5: 'h3', h6: 'h3', strong: 'strong', b: 'strong', em: 'em', i: 'em', a: 'a', ul: 'ul', ol: 'ol', li: 'li', br: 'br' };
    var DROP = /^(script|style|template|iframe|frame|frameset|object|embed|noscript|svg|math|img|picture|video|audio|source|track|canvas|head|title|meta|link|base|button|input|select|option|textarea|form)$/i;
    var BLOCKISH = /^(div|section|article|aside|header|footer|main|nav|blockquote|figure|figcaption|pre|address|td|th|dd|dt|table|tr)$/i;
    var esc = function (text) { return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };

    function ours(fn) {
        ownCommand = true;
        try { fn(); } finally { ownCommand = false; }
    }

    /** A link address we put on the page: https, mailto, tel, a path on the site or an anchor; anything else is ''. */
    function safeHref(value) {
        var href = String(value === null || value === undefined ? '' : value).trim();
        if (href === '' || href.length > 2000 || /[\s\u0000-\u001f\u007f<>"'`\\]/.test(href)) { return ''; }
        if (/^https:\/\/[^/?#\s]+/i.test(href) || /^mailto:[^\s]+$/i.test(href) || /^tel:\+?[0-9().-]{3,30}$/i.test(href)) { return href; }
        if (/^\/(?!\/)/.test(href) || /^#[A-Za-z0-9_-]{1,100}$/.test(href)) { return href; }
        return '';
    }

    /** Pasted markup reduced to the allowed tags (p, h2, h3, strong, em, a with a checked href, ul, ol, li, br); text is escaped. */
    function cleanHtml(markup) {
        var parsed;
        try { parsed = new DOMParser().parseFromString(markup, 'text/html'); } catch (error) { return ''; }
        var walk = function (node) {
            return list(node.childNodes).map(function (child) {
                if (child.nodeType === 3) { return esc(child.data); }
                if (child.nodeType !== 1) { return ''; }
                var tag = String(child.tagName).toLowerCase();
                if (DROP.test(tag)) { return ''; }
                // Google Docs wraps a whole paste in <b style="font-weight:normal">: that is no bold.
                var weight = /font-weight\s*:\s*(normal|[1-4]00)\b/i.test(child.getAttribute('style') || '');
                var to = weight && tag === 'b' ? null : TAGS[tag];
                var inner = walk(child);
                if (to === 'br') { return '<br>'; }
                if (to === 'a') { var href = safeHref(child.getAttribute('href')); return href ? '<a href="' + esc(href) + '">' + inner + '</a>' : inner; }
                if (to) { return '<' + to + '>' + inner + '</' + to + '>'; }
                return BLOCKISH.test(tag) && inner.trim() !== '' && !/<(p|h2|h3|ul|ol|li)>/.test(inner) ? '<p>' + inner + '</p>' : inner;
            }).join('');
        };
        return walk(parsed.body || parsed.documentElement).trim();
    }

    /** Plain pasted text as paragraphs (a blank line) and line breaks. */
    function textToHtml(text) {
        var parts = String(text).replace(/\r\n?/g, '\n').split(/\n{2,}/).map(function (part) { return part.trim(); }).filter(Boolean);
        if (parts.length === 1 && parts[0].indexOf('\n') === -1) { return esc(parts[0]); }
        return parts.map(function (part) { return '<p>' + esc(part).replace(/\n/g, '<br>') + '</p>'; }).join('');
    }

    /** Text offsets of the selection inside `el` ({start, end}), or null when the selection is not in there. */
    function offsetsOf(el) {
        var current = selection();
        if (!current || !current.rangeCount) { return null; }
        var range = current.getRangeAt(0);
        if (!el.contains(range.startContainer) || !el.contains(range.endContainer)) { return null; }
        var measure = function (node, offset) { var before = d.createRange(); before.selectNodeContents(el); before.setEnd(node, offset); return before.toString().length; };
        return { start: measure(range.startContainer, range.startOffset), end: measure(range.endContainer, range.endOffset) };
    }

    function pointAt(el, offset) {
        var found = null;
        var walk = function (node) {
            return list(node.childNodes).some(function (child) {
                if (child.nodeType !== 3) { return walk(child); }
                if (offset <= child.data.length) { found = [child, offset]; return true; }
                offset -= child.data.length;
                return false;
            });
        };
        walk(el);
        return found;
    }

    function setOffsets(el, start, end) {
        var a = pointAt(el, start);
        var b = pointAt(el, end);
        var range = d.createRange();
        if (!a || !b) { range.selectNodeContents(el); range.collapse(false); setRange(range); return; }
        range.setStart(a[0], a[1]);
        range.setEnd(b[0], b[1]);
        setRange(range);
    }

    /** Keeps the rich field and its selection, so a `format` (the link popover took the focus) can resume there. */
    function remember(e) {
        e = e || editing;
        if (!e || e.kind !== 'rich') { return; }
        var at = offsetsOf(e.el);
        if (at) { lastCaret = { id: e.id, field: e.field, start: at.start, end: at.end }; } else if (!lastCaret || lastCaret.id !== e.id || lastCaret.field !== e.field) { lastCaret = null; }
    }

    /** Opens the rich field `field` of block `id` again with the selection it had (after the portal took the focus for a moment). */
    function resume(id, field) {
        if (!lastCaret || (id !== null && (lastCaret.id !== id || lastCaret.field !== field))) { return false; }
        var block = blockById(lastCaret.id);
        var target = null;
        list(block ? block.querySelectorAll('[data-fsc-edit]') : []).some(function (el) {
            if (el.getAttribute('data-fsc-edit') === lastCaret.field && closest(el, isBlock) === block) { target = el; }
            return target !== null;
        });
        if (!target || kindOf(target) !== 'rich' || !canEdit(target)) { return false; }
        var at = lastCaret;
        beginEdit(target);
        if (!editing || editing.el !== target) { return false; }
        setOffsets(target, at.start, at.end);
        return true;
    }

    /** The formats at the caret for the portal's bar: block (p, h2, h3, ul, ol), bold, italic, link. */
    function formatState() {
        var e = editing;
        var state = { block: 'p', bold: false, italic: false, link: false };
        var current = selection();
        var node = current && current.rangeCount ? current.getRangeAt(0).startContainer : null;
        if (!e || !node || !e.el.contains(node)) { return state; }
        var blockSet = false;
        for (; node && node !== e.el; node = node.parentNode) {
            if (node.nodeType !== 1) { continue; }
            var tag = String(node.tagName).toLowerCase();
            if (tag === 'strong' || tag === 'b') { state.bold = true; }
            if (tag === 'em' || tag === 'i') { state.italic = true; }
            if (tag === 'a') { state.link = true; }
            if (!blockSet && (tag === 'h2' || tag === 'h3' || tag === 'ul' || tag === 'ol')) { state.block = tag; blockSet = true; }
        }
        return state;
    }

    var stateTimer = null;

    function postState() {
        stateTimer = null;
        var e = editing;
        if (!e || e.kind !== 'rich') { return; }
        remember(e);
        var next = formatState();
        if (e.state && next.block === e.state.block && next.bold === e.state.bold && next.italic === e.state.italic && next.link === e.state.link) { return; }
        e.state = next;
        post('edit-state', { blockId: e.id, field: e.field, state: next });
    }

    d.addEventListener('selectionchange', function () {
        if (editing && editing.kind === 'rich' && !stateTimer) { stateTimer = setTimeout(postState, 60); }
    });

    /** The element around the caret with this tag inside the field, or null. */
    function around(tag) {
        var current = selection();
        var node = current && current.rangeCount ? current.getRangeAt(0).startContainer : null;
        for (; editing && node && node !== editing.el; node = node.parentNode) {
            if (node.nodeType === 1 && String(node.tagName).toLowerCase() === tag) { return node; }
        }
        return null;
    }

    /** Chrome leaves a list it made inside the paragraph it came from (`<p><ul>`): the list takes the paragraph's place. */
    function unnest(el) {
        var at = offsetsOf(el);
        var moved = false;
        ['p', 'h2', 'h3'].forEach(function (tag) {
            list(el.querySelectorAll(tag)).forEach(function (block) {
                var kids = list(block.childNodes).filter(function (n) { return !(n.nodeType === 3 && /^\s*$/.test(n.data)); });
                if (!kids.length || !kids.every(function (n) { return n.nodeType === 1 && /^(ul|ol)$/i.test(n.tagName); })) { return; }
                kids.forEach(function (n) { block.parentNode.insertBefore(n, block); });
                block.parentNode.removeChild(block);
                moved = true;
            });
        });
        // The text did not change, so the selection comes back at the same text offsets.
        if (moved && at) { setOffsets(el, at.start, at.end); }
    }

    /** A `format` command of the portal's bar (or a key in the field): on the rich field with the caret, or the one left a moment ago. */
    function format(message) {
        var name = message.command;
        if (FORMATS.indexOf(name) < 0) { return; }
        var id = typeof message.blockId === 'string' && ID.test(message.blockId) ? message.blockId : null;
        var field = typeof message.field === 'string' && FIELD.test(message.field) ? message.field : null;
        var e = editing;
        if (!e || e.kind !== 'rich' || (id !== null && (e.id !== id || e.field !== field))) {
            if (!lastCaret || (id !== null && (lastCaret.id !== id || lastCaret.field !== field))) { return; }
            if (e) { endEdit('switch'); }
            if (!resume(id, field)) { return; }
            e = editing;
        } else if (!offsetsOf(e.el) && lastCaret && lastCaret.id === e.id && lastCaret.field === e.field) {
            focusEl(e.el);
            setOffsets(e.el, lastCaret.start, lastCaret.end);
        }
        ours(function () {
            if (name === 'bold' || name === 'italic') { command(name); }
            if (name === 'h2' || name === 'h3' || name === 'p') { command('formatBlock', '<' + name + '>'); }
            // A list is made of paragraphs: a heading becomes one first (else the browser nests the list inside the heading).
            if ((name === 'ul' || name === 'ol') && (around('h2') || around('h3'))) { command('formatBlock', '<p>'); }
            if (name === 'ul') { command('insertUnorderedList'); }
            if (name === 'ol') { command('insertOrderedList'); }
            if (name === 'ul' || name === 'ol') { unnest(e.el); }
            if (name === 'unlink' || name === 'link') {
                var anchor = around('a');
                var current = selection();
                if (anchor && current && current.rangeCount && current.getRangeAt(0).collapsed) {
                    var whole = d.createRange();
                    whole.selectNodeContents(anchor);
                    setRange(whole);
                }
                if (name === 'unlink') { command('unlink'); return; }
                var href = safeHref(message.value);
                if (href === '') { return; }
                if (current && current.rangeCount && current.getRangeAt(0).collapsed && !anchor) { command('insertHTML', '<a href="' + esc(href) + '">' + esc(href) + '</a>'); } else { command('createLink', href); }
            }
        });
        sendEdit();
        postState();
    }

    /* Keyed swap and moves (AD7 rules 3 and 4) */

    // A site (the Hub fallback does) may answer with a CSP nonce that changes with every response, and the browser hides the nonce of the nodes that
    // are in the page: compare without it, or an unchanged inline <style> looks changed, is swapped for a copy that carries the wrong nonce and the
    // blocks lose their CSS (found on the real stack, plan page-builder-v2 Task 20).
    var plain = function (html) { return String(html).replace(/\snonce="[^"]*"/g, ''); };
    var signature = function (node) { return plain(typeof node.__fscSig === 'string' ? node.__fscSig : node.outerHTML); };

    /** The nonce the page itself runs with (the `nonce` property survives where the attribute is hidden), or ''. */
    function pageNonce() {
        var holder = d.querySelectorAll('style[nonce]')[0] || d.querySelectorAll('script[nonce]')[0];
        return holder && typeof holder.nonce === 'string' ? holder.nonce : '';
    }

    function adopt(fresh) {
        var node = d.importNode(fresh, true);
        node.__fscSig = fresh.outerHTML;
        var nonce = pageNonce();
        if (nonce !== '') {
            // A changed inline style carries the nonce of the answer it came from, which the page's CSP does not know: give it the page's own.
            var styles = list(node.querySelectorAll ? node.querySelectorAll('style[nonce]') : []);
            if (node.tagName === 'STYLE' && node.getAttribute('nonce') !== null) { styles.push(node); }
            styles.forEach(function (style) { style.nonce = nonce; });
        }
        return node;
    }

    /** Moves a node before `ref`: the field with the caret is moved, not re-created, and keeps its caret. */
    function move(parent, node, ref) {
        var keep = editing && node.contains(editing.el) ? caretOffset(editing.el) : null;
        moving = true;
        try {
            if (typeof parent.moveBefore === 'function' && node.parentNode === parent) { parent.moveBefore(node, ref); } else { parent.insertBefore(node, ref); }
        } catch (error) { parent.insertBefore(node, ref); } finally { moving = false; }
        // moveBefore keeps the focus but shifts the caret (Chrome), which only takes a new one after a layout: put both back.
        if (keep !== null && editing) {
            if (d.activeElement !== editing.el) { focusEl(editing.el); }
            editing.el.getBoundingClientRect();
            setCaretOffset(editing.el, keep);
        }
    }

    /** Puts `nodes` in this order right before `ref`, moving only those out of place. */
    function arrange(parent, nodes, ref) {
        for (var i = nodes.length - 1; i >= 0; i--) {
            if (nodes[i].parentNode !== parent || nodes[i].nextElementSibling !== ref) { move(parent, nodes[i], ref); }
            ref = nodes[i];
        }
    }

    function swapRoot(root, fresh, ack) {
        var oldBlocks = {};
        var oldOther = [];
        list(root.children).forEach(function (node) {
            var id = node.getAttribute('data-fsc-block');
            if (id !== null && !oldBlocks[id]) { oldBlocks[id] = node; } else { oldOther.push(node); }
        });
        var desired = list(fresh.children).map(function (next) {
            var id = next.getAttribute('data-fsc-block');
            var old = id !== null ? oldBlocks[id] : null;
            if (id === null) {
                for (var i = 0; i < oldOther.length; i++) { if (signature(oldOther[i]) === plain(next.outerHTML)) { return oldOther.splice(i, 1)[0]; } }
                return adopt(next);
            }
            if (!old) { return adopt(next); }
            delete oldBlocks[id];
            if (signature(old) === plain(next.outerHTML)) { delete held[id]; return old; }
            if (editing && editing.id === id) { held[id] = { node: adopt(next), ack: ack }; return old; }
            if ((needAck[id] || 0) > ack) { return old; }
            delete needAck[id];
            delete held[id];
            var node = adopt(next);
            root.replaceChild(node, old);
            return node;
        });
        for (var id in oldBlocks) {
            if (own(oldBlocks, id)) {
                if (editing && editing.id === id) { endEdit('removed', true); }
                oldOther.push(oldBlocks[id]);
            }
        }
        oldOther.forEach(function (node) { if (node.parentNode === root) { root.removeChild(node); } });
        arrange(root, desired, null);
    }

    function order(ids) {
        if (!Array.isArray(ids) || ids.length > 500) { return; }
        var rank = {};
        var count = 0;
        ids.forEach(function (id, i) { if (typeof id === 'string' && ID.test(id) && !own(rank, id)) { rank[id] = i; } });
        list(d.querySelectorAll('[data-fsc-preview-blocks]')).forEach(function (root) {
            var nodes = list(root.children).filter(isBlock);
            if (!nodes.length) { return; }
            var end = nodes[nodes.length - 1].nextElementSibling;
            var key = function (node) { var id = node.getAttribute('data-fsc-block'); return own(rank, id) ? rank[id] : ids.length + nodes.indexOf(node); };
            nodes = nodes.slice().sort(function (a, b) { return key(a) - key(b); });
            arrange(root, nodes, end);
            nodes.forEach(function (node) { node.setAttribute('data-fsc-block-index', String(count++)); });
        });
        changed();
    }

    /* Handshake, pointer and keys */

    function init(message) {
        if (message.proto !== 2) { return; }
        var labels = {};
        var given = message.labels && typeof message.labels === 'object' ? message.labels : {};
        for (var id in given) { if (own(given, id) && ID.test(id) && typeof given[id] === 'string') { labels[id] = given[id].slice(0, 80); } }
        var add = message.texts && typeof message.texts.add === 'string' && message.texts.add ? message.texts.add.slice(0, 60) : 'Sectie toevoegen';
        var scale = typeof message.scale === 'number' && message.scale >= 0.1 && message.scale <= 4 ? message.scale : 1;
        var first = !editor;
        editor = { inline: message.inline === true, rich: message.inline === true && message.rich === true, labels: labels, scale: scale };
        ui = first ? buildUi() : ui;
        if (ui) {
            ui.host.style.setProperty('--z', String(scale));
            ui.plus.setAttribute('aria-label', add);
            ui.plus.title = add;
        }
        if (editing && !canEdit(editing.el)) { endEdit('cancel', true); }
        if (first) {
            try { theme(); } catch (error) { /* no colours then */ }
            w.addEventListener('scroll', queueDraw, { passive: true });
            w.addEventListener('resize', changed);
            d.addEventListener('load', changed, true);
            changed();
        }
        draw();
        reportBlocks();
    }

    d.addEventListener('pointerdown', function (event) { lastPointer = event.pointerType || 'mouse'; }, true);

    d.addEventListener('mousemove', function (event) {
        if (!editor) { return; }
        mouse = { x: event.clientX, y: event.clientY };
        if (!ui || event.target !== ui.host) {
            var block = closest(event.target, isBlock);
            var id = block && ID.test(block.getAttribute('data-fsc-block')) ? block.getAttribute('data-fsc-block') : null;
            if (id !== hoveredId) { hoveredId = id; postHover(); }
        }
        queueDraw();
    }, true);

    d.addEventListener('mouseout', function (event) {
        if (!editor || event.relatedTarget) { return; }
        mouse = hoveredId = null;
        postHover();
        queueDraw();
    }, true);

    d.addEventListener('keydown', function (event) {
        if (!editor) { return; }
        var mod = event.metaKey || event.ctrlKey;
        var key = String(event.key || '').toLowerCase();
        var shortcut = function (name) { event.preventDefault(); post('shortcut', { name: name }); };
        // F6 / Shift+F6 move the focus to the next / previous pane of the portal's builder; the browser's own F6 would only cycle its chrome.
        // Plain F6 only: with Ctrl, Alt or Meta it is someone else's shortcut. Works in an edit too (the focus leaving the frame ends it).
        if (key === 'f6' && !mod && !event.altKey && !(event.isComposing || event.keyCode === 229)) { shortcut(event.shiftKey ? 'pane-prev' : 'pane-next'); return; }
        if (editing && !(event.isComposing || event.keyCode === 229 || editing.composing)) {
            if (key === 'enter' && editing.kind === 'plain') { event.preventDefault(); endEdit('enter'); return; }
            if (key === 'escape') { endEdit('escape'); shortcut('escape'); return; }
            if (editing.kind === 'rich' && mod && !event.altKey) {
                if (key === 'b' || key === 'i') { event.preventDefault(); format({ command: key === 'b' ? 'bold' : 'italic' }); return; }
                if (key === 'k') { remember(); shortcut('link'); return; }
                if (key === 'u') { event.preventDefault(); return; }
            }
        }
        if (mod && key === 's') { sendEdit(); shortcut('save'); return; }
        // In the field the browser's undo applies.
        if (editing || closest(event.target, function (node) { return /^(input|textarea|select)$/i.test(node.tagName) || node.isContentEditable === true; })) { return; }
        if (mod && key === 'z') { shortcut(event.shiftKey ? 'redo' : 'undo'); }
        if (key === 'escape') { post('shortcut', { name: 'escape' }); }
        if ((key === 'delete' || key === 'backspace') && !mod && selectedId !== null) { shortcut('delete'); }
    }, true);

    w.addEventListener('message', function (event) {
        if (!target || !cfg.origin || event.origin !== cfg.origin || event.source !== target) { return; }
        var message = event.data;
        if (!message || typeof message !== 'object' || message.ns !== NS || message.v !== V) { return; }
        var type = message.type;
        var id = typeof message.blockId === 'string' && ID.test(message.blockId) ? message.blockId : null;
        if (type === 'refresh') {
            var ack = message.ack;
            refresh(typeof message.version === 'number' ? message.version : 0, typeof ack === 'number' && isFinite(ack) && ack >= 0 ? ack : 0);
        } else if (type === 'highlight') {
            if (editor) { selectFromPortal(id, true); } else { highlight(message.blockId); }
        } else if (type === 'consent') {
            consent(message.categories);
        } else if (type === 'init') {
            init(message);
        } else if (editor && type === 'select' && (id !== null || message.blockId === null)) {
            selectFromPortal(id, message.scroll, message.field);
        } else if (editor && type === 'hover') {
            hoveredId = id;
            queueDraw();
        } else if (editor && type === 'order') {
            order(message.ids);
        } else if (editor && type === 'format') {
            format(message);
        } else if (editor && type === 'edit-cancel') {
            if (editing && (id === null || id === editing.id)) { endEdit('cancel', true); } else if (id !== null) { delete needAck[id]; delete held[id]; }
        }
    });

    function ready() {
        restoreScroll();
        post('ready', { version: cfg.version, sdk: cfg.sdk, proto: 2, caps: ['blocks', 'select', 'hover', 'insert', 'order', 'edit-plain', 'edit-rich', 'format', 'shortcut', 'theme', 'timing'] });
    }

    if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', ready); } else { ready(); }
})();

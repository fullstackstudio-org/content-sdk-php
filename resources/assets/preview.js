/*
 * Portal preview helper of the FullStack Hub SDK, served at /_fsc/p/preview.js. The server inserts the tag right after <head> ONLY into a preview
 * response (POST /_fsc/p with a valid preview token, see src/Laravel/Preview/PreviewRequest.php), with its config as data-* attributes:
 * endpoint, token, origin (the exact portal origin allowed to frame the page), version, sdk.
 *
 * - Marks the page at once: html[data-fsc-preview] (a cookie banner hides itself on it) and window.fscPreviewMode = true.
 * - Blocks every form submit (the SDK forms are disabled server-side as well) and, when framed, link clicks inside blocks.
 * - Talks to the portal with postMessage ({ns: 'fsc-preview', v: 1, type, ...}), exact origin and source checks on every message:
 *     to the portal:   ready {version, sdk}, rendered {version}, select {blockId, index}, error {code}
 *     from the portal: refresh {version}, highlight {blockId}
 *   No content travels over postMessage: on refresh the page asks its own server again (same-origin POST with the token) and swaps the
 *   [data-fsc-preview-blocks] roots when their count matches (scroll kept), else it reloads with a form POST (scroll restored).
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
    var tag = d.currentScript;
    var attr = function (name) { return tag ? tag.getAttribute('data-' + name) || '' : ''; };
    var cfg = {
        endpoint: attr('endpoint') || '/_fsc/p',
        token: attr('token'),
        origin: attr('origin'),
        version: parseInt(attr('version'), 10) || 0,
        sdk: attr('sdk'),
    };
    // The token stays in this closure only, out of the DOM.
    if (tag) { tag.removeAttribute('data-token'); }

    var framed = w.parent && w.parent !== w;
    var target = framed ? w.parent : (w.opener || null);

    function post(type, extra) {
        if (!target || !cfg.origin) { return; }
        var message = { ns: NS, v: V, type: type };
        for (var key in extra) { if (Object.prototype.hasOwnProperty.call(extra, key)) { message[key] = extra[key]; } }
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

    // Nothing is ever submitted from a preview.
    d.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);

    d.addEventListener('click', function (event) {
        var block = closest(event.target, isBlock);
        if (!block) { return; }
        if (framed && closest(event.target, isLink)) { event.preventDefault(); }
        post('select', { blockId: block.getAttribute('data-fsc-block'), index: parseInt(block.getAttribute('data-fsc-block-index'), 10) || 0 });
    }, true);

    var lit = [];

    function highlight(blockId) {
        lit.forEach(function (entry) { entry[0].style.outline = entry[1]; entry[0].style.outlineOffset = entry[2]; });
        lit = [];
        var blocks = d.querySelectorAll('[data-fsc-block]');
        var found = null;
        for (var i = 0; i < blocks.length; i++) {
            if (blocks[i].getAttribute('data-fsc-block') === String(blockId)) { found = blocks[i]; break; }
        }
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

    function reload() {
        try { sessionStorage.setItem(SCROLL, JSON.stringify({ x: w.scrollX || 0, y: w.scrollY || 0 })); } catch (error) { /* storage blocked */ }
        var form = d.createElement('form');
        form.method = 'POST';
        form.action = cfg.endpoint;
        form.target = '_self';
        form.style.display = 'none';
        var input = d.createElement('input');
        input.type = 'hidden';
        input.name = 'token';
        input.value = cfg.token;
        form.appendChild(input);
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
        if (saved && typeof saved.y === 'number' && w.scrollTo) { w.scrollTo(saved.x || 0, saved.y); }
    }

    function versionOf(doc) {
        var script = doc.querySelector('script[data-endpoint][data-version]');
        var value = script ? parseInt(script.getAttribute('data-version'), 10) : NaN;
        return isNaN(value) ? null : value;
    }

    var sequence = 0;

    function refresh(version) {
        var mine = ++sequence;
        var body = new URLSearchParams();
        body.set('token', cfg.token);
        fetch(cfg.endpoint, { method: 'POST', body: body, credentials: 'omit', cache: 'no-store', redirect: 'error' }).then(function (response) {
            if (!response.ok) { throw new Error(String(response.status)); }
            return response.text();
        }).then(function (text) {
            if (mine !== sequence) { return; }
            var doc = new DOMParser().parseFromString(text, 'text/html');
            var fresh = doc.querySelectorAll('[data-fsc-preview-blocks]');
            var old = d.querySelectorAll('[data-fsc-preview-blocks]');
            if (!old.length || fresh.length !== old.length) { reload(); return; }
            var x = w.scrollX || 0;
            var y = w.scrollY || 0;
            for (var i = 0; i < old.length; i++) { old[i].parentNode.replaceChild(d.importNode(fresh[i], true), old[i]); }
            if (doc.title) { d.title = doc.title; }
            if (w.scrollTo) { w.scrollTo(x, y); }
            var next = versionOf(doc);
            cfg.version = next !== null ? next : (version || cfg.version);
            post('rendered', { version: cfg.version });
        }).catch(function (error) {
            if (mine !== sequence) { return; }
            var status = error && error.message;
            post('error', { code: status === '404' ? 'expired' : status === '429' ? 'rate_limited' : 'refresh_failed' });
        });
    }

    w.addEventListener('message', function (event) {
        if (!target || !cfg.origin || event.origin !== cfg.origin || event.source !== target) { return; }
        var message = event.data;
        if (!message || typeof message !== 'object' || message.ns !== NS || message.v !== V) { return; }
        if (message.type === 'refresh') {
            refresh(typeof message.version === 'number' ? message.version : 0);
        } else if (message.type === 'highlight') {
            highlight(message.blockId);
        }
    });

    function ready() {
        restoreScroll();
        post('ready', { version: cfg.version, sdk: cfg.sdk });
    }

    if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', ready); } else { ready(); }
})();

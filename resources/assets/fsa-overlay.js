/*
 * FullStack Hub Analytics heatmap overlay (fsa-overlay.js), served by the PHP SDK at /_fsc/a/overlay.js. Loaded by fsa.js only when the
 * page was opened from the portal with `#fsa_overlay=<token>` (plan analytics-engine, phase 4).
 *
 * - The token (short-lived, minted by the Hub for ONE page, viewport class and period of this site's project) is read from the fragment,
 *   removed from the address bar at once and posted to the site itself (`POST /_fsc/a/overlay`, in the body). The site forwards it with
 *   its server token to the Hub; the answer holds aggregates only (elements with 2+ visits, clicks per cell of a 10 × 10 grid over each
 *   element, the scroll reach).
 * - Element based: every element is looked up again with its selector and the heat is drawn over its CURRENT box, so the overlay stays
 *   right after a layout change. Elements that are gone are listed in the panel.
 * - CSP-safe: styles only through the CSSOM (`element.style`), text only through `textContent`, no inline <style>, no innerHTML, no eval.
 *   Everything lives in one container with `pointer-events: none` (the panel excepted), removed with the close button.
 */
(function (w, d) {
    'use strict';

    if (w.__fsaOverlay) return;
    w.__fsaOverlay = true;

    var match = /(?:^#|&)fsa_overlay=([A-Za-z0-9._-]{20,1200})/.exec(String(w.location.hash || ''));
    if (!match) return;
    var token = match[1];

    // Out of the address bar (no copy-paste, bookmark or history entry with the token); it stays in memory only.
    try {
        var rest = String(w.location.hash).replace(/^#/, '').split('&').filter(function (part) { return part.indexOf('fsa_overlay=') !== 0; }).join('&');
        w.history.replaceState(w.history.state, '', w.location.pathname + w.location.search + (rest ? '#' + rest : ''));
    } catch (e) { /* not fatal */ }

    var me = d.currentScript;
    var base = me && me.src ? me.src.replace(/\/overlay\.js(?:\?.*)?$/, '') : '/_fsc/a';
    var Z = '2147483000';
    var LABELS = { mobile: 'mobiel', tablet: 'tablet', desktop: 'desktop' };
    var RANGES = { '7d': '7 dagen', '30d': '30 dagen', '90d': '90 dagen', '365d': '12 maanden' };
    var root = null;
    var layer = null;
    var panel = null;
    var data = null;
    var visible = true;

    function css(node, styles) {
        for (var k in styles) if (Object.prototype.hasOwnProperty.call(styles, k)) node.style[k] = styles[k];
        return node;
    }

    function make(tag, styles, text) {
        var node = d.createElement(tag);
        if (styles) css(node, styles);
        if (text != null) node.textContent = String(text);
        return node;
    }

    function fmt(n) {
        return Number(n || 0).toLocaleString('nl-NL');
    }

    function pct(share) {
        return Math.round(Number(share || 0) * 100) + '%';
    }

    function viewportClass(width) {
        return width < 768 ? 'mobile' : width < 1024 ? 'tablet' : 'desktop';
    }

    /** Heat colour for an intensity 0..1: blue, cyan, green, yellow, red. */
    function heat(t, alpha) {
        var stops = [[37, 99, 235], [6, 182, 212], [34, 197, 94], [250, 204, 21], [239, 68, 68]];
        var x = Math.max(0, Math.min(1, t)) * (stops.length - 1);
        var i = Math.min(stops.length - 2, Math.floor(x));
        var f = x - i;
        var c = stops[i].map(function (v, k) { return Math.round(v + (stops[i + 1][k] - v) * f); });
        return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + alpha + ')';
    }

    function docSize() {
        var el = d.documentElement;
        var body = d.body || el;
        return {
            width: Math.max(el.scrollWidth, body.scrollWidth, el.clientWidth),
            height: Math.max(el.scrollHeight, body.scrollHeight, el.clientHeight),
        };
    }

    function find(selector) {
        try { return d.querySelector(selector); } catch (e) { return null; }
    }

    function buildRoot() {
        root = make('div', { position: 'absolute', left: '0', top: '0', width: '0', height: '0', zIndex: Z, pointerEvents: 'none' });
        root.setAttribute('data-fsa-overlay', '');
        root.setAttribute('aria-hidden', 'true');
        layer = make('div', { position: 'absolute', left: '0', top: '0', pointerEvents: 'none' });
        root.appendChild(layer);
        (d.body || d.documentElement).appendChild(root);
    }

    function badge(text, title) {
        var b = make('div', {
            position: 'absolute', transform: 'translate(-2px, -100%)', padding: '1px 6px', borderRadius: '6px', background: 'rgba(15,23,42,.88)',
            color: '#fff', font: '600 11px/16px system-ui, -apple-system, Segoe UI, sans-serif', whiteSpace: 'nowrap', pointerEvents: 'none',
        }, text);
        if (title) b.title = title;
        return b;
    }

    function draw() {
        if (!data || !layer) return;
        while (layer.firstChild) layer.removeChild(layer.firstChild);
        var size = docSize();
        css(layer, { width: size.width + 'px', height: size.height + 'px' });
        var grid = data.grid || 10;
        var max = 1;
        data.elements.forEach(function (e) { (e.cells || []).forEach(function (c) { if (c[2] > max) max = c[2]; }); });
        var missing = [];
        var sx = w.scrollX || w.pageXOffset || 0;
        var sy = w.scrollY || w.pageYOffset || 0;
        var ratio = Math.min(2, w.devicePixelRatio || 1);

        data.elements.forEach(function (e, rank) {
            var node = find(e.selector);
            var rect = node && node.getBoundingClientRect ? node.getBoundingClientRect() : null;
            if (!rect || rect.width < 1 || rect.height < 1) {
                missing.push(e);
                return;
            }
            var width = Math.min(rect.width, 2400);
            var height = Math.min(rect.height, 2400);
            var left = rect.left + sx;
            var top = rect.top + sy;
            var canvas = make('canvas', { position: 'absolute', left: left + 'px', top: top + 'px', width: width + 'px', height: height + 'px', pointerEvents: 'none' });
            canvas.width = Math.max(1, Math.round(width * ratio));
            canvas.height = Math.max(1, Math.round(height * ratio));
            var ctx = canvas.getContext && canvas.getContext('2d');
            if (ctx) {
                ctx.scale(ratio, ratio);
                var cw = width / grid;
                var ch = height / grid;
                var radius = Math.max(10, Math.min(48, Math.max(cw, ch) * 0.9));
                (e.cells || []).forEach(function (c) {
                    var t = Math.sqrt(c[2] / max);
                    var x = (c[0] + 0.5) * cw;
                    var y = (c[1] + 0.5) * ch;
                    var g = ctx.createRadialGradient(x, y, 0, x, y, radius);
                    g.addColorStop(0, heat(t, 0.75));
                    g.addColorStop(1, heat(t, 0));
                    ctx.fillStyle = g;
                    ctx.fillRect(x - radius, y - radius, radius * 2, radius * 2);
                });
            }
            layer.appendChild(canvas);
            var outline = make('div', {
                position: 'absolute', left: left + 'px', top: top + 'px', width: width + 'px', height: height + 'px', boxSizing: 'border-box',
                border: '1px dashed ' + (e.rage > 0 ? 'rgba(239,68,68,.9)' : 'rgba(15,23,42,.45)'), borderRadius: '4px', pointerEvents: 'none',
            });
            layer.appendChild(outline);
            var extra = (e.rage ? ' · ' + fmt(e.rage) + ' rage' : '') + (e.dead ? ' · ' + fmt(e.dead) + ' dood' : '');
            var b = badge('#' + (rank + 1) + ' · ' + fmt(e.clicks) + extra, e.selector);
            css(b, { left: left + 'px', top: top + 'px' });
            layer.appendChild(b);
        });

        // Scroll reach: a line at every 20% of the page with the share of views that got there.
        (data.scroll || []).forEach(function (s) {
            if (s.depth === 0 || s.depth === 100 || s.depth % 20 !== 0) return;
            var y = Math.round(size.height * s.depth / 100);
            layer.appendChild(make('div', { position: 'absolute', left: '0', top: y + 'px', width: '100%', borderTop: '2px dashed rgba(124,58,237,.7)', pointerEvents: 'none' }));
            var label = badge(pct(s.reach) + ' van de weergaven komt hier', null);
            css(label, { left: '8px', top: y + 'px', background: 'rgba(124,58,237,.92)' });
            layer.appendChild(label);
        });

        renderPanel(missing);
    }

    function renderPanel(missing) {
        if (!panel) {
            panel = make('div', {
                position: 'fixed', right: '12px', bottom: '12px', zIndex: String(Number(Z) + 1), width: '300px', maxWidth: 'calc(100vw - 24px)',
                maxHeight: '60vh', overflow: 'auto', padding: '12px 14px', borderRadius: '12px', background: '#0f172a', color: '#e2e8f0',
                boxShadow: '0 12px 32px rgba(0,0,0,.35)', font: '13px/1.45 system-ui, -apple-system, Segoe UI, sans-serif', pointerEvents: 'auto',
            });
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-label', 'Heatmap');
            (d.body || d.documentElement).appendChild(panel);
        }
        while (panel.firstChild) panel.removeChild(panel.firstChild);

        var head = make('div', { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', marginBottom: '6px' });
        head.appendChild(make('strong', { fontSize: '14px', color: '#fff' }, 'Heatmap'));
        var actions = make('div', { display: 'flex', gap: '6px' });
        actions.appendChild(button(visible ? 'Verberg' : 'Toon', function () {
            visible = !visible;
            css(root, { display: visible ? 'block' : 'none' });
            renderPanel(missing);
        }));
        actions.appendChild(button('Sluit', close));
        head.appendChild(actions);
        panel.appendChild(head);

        if (!data) return;
        panel.appendChild(make('div', { color: '#94a3b8' }, data.path + ' · ' + (LABELS[data.viewport] || data.viewport) + ' · ' + (RANGES[data.range] || data.range)));
        panel.appendChild(make('div', { marginTop: '6px' }, fmt(data.clicks) + ' klikken van bezoekers met toestemming (' + pct(data.consentShare) + ' van de weergaven)'));

        var legend = make('div', { display: 'flex', alignItems: 'center', gap: '6px', marginTop: '8px', color: '#94a3b8', fontSize: '12px' });
        legend.appendChild(make('span', null, 'Weinig'));
        var ramp = make('span', { flex: '1', height: '8px', borderRadius: '4px', background: 'linear-gradient(90deg,' + [0, 0.25, 0.5, 0.75, 1].map(function (t) { return heat(t, 1); }).join(',') + ')' });
        legend.appendChild(ramp);
        legend.appendChild(make('span', null, 'Veel'));
        panel.appendChild(legend);

        var width = w.innerWidth || 0;
        if (viewportClass(width) !== data.viewport) {
            panel.appendChild(make('div', { marginTop: '8px', padding: '6px 8px', borderRadius: '8px', background: 'rgba(250,204,21,.15)', color: '#fde68a' },
                'Dit venster is ' + width + ' px breed. Deze heatmap hoort bij ' + (LABELS[data.viewport] || data.viewport) + '; maak het venster ' + (data.viewport === 'mobile' ? 'smaller dan 768 px' : data.viewport === 'tablet' ? '768 tot 1024 px' : 'minstens 1024 px') + ' breed.'));
        }
        if (w.location.pathname !== data.path) {
            panel.appendChild(make('div', { marginTop: '8px', color: '#fde68a' }, 'Deze heatmap hoort bij ' + data.path + ', niet bij deze pagina.'));
        }
        if (data.elements.length === 0) {
            panel.appendChild(make('div', { marginTop: '8px' }, 'Nog geen elementen met klikken van minstens twee bezoeken in deze periode.'));
        }
        if (missing && missing.length) {
            panel.appendChild(make('div', { marginTop: '10px', color: '#94a3b8' }, 'Niet (meer) op de pagina:'));
            var list = make('ul', { margin: '4px 0 0', paddingLeft: '18px' });
            missing.slice(0, 15).forEach(function (e) {
                list.appendChild(make('li', { wordBreak: 'break-all' }, e.selector + ' · ' + fmt(e.clicks)));
            });
            panel.appendChild(list);
        }
    }

    function button(label, onClick) {
        var b = make('button', {
            font: '600 12px/1 system-ui, -apple-system, Segoe UI, sans-serif', padding: '6px 9px', borderRadius: '7px', border: '1px solid #334155',
            background: '#1e293b', color: '#e2e8f0', cursor: 'pointer',
        }, label);
        b.type = 'button';
        b.addEventListener('click', onClick);
        return b;
    }

    function message(text) {
        data = null;
        renderPanel([]);
        panel.appendChild(make('div', null, text));
    }

    function close() {
        if (root && root.parentNode) root.parentNode.removeChild(root);
        if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
        w.removeEventListener('resize', schedule);
        root = panel = layer = null;
    }

    var pending = null;
    function schedule() {
        if (pending) w.clearTimeout(pending);
        pending = w.setTimeout(function () { pending = null; draw(); }, 150);
    }

    function start() {
        buildRoot();
        message('Heatmap laden…');
        w.fetch(base + '/overlay', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ t: token }),
            credentials: 'omit',
            cache: 'no-store',
            referrerPolicy: 'no-referrer',
        }).then(function (res) {
            return res.json().then(function (body) { return { status: res.status, body: body }; }, function () { return { status: res.status, body: {} }; });
        }).then(function (r) {
            var result = r.body && r.body.result;
            if (r.status === 403) return message('De heatmaplink is verlopen of hoort niet bij deze site. Open de heatmap opnieuw vanuit het portaal.');
            if (r.status !== 200 || !result) return message('De heatmap kon niet geladen worden. Probeer het later opnieuw.');
            if (result.available === false) return message('Deze site meet nog niet met FullStack Hub Analytics.');
            data = { path: String(result.path || ''), viewport: String(result.viewport || ''), range: String(result.range || ''), grid: Number(result.grid) || 10,
                clicks: Number(result.clicks) || 0, consentShare: Number(result.consentShare) || 0,
                elements: Array.isArray(result.elements) ? result.elements.filter(function (e) { return e && typeof e.selector === 'string'; }) : [],
                scroll: Array.isArray(result.scroll) ? result.scroll : [] };
            draw();
            w.addEventListener('resize', schedule);
            // Late content (images, fonts, lazy sections) moves elements: redraw a few times in the first seconds.
            [800, 2500, 6000].forEach(function (ms) { w.setTimeout(draw, ms); });
        }, function () {
            message('De heatmap kon niet geladen worden. Probeer het later opnieuw.');
        });
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start);
    else start();
})(window, document);

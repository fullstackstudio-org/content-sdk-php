/*
 * Consent loader of the site standards (module `site`), printed inline (with the page nonce) by `<x-content::head />` and
 * `<x-content::body-end />`. `__CONFIG__` is replaced by `{key, event, all}` before printing; this comment is stripped.
 *
 * Customer code that needs consent is an inert `<template data-fsc-code data-consent="analytics|marketing|preferences">`. Once the
 * visitor accepted that category, the template is replaced by its content; every `<script>` is re-created (attributes copied, the
 * nonce added) so the browser runs it, one at a time in source order (an external script without `async` is awaited), and every
 * `<style>` gets the nonce.
 *
 * Consent is read from `localStorage[key]` (default `fsc-consent`, the same key the analytics helper reads) and from the `window`
 * event `event` (default `fsc:consent`). Understood: `accepted`/`all`/`true` (every category), `declined`/`necessary`/`none`/`false`
 * (none), `{categories: [...]}`, `{choice: 'all'|'none'|'custom', categories}`, `{analytics: true, marketing: false, ...}`
 * (`statistics` counts as analytics), a list, and the same as JSON text. Granting is one-way within a page view: code that ran cannot
 * be unloaded, a revoked choice applies from the next page. Idempotent: a second copy on the page only scans again.
 */
(function (w, d) {
    if (w.fscCode) { w.fscCode.scan(); return; }
    var c = __CONFIG__, cs = d.currentScript, n = cs ? (cs.nonce || cs.getAttribute('nonce') || '') : '', ok = {};
    function cats(v) {
        if (v === true) return c.all;
        if (v === false) return [];
        if (v == null || v === '') return null;
        if (typeof v === 'object') {
            if (Array.isArray(v)) return v.filter(function (x) { return typeof x === 'string'; });
            if (v.choice === 'all') return c.all;
            if (v.choice === 'none') return [];
            if (Array.isArray(v.categories)) return cats(v.categories);
            if ('accepted' in v && typeof v.accepted !== 'object') return cats(v.accepted);
            var r = [], f = false;
            for (var i = 0; i < c.all.length; i++) {
                var k = c.all[i], b = v[k];
                if (k === 'analytics' && typeof b !== 'boolean') b = v.statistics;
                if (typeof b === 'boolean') { f = true; if (b) r.push(k); }
            }
            return f ? r : null;
        }
        var t = String(v).trim().toLowerCase();
        if (/^(accepted|accept|all|granted|true|1|yes)$/.test(t)) return c.all;
        if (/^(declined|decline|rejected|denied|necessary|none|false|0|no|revoked)$/.test(t)) return [];
        if (t.charAt(0) === '{' || t.charAt(0) === '[') { try { return cats(JSON.parse(String(v))); } catch (e) { return null; } }
        return null;
    }
    function fresh(o) {
        var s = d.createElement('script');
        for (var i = 0; i < o.attributes.length; i++) { var a = o.attributes[i]; if (a.name !== 'nonce') s.setAttribute(a.name, a.value); }
        if (n) s.nonce = n;
        if (!o.hasAttribute('async')) s.async = false;
        s.text = o.text;
        return s;
    }
    // Scripts run strictly in source order, across templates: an external script without `async` is awaited (load or error, at most
    // 15 s) before the next script is inserted, so an inline init after its library works like in the original markup.
    var queue = [], busy = false;
    function next() {
        if (busy) return;
        var job = queue.shift();
        if (!job) return;
        var s = fresh(job[1]), p = job[0];
        if (s.getAttribute('src') && !s.hasAttribute('async')) {
            busy = true;
            var done = false, go = function () { if (done) return; done = true; busy = false; next(); };
            s.onload = go; s.onerror = go;
            w.setTimeout(go, 15000);
            if (p.parentNode) p.parentNode.replaceChild(s, p); else go();
        } else {
            if (p.parentNode) p.parentNode.replaceChild(s, p);
            next();
        }
    }
    function on(t) {
        var f = d.importNode(t.content, true), l = f.querySelectorAll('script,style');
        for (var i = 0; i < l.length; i++) {
            var e = l[i];
            if (e.tagName === 'SCRIPT') { var p = d.createComment(''); e.parentNode.replaceChild(p, e); queue.push([p, e]); }
            else if (n) e.nonce = n;
        }
        t.parentNode.insertBefore(f, t);
        t.parentNode.removeChild(t);
        next();
    }
    function scan() {
        var l = d.querySelectorAll('template[data-fsc-code]');
        for (var i = 0; i < l.length; i++) if (ok[l[i].getAttribute('data-consent')] === true) on(l[i]);
    }
    function grant(v) {
        var r = cats(v);
        if (!r) return;
        for (var i = 0; i < r.length; i++) if (c.all.indexOf(r[i]) !== -1) ok[r[i]] = true;
        scan();
    }
    try { grant(w.localStorage.getItem(c.key)); } catch (e) {}
    w.addEventListener(c.event, function (e) { grant(e && 'detail' in e ? e.detail : null); });
    w.addEventListener('storage', function (e) { if (e.key === c.key) grant(e.newValue); });
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', scan);
    w.fscCode = { scan: scan, grant: grant, granted: function (k) { return ok[k] === true; } };
})(window, document);

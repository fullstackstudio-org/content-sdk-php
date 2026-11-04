/*
 * FullStack Hub Analytics tracker (fsa.js), served by the PHP SDK at /_fsc/a/fsa.js and rendered by <x-content::analytics /> when the
 * Hub module `analytics` runs the native engine (`engine: native` or `both`). Events go to the site itself (POST /_fsc/a/e); the SDK
 * proxy signs them and forwards them to the collector inside the cluster.
 *
 * Privacy (binding, plan analytics-engine D5):
 * - No cookies, ever. Without consent nothing is stored on the device: the collector counts visitors with a daily rotating hash.
 * - With consent (`analytics` category) a random id is kept in localStorage (`fsa-id`), so visits connect over days, and heatmap clicks
 *   are sent. Not when the browser sends Global Privacy Control. Revoking consent deletes the id at once.
 * - URLs keep only the path and utm_* / ad click id parameters; e-mail addresses and phone numbers are replaced in paths, titles and
 *   props. Form field values are never read. No session recordings.
 *
 * API: fsa.track(name, props), fsa.revenue(amount, currency, props), fsa.pageview(), fsa.setConsent(value).
 * Declarative: data-fsa-event="cta_click" (+ data-fsa-prop-<key>="value") on any clickable element.
 */
(function (w, d) {
    'use strict';

    if (w.fsa && w.fsa.__loaded) return;

    var me = d.currentScript;
    var data = (me && me.dataset) || {};
    var KEY = data.sitekey || '';
    if (!KEY) return;

    var ENDPOINT = data.endpoint || '/_fsc/a/e';
    var CONSENT_KEY = data.consentKey || 'fsc-consent';
    var CONSENT_EVENT = data.consentEvent || 'fsc:consent';
    var ID_KEY = 'fsa-id';
    var PARAMS = (data.params || 'utm_source,utm_medium,utm_campaign,utm_term,utm_content,gclid,gbraid,wbraid,dclid,gclsrc,fbclid,msclkid,ttclid,li_fat_id,twclid,rdt_cid,epik,sccid').split(',');
    var nav = w.navigator || {};
    var loc = w.location;
    var stub = w.fsa;

    var MAX_BATCH = 50;
    var FLUSH_MS = 1000;
    var HEARTBEAT_MS = 60000;
    var IDLE_MS = 30000;
    var MAX_HEATMAP = 100;
    var DOWNLOAD_RE = /\.(pdf|zip|rar|7z|gz|docx?|xlsx?|pptx?|csv|txt|rtf|odt|ods|odp|epub|dmg|exe|msi|apk|mp3|mp4|mov|avi|wav)$/i;
    var EMAIL_RE = /[a-z0-9._%+-]+(?:@|%40)[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/gi;
    var PHONE_RE = /(^|[^0-9+])((?:\+|00)[1-9](?:[\s().-]?\d){7,13}|0[1-9](?:[\s().-]?\d){7,9})(?=$|[^0-9])/g;
    var PII_KEY_RE = /^name$|(^|[._])(e?mail|email_?address|phone|tel|telephone|mobile|first_?name|last_?name|full_?name|user_?name|contact_?name|address|street|zip|postcode|password|iban|bsn|birth_?date|dob|ip)($|[._])/;
    var WHATSAPP = { 'wa.me': 1, 'api.whatsapp.com': 1, 'web.whatsapp.com': 1 };

    function now() { return Date.now(); }

    function str(value, max) {
        return String(value == null ? '' : value).replace(/[\u0000-\u001F\u007F]/g, '').slice(0, max);
    }

    /** E-mail addresses and phone numbers out of any text that leaves the page. */
    function scrub(text) {
        return String(text).replace(EMAIL_RE, '[email]').replace(PHONE_RE, '$1[tel]').replace(PHONE_RE, '$1[tel]');
    }

    function parse(href) {
        try { return new (w.URL || URL)(href, loc.href); } catch (e) { return null; }
    }

    function bareHost(host) { return String(host || '').toLowerCase().replace(/^www\./, ''); }

    /** The page URL as sent: origin + scrubbed path + only the campaign parameters. */
    function pageUrl(href) {
        var u = parse(href);
        if (!u) return '';
        var keep = [];
        PARAMS.forEach(function (p) {
            var v = u.searchParams.get(p);
            if (v) keep.push(encodeURIComponent(p) + '=' + encodeURIComponent(str(scrub(v), 200)));
        });
        return u.origin + scrub(u.pathname) + (keep.length ? '?' + keep.join('&') : '');
    }

    function refUrl(href) {
        var u = href ? parse(href) : null;
        return u && /^https?:$/.test(u.protocol) ? u.origin + scrub(u.pathname) : '';
    }

    // --- Consent (same contract as the SDK helper: localStorage key + window event) ----------------------------------------------

    function interpret(value) {
        if (value === true || value === false) return value;
        if (value == null || value === '') return null;
        if (typeof value === 'object') {
            if (typeof value.analytics === 'boolean') return value.analytics;
            if (typeof value.statistics === 'boolean') return value.statistics;
            if (value.choice === 'all') return true;
            if (value.choice === 'none') return false;
            if (Array.isArray(value.categories)) return value.categories.indexOf('analytics') !== -1 || value.categories.indexOf('statistics') !== -1;
            if ('accepted' in value) return interpret(value.accepted);
            return null;
        }
        var text = String(value).trim().toLowerCase();
        if (/^(accepted|accept|all|granted|true|1|yes)$/.test(text)) return true;
        if (/^(declined|decline|rejected|denied|necessary|false|0|no|revoked)$/.test(text)) return false;
        if (text.charAt(0) === '{' || text.charAt(0) === '[') {
            try { return interpret(JSON.parse(text)); } catch (e) { return null; }
        }
        return null;
    }

    function storage() {
        try { return w.localStorage || null; } catch (e) { return null; }
    }

    var consent = false;
    try { consent = interpret(storage() && storage().getItem(CONSENT_KEY)) === true; } catch (e) { consent = false; }
    var gpc = nav.globalPrivacyControl === true;

    function persistentId() {
        var s = storage();
        if (!consent || gpc || !s) return '';
        try {
            var id = s.getItem(ID_KEY);
            if (!id || !/^[A-Za-z0-9-]{16,64}$/.test(id)) {
                var c = w.crypto;
                id = c && c.randomUUID ? c.randomUUID() : (now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 32);
                s.setItem(ID_KEY, id);
            }
            return id;
        } catch (e) {
            return '';
        }
    }

    function setConsent(value) {
        var granted = interpret(value);
        if (granted === null) return;
        consent = granted;
        if (!granted) {
            try { var s = storage(); if (s) s.removeItem(ID_KEY); } catch (e) { /* storage blocked */ }
        }
    }

    w.addEventListener(CONSENT_EVENT, function (event) { setConsent(event && 'detail' in event ? event.detail : null); });
    w.addEventListener('storage', function (event) {
        if (event && event.key === CONSENT_KEY) setConsent(event.newValue == null ? false : event.newValue);
    });

    // --- Queue and transport -----------------------------------------------------------------------------------------------------

    var queue = [];
    var timer = null;

    function send(body) {
        try {
            if (nav.sendBeacon && nav.sendBeacon(ENDPOINT, new (w.Blob || Blob)([body], { type: 'text/plain' }))) return;
        } catch (e) { /* fall through to fetch */ }
        try {
            if (w.fetch) {
                w.fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } })
                    .catch(function () { /* analytics never breaks a page */ });
            }
        } catch (e) { /* ignore */ }
    }

    function flush() {
        if (timer) { w.clearTimeout(timer); timer = null; }
        while (queue.length) {
            var batch = { k: KEY, c: consent ? 1 : 0, wd: nav.webdriver === true, vw: w.innerWidth || 0, vh: w.innerHeight || 0, lang: str(nav.language, 35), e: queue.splice(0, MAX_BATCH) };
            var id = persistentId();
            if (id) batch.pid = id;
            send(JSON.stringify(batch));
        }
    }

    function push(event) {
        event.ts = now();
        if (!event.u) event.u = current;
        queue.push(event);
        if (queue.length >= MAX_BATCH) flush();
        else if (!timer) timer = w.setTimeout(flush, FLUSH_MS);
    }

    /** Keys [a-z0-9_.], max 30; strings scrubbed and max 200; numbers finite; booleans. Keys that name personal data are dropped. */
    function cleanProps(props) {
        var out = {};
        var n = 0;
        if (!props || typeof props !== 'object') return out;
        Object.keys(props).forEach(function (raw) {
            var key = String(raw).toLowerCase();
            var value = props[raw];
            if (n >= 30 || !/^[a-z0-9_.]{1,40}$/.test(key) || PII_KEY_RE.test(key)) return;
            if (typeof value === 'string') value = str(scrub(value.trim()), 200);
            else if (typeof value === 'number') { if (!isFinite(value)) return; }
            else if (typeof value !== 'boolean') return;
            out[key] = value;
            n++;
        });
        return out;
    }

    // --- Pageviews, engagement and scroll depth ----------------------------------------------------------------------------------

    var current = '';
    var href = '';
    var engaged = 0;
    var lastTick = now();
    var lastInput = now();
    var scrollMax = 0;
    var scrollSentMax = 0;
    var thresholds = {};
    var heatmaps = 0;
    var clicks = [];

    function visible() { return d.visibilityState !== 'hidden'; }

    function tick() {
        var t = now();
        if (visible() && t - lastInput < IDLE_MS) engaged += Math.min(t - lastTick, IDLE_MS);
        lastTick = t;
    }

    function heartbeat() {
        tick();
        if (!current || (engaged < 1000 && scrollMax <= scrollSentMax)) return;
        push({ t: 'heartbeat', u: current, d: Math.round(engaged), sd: scrollMax });
        engaged = 0;
        scrollSentMax = scrollMax;
    }

    function pageview() {
        var next = pageUrl(loc.href);
        if (!next || next === current) return;
        var referrer = current ? href : d.referrer;
        if (current) heartbeat();
        current = next;
        href = loc.href;
        scrollMax = 0;
        scrollSentMax = 0;
        thresholds = {};
        heatmaps = 0;
        engaged = 0;
        lastTick = now();
        push({ t: 'pageview', u: current, r: refUrl(referrer), ti: str(scrub(d.title || ''), 200) });
    }

    function onScroll() {
        lastInput = now();
        var root = d.documentElement || {};
        var height = Math.max(root.scrollHeight || 0, (d.body && d.body.scrollHeight) || 0);
        var seen = (w.scrollY || w.pageYOffset || 0) + (w.innerHeight || 0);
        if (height <= 0) return;
        var pct = Math.min(100, Math.round(seen / height * 100));
        if (pct > scrollMax) scrollMax = pct;
        [25, 50, 75, 100].forEach(function (mark) {
            if (pct >= mark && !thresholds[mark]) {
                thresholds[mark] = 1;
                push({ t: 'scroll', sd: mark });
            }
        });
    }

    // --- Clicks: outbound, downloads, declarative events, heatmap (consent only) -------------------------------------------------

    function closest(el, selector) {
        return el && el.closest ? el.closest(selector) : null;
    }

    function selector(el) {
        var parts = [];
        for (var i = 0; el && el.tagName && i < 4; i++) {
            var part = el.tagName.toLowerCase();
            if (el.id && /^[A-Za-z][\w-]{0,40}$/.test(el.id)) { parts.unshift(part + '#' + el.id); break; }
            var cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(function (c) { return /^[A-Za-z][\w-]{0,30}$/.test(c); }).slice(0, 2) : [];
            parts.unshift(part + (cls.length ? '.' + cls.join('.') : ''));
            el = el.parentElement;
        }
        return str(scrub(parts.join(' > ')), 300);
    }

    function heatmap(event) {
        if (!consent || heatmaps >= MAX_HEATMAP || !event.target || !event.target.getBoundingClientRect) return;
        var el = event.target;
        var rect = el.getBoundingClientRect();
        if (!rect || rect.width <= 0 || rect.height <= 0) return;
        var x = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
        var y = Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height));
        var t = now();
        clicks = clicks.filter(function (c) { return t - c.t < 700; });
        clicks.push({ t: t, x: event.clientX, y: event.clientY });
        var near = clicks.filter(function (c) { return Math.abs(c.x - event.clientX) < 30 && Math.abs(c.y - event.clientY) < 30; });
        var kind = 'click';
        if (near.length >= 3) {
            if (near.length > 3) return; // one rage marker per burst
            kind = 'rage';
        }
        heatmaps++;
        push({ t: 'heatmap', sel: selector(el), x: Math.round(x * 1000) / 1000, y: Math.round(y * 1000) / 1000, hk: kind });
    }

    function onClick(event) {
        lastInput = now();
        if (event.type === 'click') heatmap(event);

        var marked = closest(event.target, '[data-fsa-event]');
        if (marked && event.type === 'click') {
            var props = {};
            var attrs = marked.attributes || [];
            for (var i = 0; i < attrs.length; i++) {
                var m = /^data-fsa-prop-(.+)$/.exec(attrs[i].name);
                if (m) props[m[1].replace(/-/g, '_')] = attrs[i].value;
            }
            track(marked.getAttribute('data-fsa-event'), props);
        }

        var link = closest(event.target, 'a[href]');
        if (!link) return;
        var raw = link.getAttribute('href') || '';
        var u = parse(raw);
        if (!u) return;
        if (u.protocol === 'mailto:' || u.protocol === 'tel:') {
            push({ t: 'outbound', p: { kind: u.protocol.slice(0, -1) } });
        } else if (!/^https?:$/.test(u.protocol)) {
            return;
        } else if (WHATSAPP[u.hostname.toLowerCase()]) {
            push({ t: 'outbound', p: { kind: 'whatsapp' } });
        } else if (link.hasAttribute && link.hasAttribute('download') || DOWNLOAD_RE.test(u.pathname)) {
            var file = u.pathname.split('/').pop() || '';
            push({ t: 'download', p: { file: str(scrub(decodeURIComponent(file)), 200), ext: (DOWNLOAD_RE.exec(u.pathname) || ['', ''])[1].toLowerCase(), host: u.hostname.toLowerCase() } });
        } else if (bareHost(u.hostname) !== bareHost(loc.hostname)) {
            push({ t: 'outbound', p: { kind: 'link', host: u.hostname.toLowerCase(), path: str(scrub(u.pathname), 200) } });
        } else {
            return;
        }
        // The page may unload right after this click.
        flush();
    }

    // --- Forms: started (first interaction) and submitted; never a field value ---------------------------------------------------

    var started = {};

    function formId(form) {
        var id = form.getAttribute('data-fsc-form') || form.getAttribute('data-form-key') || form.id || form.getAttribute('name');
        if (!id) {
            var action = parse(form.getAttribute('action') || loc.href);
            id = action ? action.pathname : 'form';
        }
        return str(scrub(id), 100);
    }

    function formOf(target) {
        if (!target) return null;
        var form = target.tagName === 'FORM' ? target : (target.form || closest(target, 'form'));
        return form && !(form.hasAttribute && form.hasAttribute('data-fsa-ignore')) ? form : null;
    }

    function onFocus(event) {
        var target = event.target;
        if (!target || !/^(INPUT|SELECT|TEXTAREA)$/.test(target.tagName || '')) return;
        var form = formOf(target);
        if (!form) return;
        var id = formId(form);
        if (started[id]) return;
        started[id] = 1;
        push({ t: 'form_start', p: { form: id } });
    }

    function onSubmit(event) {
        var form = formOf(event.target);
        if (!form) return;
        var id = formId(form);
        push({ t: 'form_submit', p: { form: id } });
        flush();
    }

    // --- Public API --------------------------------------------------------------------------------------------------------------

    function track(name, props) {
        name = str(name, 64);
        if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(name)) return;
        push({ t: 'event', n: name, p: cleanProps(props) });
    }

    function revenue(amount, currency, props) {
        var value = Number(amount);
        var cur = String(currency || '').toUpperCase();
        if (!isFinite(value) || value <= 0 || !/^[A-Z]{3}$/.test(cur)) return;
        var clean = cleanProps(props);
        var name = typeof clean.name === 'string' && /^[A-Za-z0-9_.:-]{1,64}$/.test(clean.name) ? clean.name : 'purchase';
        delete clean.name;
        push({ t: 'revenue', n: name, amt: Math.round(value * 10000) / 10000, cur: cur, p: clean });
        flush();
    }

    // --- Wiring ------------------------------------------------------------------------------------------------------------------

    function wrap(method) {
        var hist = w.history;
        var original = hist && hist[method];
        if (typeof original !== 'function') return;
        hist[method] = function () {
            var result = original.apply(this, arguments);
            w.setTimeout(pageview, 0);
            return result;
        };
    }

    wrap('pushState');
    wrap('replaceState');
    w.addEventListener('popstate', function () { w.setTimeout(pageview, 0); });
    w.addEventListener('scroll', onScroll, { passive: true });
    ['mousemove', 'keydown', 'touchstart', 'pointerdown'].forEach(function (type) {
        w.addEventListener(type, function () { tick(); lastInput = now(); }, { passive: true });
    });
    d.addEventListener('click', onClick, true);
    d.addEventListener('auxclick', onClick, true);
    d.addEventListener('focusin', onFocus, true);
    d.addEventListener('submit', onSubmit, true);
    d.addEventListener('visibilitychange', function () {
        if (visible()) { lastTick = now(); return; }
        heartbeat();
        flush();
    });
    w.addEventListener('pagehide', function () { heartbeat(); flush(); });
    w.setInterval(function () { tick(); }, 5000);
    w.setInterval(function () { if (visible()) heartbeat(); }, HEARTBEAT_MS);

    pageview();

    // 404: the SDK renders data-status="404" on error pages; modern browsers also report the navigation status.
    var status = parseInt(data.status || '0', 10);
    if (!status) {
        try {
            var entry = w.performance && w.performance.getEntriesByType ? w.performance.getEntriesByType('navigation')[0] : null;
            status = entry && entry.responseStatus ? entry.responseStatus : 0;
        } catch (e) { status = 0; }
    }
    if (status === 404) track('not_found', {});

    w.fsa = {
        __loaded: true,
        track: track,
        revenue: revenue,
        pageview: pageview,
        setConsent: setConsent,
        hasConsent: function () { return consent; },
        flush: flush,
    };

    // Calls made before the tracker loaded: window.fsa = window.fsa || function () { (fsa.q = fsa.q || []).push(arguments); };
    var pending = stub && stub.q && stub.q.length ? stub.q : [];
    for (var i = 0; i < pending.length; i++) {
        var args = Array.prototype.slice.call(pending[i]);
        var fn = w.fsa[args.shift()];
        if (typeof fn === 'function' && fn !== w.fsa.flush) fn.apply(null, args);
    }
})(window, document);

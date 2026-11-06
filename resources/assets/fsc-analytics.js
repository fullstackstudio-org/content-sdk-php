/*
 * Fullstack Content analytics helper (served by the PHP SDK at /_fsc/a/fsc.js, rendered by <x-content::analytics />).
 *
 * Rules (binding, also in AGENTS.md):
 * - The Umami tracker (pageviews + custom events) is cookieless and runs WITHOUT consent. It is a separate deferred tag.
 * - Heatmaps and session replay (Umami recorder.js) start ONLY after the visitor accepted cookies. Revoking consent stops every upload at
 *   once (the record endpoint is blocked in this page) and the recorder is not loaded on later pages.
 * - First-touch attribution (utm_*, ad click ids, landing URL, external referrer) is kept in sessionStorage and added to forms.
 * - With the native engine (fsa.js) `fscAnalytics.track()` and `setConsent()` reach that tracker too.
 * - Conversion markers: `<x-content::begin-checkout />`, `<x-content::appointment-booked />` and `<x-content::purchase />` print an inert
 *   `<span hidden data-fsc-fire="...">`; this helper fires each marker once per page (a purchase or booking with an order/booking reference
 *   also once per browser session) through `fscAnalytics.track()` / `fscAnalytics.revenue()`. Events are session-level: no consent needed.
 *
 * Consent wiring for a site's own banner (any of these):
 *   window.dispatchEvent(new CustomEvent('fsc:consent', { detail: 'accepted' }));   // or 'declined', true/false, { analytics: true },
 *                                                                                 // { categories: ['analytics'] }
 *   window.fscAnalytics.setConsent(true);                                          // same as the event
 *   localStorage.setItem('fsc-consent', 'accepted');                               // read on every page load (key configurable)
 */
(function (w, d) {
    'use strict';

    var me = d.currentScript;
    var data = (me && me.dataset) || {};
    var CONSENT_KEY = data.consentKey || 'fsc-consent';
    var CONSENT_EVENT = data.consentEvent || 'fsc:consent';
    var ATTRIBUTION_KEY = data.attributionKey || 'fsc-attribution';
    var TRACKER_ID = data.tracker || 'fsc-analytics';
    var PARAMS = (data.params || 'utm_source,utm_medium,utm_campaign,utm_term,utm_content,gclid,gbraid,wbraid,dclid,gclsrc,fbclid,msclkid,ttclid,li_fat_id,twclid,rdt_cid,epik,sccid')
        .split(',')
        .map(function (p) { return p.trim(); })
        .filter(Boolean);

    if (w.fscAnalytics && w.fscAnalytics.__loaded) {
        return;
    }

    // Newsletter click links (plan ootb-integrations D9): `fscn=<token>` from the Hub's redirect leaves the address bar before the landing
    // URL is kept for attribution (fsa.js does the same; whichever runs first). The site server already read it.
    try {
        var search = String((w.location || {}).search || '');
        if (/[?&]fscn=/.test(search)) {
            var kept = search.replace(/^\?/, '').split('&').filter(function (part) { return part !== '' && part.indexOf('fscn=') !== 0; }).join('&');
            w.history.replaceState(w.history.state, '', w.location.pathname + (kept ? '?' + kept : '') + (w.location.hash || ''));
        }
    } catch (e) { /* no history API: nothing else depends on it */ }

    // Heatmap overlay (plan analytics-engine, phase 4): the portal opened this page for staff (fsa.js sets fsaOverlayMode and takes the
    // token out of the URL; the hash check covers a page without fsa.js). Staff looking at a heatmap is no visitor: no recorder, no
    // replay, no attribution, no counting. The API stays, as no-ops, so site code keeps working.
    // The same stand-down for an excluded browser (`?fsa_exclude=1` stored `localStorage['fsa-exclude']`; fsa.js sets `fsaExcluded`; the storage and
    // query checks cover a page where fsc.js runs first).
    var excludedBrowser = false;
    try {
        excludedBrowser = !!w.fsaExcluded || /(?:^\?|&)fsa_exclude=1(?=&|$)/.test(String((w.location || {}).search || '')) || (w.localStorage && w.localStorage.getItem('fsa-exclude') === '1');
    } catch (e) { excludedBrowser = !!w.fsaExcluded; }
    if (excludedBrowser || w.fsaOverlayMode || /(?:^#|&)fsa_overlay=/.test(String((w.location || {}).hash || ''))) {
        var none = function () {};
        w.fscAnalytics = { __loaded: true, overlay: true, setConsent: none, hasConsent: function () { return false; }, attribution: function () { return null; }, track: none, revenue: none, scan: none };
        return;
    }

    function clean(value, max) {
        return String(value == null ? '' : value).replace(/[\u0000-\u001F\u007F]/g, '').slice(0, max);
    }

    // --- Consent ---------------------------------------------------------------------------------------------------------------

    /** true / false / null (no choice yet) from a stored value or an event detail. */
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
        if (text === 'accepted' || text === 'accept' || text === 'all' || text === 'granted' || text === 'true' || text === '1' || text === 'yes') return true;
        if (text === 'declined' || text === 'decline' || text === 'rejected' || text === 'denied' || text === 'necessary' || text === 'false' || text === '0' || text === 'no' || text === 'revoked') return false;
        if (text.charAt(0) === '{' || text.charAt(0) === '[') {
            try { return interpret(JSON.parse(text)); } catch (e) { return null; }
        }
        return null;
    }

    function storedConsent() {
        try {
            return interpret(w.localStorage.getItem(CONSENT_KEY));
        } catch (e) {
            return null;
        }
    }

    var consent = storedConsent() === true;

    // The recorder posts to <host>/api/record. Without consent nothing may leave the page: answer locally instead.
    function isRecordUrl(input) {
        var url = typeof input === 'string' ? input : (input && (input.url || input.href)) || '';
        return /\/api\/record(?:[?#]|$)/.test(String(url));
    }

    if (typeof w.fetch === 'function' && !w.fetch.__fscGuard) {
        var nativeFetch = w.fetch;
        var guarded = function (input, init) {
            if (!consent && isRecordUrl(input)) {
                return Promise.resolve(new w.Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }));
            }
            return nativeFetch.apply(this, arguments);
        };
        guarded.__fscGuard = true;
        w.fetch = guarded;
    }

    function tracker() {
        return d.getElementById(TRACKER_ID);
    }

    function startRecorder() {
        var tag = tracker();
        var src = tag && tag.getAttribute('data-fsc-recorder-src');
        if (!tag || !src || d.getElementById(TRACKER_ID + '-recorder')) return false;

        var inject = function () {
            if (!consent || d.getElementById(TRACKER_ID + '-recorder')) return;
            var script = d.createElement('script');
            script.id = TRACKER_ID + '-recorder';
            script.defer = true;
            script.src = src;
            script.setAttribute('data-website-id', tag.getAttribute('data-website-id') || '');
            var host = tag.getAttribute('data-host-url');
            if (host) script.setAttribute('data-host-url', host);
            if (tag.nonce) script.nonce = tag.nonce;
            script.onerror = function () { script.remove(); };
            d.head.appendChild(script);
        };

        // The recorder needs the tracker's session: inject after the tracker ran (it may already have).
        if (w.umami) inject();
        else tag.addEventListener('load', inject, { once: true });

        return true;
    }

    function setConsent(value) {
        var granted = interpret(value);
        if (granted === null) return;
        consent = granted;
        // The native tracker (fsa.js) listens to the same event and storage key; a direct call must reach it too.
        try { if (w.fsa && typeof w.fsa.setConsent === 'function') w.fsa.setConsent(granted); } catch (e) { /* never break a banner */ }
        if (granted) {
            startRecorder();
        } else {
            // Uploads are blocked from now on (fetch guard). The script element goes too, so nothing reloads it.
            var rec = d.getElementById(TRACKER_ID + '-recorder');
            if (rec) rec.remove();
        }
    }

    w.addEventListener(CONSENT_EVENT, function (event) {
        setConsent(event && 'detail' in event ? event.detail : null);
    });

    // A banner on another tab changed the stored choice.
    w.addEventListener('storage', function (event) {
        if (event.key === CONSENT_KEY) setConsent(event.newValue == null ? false : event.newValue);
    });

    if (consent) startRecorder();

    // --- Attribution (first touch within a visit) ------------------------------------------------------------------------------

    function capture() {
        try {
            var existing = w.sessionStorage.getItem(ATTRIBUTION_KEY);
            if (existing) return JSON.parse(existing);
        } catch (e) {
            // Storage blocked or broken: use the current page only.
        }

        var touch = { landing_url: clean(w.location.pathname + w.location.search, 1024) };
        var params;
        try { params = new URLSearchParams(w.location.search); } catch (e) { params = null; }
        if (params) {
            PARAMS.forEach(function (key) {
                var value = clean(params.get(key), 200);
                if (value) touch[key] = value;
            });
        }

        var referrer = d.referrer || '';
        try {
            if (referrer && new URL(referrer).origin !== w.location.origin) touch.referrer = clean(referrer, 1024);
        } catch (e) {
            // Unparseable referrer: ignore.
        }

        try {
            w.sessionStorage.setItem(ATTRIBUTION_KEY, JSON.stringify(touch));
        } catch (e) {
            // Not persistable: still returned for this page.
        }

        return touch;
    }

    var touch = capture();

    /** The attribution as `_meta` keys of a Content form submission (empty values left out). */
    function attribution() {
        var out = {};
        Object.keys(touch).forEach(function (key) {
            if (touch[key]) out[key] = touch[key];
        });
        return out;
    }

    // Every SDK form (`<x-content::form>`) and every form with `data-fsc-attribution` gets the attribution as a hidden field.
    d.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if (!(form.classList.contains('fsc-form') || form.hasAttribute('data-fsc-attribution'))) return;
        var field = form.querySelector('input[name="_fsc_attribution"]');
        if (!field) {
            field = d.createElement('input');
            field.type = 'hidden';
            field.name = '_fsc_attribution';
            form.appendChild(field);
        }
        field.value = JSON.stringify(attribution());
    }, true);

    // --- Events and conversion markers -----------------------------------------------------------------------------------------

    /** True while at least one tracker can take an event. */
    function engineReady() {
        return !!((w.fsa && typeof w.fsa.track === 'function') || (w.umami && typeof w.umami.track === 'function'));
    }

    /** One call for every engine: Umami and/or the native tracker (engine `both` counts in both). */
    function track(name, payload) {
        var result;
        try {
            if (w.fsa && typeof w.fsa.track === 'function') w.fsa.track(name, payload);
        } catch (e) {
            // Analytics never breaks a user action.
        }
        try {
            if (w.umami && typeof w.umami.track === 'function') result = w.umami.track(name, payload);
        } catch (e) {
            // Idem.
        }
        return result;
    }

    /**
     * A revenue event for every engine. The native tracker has a revenue event type (`fsa.revenue`); Umami gets the event with Umami's own
     * `revenue` and `currency` properties. `props.name` (default `purchase`) names the event.
     */
    function revenue(amount, currency, props) {
        var value = Number(amount);
        var cur = String(currency || '').toUpperCase();
        if (!isFinite(value) || value <= 0 || !/^[A-Z]{3}$/.test(cur)) return;
        var extra = {};
        Object.keys(props || {}).forEach(function (key) { extra[key] = props[key]; });
        var name = typeof extra.name === 'string' && /^[A-Za-z0-9_.:-]{1,64}$/.test(extra.name) ? extra.name : 'purchase';
        delete extra.name;
        try {
            if (w.fsa && typeof w.fsa.revenue === 'function') w.fsa.revenue(value, cur, Object.assign({ name: name }, extra));
        } catch (e) {
            // Analytics never breaks a page.
        }
        try {
            if (w.umami && typeof w.umami.track === 'function') w.umami.track(name, Object.assign({}, extra, { revenue: value, currency: cur }));
        } catch (e) {
            // Idem.
        }
    }

    var PROP_PREFIX = 'data-fsc-prop-';
    var EVENT_NAME = /^[A-Za-z0-9_.:-]{1,64}$/;

    /** The props of a marker: `data-fsc-prop-order_ref="A-1"` becomes `{order_ref: 'A-1'}`. Values are strings, numbers, booleans. */
    function markerProps(el) {
        var out = {};
        var attrs = el.attributes || [];
        for (var i = 0; i < attrs.length; i++) {
            var attr = attrs[i];
            if (!attr || String(attr.name).indexOf(PROP_PREFIX) !== 0) continue;
            var key = String(attr.name).slice(PROP_PREFIX.length).replace(/-/g, '_');
            if (key) out[key] = clean(attr.value, 200);
        }
        return out;
    }

    function onceKey(name, ref) {
        return 'fsc-fired:' + name + ':' + ref;
    }

    function alreadyFired(key) {
        try { return w.sessionStorage.getItem(key) === '1'; } catch (e) { return false; }
    }

    function remember(key) {
        try { w.sessionStorage.setItem(key, '1'); } catch (e) { /* storage blocked: the once-per-page mark still holds */ }
    }

    /** Fires one marker; returns true when it is settled (sent, or deliberately skipped), false when no tracker was ready yet. */
    function fireMarker(el) {
        if (el.getAttribute('data-fsc-fired') === '1') return true;
        var name = clean(el.getAttribute('data-fsc-fire'), 64);
        if (!EVENT_NAME.test(name)) { el.setAttribute('data-fsc-fired', '1'); return true; }
        if (!engineReady()) return false;

        // With a reference (order number, booking id) the event is also once per browser session: a reload of the thank-you page must
        // not count the order twice.
        var ref = clean(el.getAttribute('data-fsc-once'), 120);
        var key = ref ? onceKey(name, ref) : null;
        el.setAttribute('data-fsc-fired', '1');
        if (key && alreadyFired(key)) return true;

        var props = markerProps(el);
        var amount = el.getAttribute('data-fsc-revenue');
        if (amount !== null) {
            props.name = name;
            revenue(amount, el.getAttribute('data-fsc-currency'), props);
        } else {
            track(name, props);
        }
        if (key) remember(key);
        return true;
    }

    var scanQueued = false;

    /** Fires every marker on the page that did not fire yet. Markers wait for a tracker until the page has loaded. */
    function scan() {
        var nodes = d.querySelectorAll ? d.querySelectorAll('[data-fsc-fire]') : [];
        var waiting = false;
        for (var i = 0; i < nodes.length; i++) {
            if (!fireMarker(nodes[i])) waiting = true;
        }
        if (waiting && !scanQueued) {
            scanQueued = true;
            var retry = function () { scanQueued = false; scan(); };
            w.addEventListener('load', retry);
            var tag = tracker();
            if (tag) tag.addEventListener('load', retry, { once: true });
        }
    }

    if (d.readyState === 'loading' && d.addEventListener) d.addEventListener('DOMContentLoaded', scan);
    else scan();

    w.fscAnalytics = {
        __loaded: true,
        setConsent: setConsent,
        hasConsent: function () { return consent; },
        attribution: attribution,
        track: track,
        revenue: revenue,
        scan: scan,
    };
})(window, document);

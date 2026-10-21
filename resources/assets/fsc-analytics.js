/*
 * Fullstack Content analytics helper (served by the PHP SDK at /_fsc/a/fsc.js, rendered by <x-content::analytics />).
 *
 * Rules (binding, also in AGENTS.md):
 * - The Umami tracker (pageviews + custom events) is cookieless and runs WITHOUT consent. It is a separate deferred tag.
 * - Heatmaps and session replay (Umami recorder.js) start ONLY after the visitor accepted cookies. Revoking consent stops every upload at
 *   once (the record endpoint is blocked in this page) and the recorder is not loaded on later pages.
 * - First-touch attribution (utm_*, ad click ids, landing URL, external referrer) is kept in sessionStorage and added to forms.
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

    w.fscAnalytics = {
        __loaded: true,
        setConsent: setConsent,
        hasConsent: function () { return consent; },
        attribution: attribution,
        track: function (name, payload) {
            try {
                if (w.umami && typeof w.umami.track === 'function') return w.umami.track(name, payload);
            } catch (e) {
                // Analytics never breaks a user action.
            }
            return undefined;
        },
    };
})(window, document);

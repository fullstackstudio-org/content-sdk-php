/*
 * FullStack Hub Analytics tracker (fsa.js), served by the PHP SDK at /_fsc/a/fsa.js and rendered by <x-content::analytics /> when the
 * Hub module `analytics` runs the native engine (`engine: native` or `both`). Events go to the site itself (POST /_fsc/a/e); the SDK
 * proxy signs them and forwards them to the collector inside the cluster.
 *
 * Privacy (binding, plan analytics-engine D5):
 * - No cookies, ever. Without consent nothing is stored on the device: the collector counts visitors with a daily rotating hash.
 * - With consent (`analytics` category) heatmap clicks are sent and a random id is kept in localStorage (`fsa-id`), so visits connect
 *   over days. The id is never kept when the browser sends Global Privacy Control (heatmaps still follow consent). Without consent
 *   (also: revoked, or never given) any stored id is deleted.
 * - URLs keep only the path (max 500 characters) and utm_* / ad click id parameters; e-mail addresses, phone numbers and secret-looking
 *   segments (reset/share tokens, UUIDs, long hex) are replaced in paths, referrers, titles and props. Form field values are never read.
 *   No session recordings.
 * - Web Vitals (LCP, INP, CLS, FCP, TTFB) once per page load, when the page is hidden.
 * - Heatmap clicks (consent only): the element selector (id, an allow-listed data attribute or a short tag/class path; never text, never
 *   a field value), the position inside the element and inside the document, at most 100 per page view and 10 per element.
 * - Scroll map (no consent needed): one `scroll_max` event per page view with the deepest point reached, rounded down to 10%.
 * - Frustration signals (no consent needed, no personal data): `frustration` events rage_click (3+ clicks within 700 ms and 30 px),
 *   dead_click (click on a non-interactive element, no DOM change within 1 s), error_click (a JS error within 1 s after a click: only
 *   the error class and a hash of the normalised message, never the message or a stack) and quick_back (back within 5 s of arriving;
 *   needs the Navigation API or a same-document popstate). At most 20 per page view.
 * - Forms (no consent needed): `form_start` (first interaction) and `form_submit` with the form id (`data-fsa-form`, else the form's id/name/action)
 *   and, on submit, `seconds` since the start. With consent and without GPC also `form_field` events (the form id, the NAME of the field a
 *   visitor enters, seconds since the start): never a value, never password or hidden inputs. `data-fsa-ignore` on a form opts it out.
 *
 * - Site search (no consent needed): when the URL carries one of the site's search parameters (`data-search-params`, default q,s,search,zoeken)
 *   an `event` named `site_search` with the trimmed term (lower case, max 80 characters, e-mail addresses, phone numbers, IBANs and long
 *   numbers replaced) follows the pageview. The parameter itself is never part of the page URL (only utm_* / click ids are kept).
 * - Not found (no consent needed): `data-fsa-404` on <html> or <body> (or data-status="404", or a 404 navigation status) sends one
 *   `event` named `not_found` with the scrubbed path and the referrer host.
 * - Own visits: opening any page with `?fsa_exclude=1` stores `localStorage['fsa-exclude'] = '1'`; this browser then sends nothing at all
 *   (and the first-party Umami tracker and fsc.js stand down too). `?fsa_exclude=0` clears it. The parameter leaves the address bar at once.
 *
 * - Heatmap overlay: a page opened from the portal with `#fsa_overlay=<token>` is not measured; `/_fsc/a/overlay.js` draws the heatmap.
 *   No browser id either: `fsa.pid()` is null there, so a staff view never links to a FullStack Intelligence profile.
 *
 * - Video (no consent needed): `data-fsa-video` on <video> sends video_start, video_progress (25/50/75) and video_complete with a label and
 *   player; also on a YouTube iframe that has enablejsapi=1 (see the Video section).
 *
 * API: fsa.track(name, props), fsa.revenue(amount, currency, props), fsa.pageview(), fsa.setConsent(value), fsa.pid() (the browser id,
 * only with consent and without Global Privacy Control, else null: a site form may send it along so FullStack Intelligence links the
 * submission to the visitor's profile).
 * Declarative: data-fsa-event="cta_click" (+ data-fsa-prop-<key>="value") on any clickable element; `data-fsc` / `data-fsc-prop-<key>`
 * work the same (plan name).
 */
(function (w, d) {
    'use strict';

    if (w.fsa && w.fsa.__loaded) return;

    // Newsletter click links (plan ootb-integrations D9): the Hub's redirect adds `fscn=<token>` to a link to this site. The site server
    // already read it (the PHP SDK links a consenting browser after the response); it leaves the address bar at once, before any pageview,
    // so it is never bookmarked, shared, copied into a form or reported. Always, also without consent or a sitekey.
    // The landing view remembers that the parameter was there (a boolean only, never the token) and reports it as `nl: 1` on that pageview
    // (plan analytics-complete D6), so the collector can count it as the newsletter channel.
    var newsletterLanding = false;
    try {
        var search = String((w.location || {}).search || '');
        if (/[?&]fscn=/.test(search)) {
            newsletterLanding = true;
            var kept = search.replace(/^\?/, '').split('&').filter(function (part) { return part !== '' && part.indexOf('fscn=') !== 0; }).join('&');
            w.history.replaceState(w.history.state, '', w.location.pathname + (kept ? '?' + kept : '') + (w.location.hash || ''));
        }
    } catch (e) { /* no history API: nothing else depends on it */ }

    var me = d.currentScript;
    var data = (me && me.dataset) || {};
    var KEY = data.sitekey || '';
    // Heatmap overlay (plan analytics-engine, phase 4): the portal opened this page with #fsa_overlay=<short-lived, single-use token>.
    // Staff looking at a heatmap is no visitor, so this view is not measured at all, and `fsaOverlayMode` tells fsc.js and the first-party
    // Umami tracker and recorder to stand down too. The token leaves the address bar at once (before any other script can read or report
    // the URL) and goes to overlay.js (same origin) in memory only; it never reaches a server log or a Referer.
    var overlayMatch = /(?:^#|&)fsa_overlay=([A-Za-z0-9._-]{20,1200})/.exec(String((w.location || {}).hash || ''));
    if (overlayMatch || w.fsaOverlayMode) {
        w.fsaOverlayMode = true;
        if (overlayMatch) {
            w.__fsaOverlayToken = overlayMatch[1];
            try {
                var rest = String(w.location.hash).replace(/^#/, '').split('&').filter(function (part) { return part.indexOf('fsa_overlay=') !== 0; }).join('&');
                w.history.replaceState(w.history.state, '', w.location.pathname + w.location.search + (rest ? '#' + rest : ''));
            } catch (e) { /* not fatal: overlay.js tries again */ }
        }
        var overlay = d.createElement('script');
        overlay.src = (data.endpoint || '/_fsc/a/e').replace(/\/e$/, '') + '/overlay.js';
        overlay.async = true;
        (d.head || d.documentElement).appendChild(overlay);
        var noop = function () {};
        // No browser id in overlay mode: nothing is sent, so no profile link or recognition can happen from a staff view.
        w.fsa = { __loaded: true, track: noop, revenue: noop, pageview: noop, setConsent: noop, pid: function () { return null; } };
        return;
    }

    // Own visits (plan analytics-complete D13, feature 80): `?fsa_exclude=1` sets a flag in localStorage (never a cookie), `?fsa_exclude=0`
    // clears it. A flagged browser sends nothing: no pageview, no event, no browser id. Also without a sitekey, so the flag can be set
    // from any page of the site. `fsaExcluded` makes fsc.js and the first-party Umami tracker stand down as well.
    var EXCLUDE_KEY = 'fsa-exclude';
    var excluded = false;
    try {
        var excludeSearch = String((w.location || {}).search || '');
        var excludeMatch = /(?:^\?|&)fsa_exclude=([01])(?=&|$)/.exec(excludeSearch);
        var excludeStore = null;
        try { excludeStore = w.localStorage || null; } catch (e) { excludeStore = null; }
        if (excludeMatch) {
            try {
                if (excludeStore) { if (excludeMatch[1] === '1') excludeStore.setItem(EXCLUDE_KEY, '1'); else excludeStore.removeItem(EXCLUDE_KEY); }
            } catch (e) { /* storage blocked: the flag only holds for this page */ }
            excluded = excludeMatch[1] === '1';
            var excludeKept = excludeSearch.replace(/^\?/, '').split('&').filter(function (part) { return part !== '' && part.indexOf('fsa_exclude=') !== 0; }).join('&');
            try { w.history.replaceState(w.history.state, '', w.location.pathname + (excludeKept ? '?' + excludeKept : '') + (w.location.hash || '')); } catch (e) { /* not fatal */ }
        } else {
            excluded = !!excludeStore && excludeStore.getItem(EXCLUDE_KEY) === '1';
        }
    } catch (e) { excluded = false; }
    if (excluded) {
        w.fsaExcluded = true;
        var none = function () {};
        w.fsa = { __loaded: true, excluded: true, track: none, revenue: none, pageview: none, setConsent: none, hasConsent: function () { return false; }, pid: function () { return null; }, flush: none };
        return;
    }

    if (!KEY) return;

    var ENDPOINT = data.endpoint || '/_fsc/a/e';

    var CONSENT_KEY = data.consentKey || 'fsc-consent';
    var CONSENT_EVENT = data.consentEvent || 'fsc:consent';
    var ID_KEY = 'fsa-id';
    var PARAMS = (data.params || 'utm_source,utm_medium,utm_campaign,utm_term,utm_content,gclid,gbraid,wbraid,dclid,gclsrc,fbclid,msclkid,ttclid,li_fat_id,twclid,rdt_cid,epik,sccid').split(',');
    // The query parameters that hold a site search term (Hub config `analytics.searchParams`, rendered by the SDK as `data-search-params`).
    var SEARCH_PARAMS = String(data.searchParams || 'q,s,search,zoeken').split(',').map(function (p) { return p.trim(); })
        .filter(function (p) { return /^[A-Za-z0-9_.-]{1,32}$/.test(p); }).slice(0, 10);
    var MAX_TERM = 80;
    var nav = w.navigator || {};
    var loc = w.location;
    var stub = w.fsa;

    var MAX_BATCH = 50;
    var FLUSH_MS = 1000;
    var HEARTBEAT_MS = 60000;
    var IDLE_MS = 30000;
    var MAX_HEATMAP = 100;
    var MAX_PER_SELECTOR = 10;
    var MAX_FRUSTRATION = 20;
    var RAGE_MS = 700;
    var RAGE_PX = 30;
    var REACT_MS = 1000;
    var QUICK_BACK_MS = 5000;
    var DATA_ATTRS = ['data-fsa-id', 'data-fsa-event', 'data-fsc', 'data-testid', 'data-test', 'data-cy', 'data-track'];
    // The selector grammar the collector accepts (collector/internal/ingest/pii.go, selectorPartRe): anything else is not sent.
    var SELECTOR_PART = /^[a-z][a-z0-9-]{0,30}(?:#[A-Za-z][\w-]{0,40}|\[data-(?:fsa-id|fsa-event|fsc|testid|test|cy|track)="[\w.:-]{1,40}"\]|(?:\.[A-Za-z][\w-]{0,30}){0,2}(?::nth-of-type\(\d{1,3}\))?)$/;
    var INTERACTIVE = 'a[href],button,input,select,textarea,label,summary,option,[role=button],[role=link],[role=tab],[role=menuitem],[role=checkbox],[role=switch],[role=option],[onclick],[contenteditable],[tabindex],[data-fsa-event],[data-fsc]';
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

    /** Secret-looking path segments become [token]: same rule as the collector (collector/internal/ingest/pii.go, isToken). */
    function isToken(seg) {
        if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(seg) || /^[0-9a-f]{32,}$/i.test(seg)) return true;
        var mixed = function (s) { return /[A-Za-z]/.test(s) && /[0-9]/.test(s); };
        if (!/^[A-Za-z0-9_-]{20,}$/.test(seg) || !mixed(seg)) return false;
        if (seg.split(/[-_]/).some(function (part) { return part.length >= 16 && mixed(part); })) return true;
        var switches = 0;
        var prev = '';
        seg.replace(/[-_]/g, '').split('').forEach(function (c) {
            var kind = /[0-9]/.test(c) ? 'd' : 'l';
            if (prev && kind !== prev) switches++;
            prev = kind;
        });
        return switches >= 6;
    }

    function cleanPath(path) {
        return scrub(String(path).split('/').map(function (seg) { return isToken(seg) ? '[token]' : seg; }).join('/')).slice(0, 500);
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
        return u.origin + cleanPath(u.pathname) + (keep.length ? '?' + keep.join('&') : '');
    }

    function refUrl(href) {
        var u = href ? parse(href) : null;
        return u && /^https?:$/.test(u.protocol) ? u.origin + cleanPath(u.pathname) : '';
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

    // First-party mirror of the browser id (with consent only): a cookie the SITE'S SERVER may read as the `pid` of a server-side
    // personalisation decision (FullStack Intelligence slots), so the variant is already in the HTML. Same life as the localStorage id:
    // removed at once when consent is revoked, the id is removed or GPC is on. Readable by script (the page's own JS can read the id anyway).
    var PID_COOKIE = 'fsa_pid';
    // Optional cookie domain (`data-pid-domain`, e.g. `.fullstackstudio.nl`) so a sibling host of the same site (the customer portal) can
    // read the id at registration or login and link the earlier profile to the account. Only used when the page's own host lies under it
    // (else a browser drops the cookie); default host-only.
    var PID_DOMAIN = pidDomain(data.pidDomain);
    var mirrored = '';

    function pidDomain(value) {
        var domain = String(value || '').toLowerCase();
        var host = String((loc && loc.hostname) || '').toLowerCase();
        if (!/^\.?[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(domain)) return '';
        var bare = domain.replace(/^\./, '');
        return host === bare || host.slice(-(bare.length + 1)) === '.' + bare ? '; Domain=' + domain : '';
    }

    function pidCookie(value, maxAge, domain) {
        return PID_COOKIE + '=' + value + '; Max-Age=' + maxAge + '; Path=/' + domain + '; SameSite=Lax' + (loc && loc.protocol === 'https:' ? '; Secure' : '');
    }

    function mirrorId(id) {
        if (mirrored === id) return;
        try {
            // A host-only copy from before the domain was configured would shadow nothing but linger: drop it first.
            if (PID_DOMAIN) d.cookie = pidCookie('', 0, '');
            d.cookie = pidCookie(id, 34128000, PID_DOMAIN);
            mirrored = id;
        } catch (e) { /* cookies blocked */ }
    }

    function forgetCookie() {
        mirrored = '';
        // Removed with the same domain it was set with, and host-only too (a copy from before the domain was configured).
        try {
            d.cookie = pidCookie('', 0, '');
            if (PID_DOMAIN) d.cookie = pidCookie('', 0, PID_DOMAIN);
        } catch (e) { /* cookies blocked */ }
    }

    function forgetId() {
        try { var s = storage(); if (s) s.removeItem(ID_KEY); } catch (e) { /* storage blocked */ }
        forgetCookie();
    }

    // No consent (never given, declined, revoked in another tab while this one was closed) or GPC: no id may stay on the device.
    if (!consent || gpc) forgetId();

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
            mirrorId(id);
            return id;
        } catch (e) {
            return '';
        }
    }

    // The id exists from the first view with consent, so the very next page load can already be decided on the server.
    if (consent && !gpc) persistentId();

    function setConsent(value) {
        var granted = interpret(value);
        if (granted === null) return;
        consent = granted;
        if (!granted) forgetId(); else if (!gpc) persistentId();
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
        if (queue.length >= MAX_BATCH) {
            flush();
        } else if (event.t === 'pageview') {
            // A pageview goes out at once (next tick, so events of the same tick ride along): a visitor who leaves within the batch
            // window, or a browser that kills the page without pagehide (in-app browsers, prerender), must still count.
            if (timer) w.clearTimeout(timer);
            timer = w.setTimeout(flush, 0);
        } else if (!timer) {
            timer = w.setTimeout(flush, FLUSH_MS);
        }
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
    var firstUrl = '';
    var href = '';
    var engaged = 0;
    var lastTick = now();
    var lastInput = now();
    var scrollMax = 0;
    var scrollSentMax = 0;
    var scrollMaxSent = false;
    var thresholds = {};
    var heatmaps = 0;
    var perSelector = {};
    var frustrations = 0;
    var deadSeen = {};
    var pageStart = now();
    var clicks = [];
    var lastClick = { t: 0, sel: '', done: true };

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

    var lastTerm = '';

    /** The search term of the current URL, cleaned for sending, or ''. Dutch/English names in `SEARCH_PARAMS`; the first non-empty one wins. */
    function searchTerm() {
        var u = parse(loc.href);
        if (!u) return '';
        for (var i = 0; i < SEARCH_PARAMS.length; i++) {
            var raw = u.searchParams.get(SEARCH_PARAMS[i]);
            if (raw == null) continue;
            // A search box can receive anything a visitor types: besides e-mail addresses and phone numbers also IBANs and long numbers
            // (customer, order, ID numbers) are replaced before the term leaves the page.
            var spaced = raw.replace(/\s+/g, ' ').trim().replace(EMAIL_RE, '[email]').replace(/\b[A-Za-z]{2}\d{2}[A-Za-z0-9]{10,30}\b/g, '[iban]');
            var term = str(scrub(spaced).replace(/\d[\d .-]{5,}\d/g, '[nr]'), MAX_TERM).trim().toLowerCase();
            if (term) return term;
        }
        return '';
    }

    /** Feature 29: one `site_search` event per new term (a reload is a new page load; the same term twice in a row on one page is one search). */
    function siteSearch() {
        var term = searchTerm();
        if (!term) { lastTerm = ''; return; }
        if (term === lastTerm) return;
        lastTerm = term;
        push({ t: 'event', n: 'site_search', p: { term: term } });
    }

    function pageview() {
        var next = pageUrl(loc.href);
        if (!next) return;
        // The query is not part of the page URL, so a new search on the same results page is no new pageview, but it is a new search.
        if (next === current) { siteSearch(); return; }
        var referrer = current ? href : d.referrer;
        if (current) { heartbeat(); sendScrollMax(true); }
        current = next;
        if (!firstUrl) firstUrl = next;
        href = loc.href;
        scrollMax = 0;
        scrollSentMax = 0;
        thresholds = {};
        heatmaps = 0;
        perSelector = {};
        frustrations = 0;
        deadSeen = {};
        // A new page view is a new form journey (SPA navigation): forms start, time and report their fields afresh.
        started = {}; startedAt = {}; lastField = {}; fieldCount = {};
        scrollMaxSent = false;
        pageStart = now();
        engaged = 0;
        lastTick = now();
        var view = { t: 'pageview', u: current, r: refUrl(referrer), ti: str(scrub(d.title || ''), 200) };
        if (newsletterLanding) { view.nl = 1; newsletterLanding = false; }
        push(view);
        siteSearch();
    }

    /** Updates scrollMax (percent of the document seen) and returns it. */
    function measure() {
        var root = d.documentElement || {};
        var height = Math.max(root.scrollHeight || 0, (d.body && d.body.scrollHeight) || 0);
        var seen = (w.scrollY || w.pageYOffset || 0) + (w.innerHeight || 0);
        if (height > 0) scrollMax = Math.max(scrollMax, Math.min(100, Math.round(seen / height * 100)));
        return scrollMax;
    }

    function onScroll() {
        lastInput = now();
        var pct = measure();
        [25, 50, 75, 100].forEach(function (mark) {
            if (pct >= mark && !thresholds[mark]) {
                thresholds[mark] = 1;
                push({ t: 'scroll', sd: mark });
            }
        });
    }

    /** Scroll map: the deepest point of this page view, once, at 10% resolution (a short page that fits the screen counts as 100). */
    function sendScrollMax(spa) {
        if (scrollMaxSent || !current) return;
        scrollMaxSent = true;
        if (!spa) measure(); // after a SPA navigation the document is already the next page
        push({ t: 'scroll_max', sd: Math.floor(Math.min(100, scrollMax) / 10) * 10 });
    }

    // --- Clicks: outbound, downloads, declarative events, heatmap (consent only) -------------------------------------------------

    function closest(el, selector) {
        return el && el.closest ? el.closest(selector) : null;
    }

    /** A stable element selector: #id, an allow-listed data attribute or a short tag.class path. Never text, never a value. */
    function selector(el) {
        var parts = [];
        for (var i = 0; el && el.tagName && i < 4; i++) {
            var part = el.tagName.toLowerCase();
            var id = el.id;
            if (id && /^[A-Za-z][\w-]{0,40}$/.test(id) && !/\d{4}/.test(id) && !isToken(id)) { parts.unshift(part + '#' + id); break; }
            var attr = '';
            for (var j = 0; j < DATA_ATTRS.length && !attr; j++) {
                var v = el.getAttribute && el.getAttribute(DATA_ATTRS[j]);
                if (v && /^[\w.:-]{1,40}$/.test(v) && !isToken(v)) attr = '[' + DATA_ATTRS[j] + '="' + v + '"]';
            }
            if (attr) { parts.unshift(part + attr); break; }
            var cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(function (c) { return /^[A-Za-z][\w-]{0,30}$/.test(c) && !/\d{3}|[0-9a-f]{8,}/.test(c) && !isToken(c); }).slice(0, 2) : [];
            var nth = 0;
            if (!cls.length) for (var sib = el.previousElementSibling; sib; sib = sib.previousElementSibling) if (sib.tagName === el.tagName) nth++;
            parts.unshift(part + (cls.length ? '.' + cls.join('.') : nth ? ':nth-of-type(' + (nth + 1) + ')' : ''));
            el = el.parentElement;
        }
        var out = parts.join(' > ');
        return out.length <= 300 && parts.every(function (part) { return SELECTOR_PART.test(part); }) && scrub(out) === out ? out : '';
    }

    function interactive(el) {
        if (closest(el, INTERACTIVE)) return true;
        try {
            for (var n = el, i = 0; n && n.nodeType === 1 && i < 4; n = n.parentElement, i++) if (w.getComputedStyle(n).cursor === 'pointer') return true;
        } catch (e) { /* no computed style */ }
        return false;
    }

    /**
     * FNV-1a of a normalised error message: personal data, urls, quoted strings and numbers out and only the part before the first ':'
     * (where messages carry free text), so the same bug hashes the same. The sitekey is mixed in so hashes of different sites differ.
     * It is not a secret (it is in the page), so the residual risk is a guessable 32-bit hash of a generic message: no personal data.
     */
    function hashMessage(text) {
        var s = scrub(String(text)).split(':')[0].toLowerCase().replace(/(["'`]).*?\1/g, 'q').replace(/\d+/g, '0').slice(0, 300);
        s = KEY + '|' + s;
        var h = 2166136261;
        for (var i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619) >>> 0;
        return ('0000000' + h.toString(16)).slice(-8);
    }

    /** One frustration event (cookieless). `now` sends the batch at once: the page may be gone a moment later. */
    function frustration(kind, sel, extra, now_) {
        if (frustrations >= MAX_FRUSTRATION) return;
        frustrations++;
        var event = { t: 'frustration', n: kind };
        // D5: without consent the signal is its name only (no element, no error class or hash).
        if (consent) {
            if (sel) event.sel = sel;
            if (extra) event.p = extra;
        }
        push(event);
        if (now_) flush();
    }

    /** Dead click: no DOM change and no navigation within 1 s. */
    function watchDead(sel) {
        var MO = w.MutationObserver;
        if (!MO || deadSeen[sel]) return;
        var changed = false;
        var href0 = loc.href;
        var observer;
        try {
            observer = new MO(function () { changed = true; });
            observer.observe(d.documentElement || d, { subtree: true, childList: true, attributes: true, characterData: true });
        } catch (e) { return; }
        w.setTimeout(function () {
            observer.disconnect();
            if (!changed && loc.href === href0 && visible()) { deadSeen[sel] = 1; frustration('dead_click', sel); }
        }, REACT_MS);
    }

    function heatmap(event) {
        var el = event.target;
        if (!el || !el.tagName) return;
        if (el.ownerSVGElement) el = el.ownerSVGElement;
        var t = now();
        var sel = selector(el);
        lastClick = { t: t, sel: sel, done: false };
        if (event.detail === 0) return; // keyboard or script click: no pointer position
        var x = event.clientX;
        var y = event.clientY;
        clicks = clicks.filter(function (c) { return t - c.t < RAGE_MS; });
        clicks.push({ t: t, x: x, y: y });
        var near = clicks.filter(function (c) { return Math.abs(c.x - x) < RAGE_PX && Math.abs(c.y - y) < RAGE_PX; });
        var kind = 'click';
        if (near.length >= 3) {
            if (near.length > 3) return; // one rage marker per burst
            kind = 'rage';
            frustration('rage_click', sel);
        }
        var dead = kind === 'click' && !interactive(event.target) && !(w.getSelection && w.getSelection().toString());
        if (dead) watchDead(sel);
        if (!consent || heatmaps >= MAX_HEATMAP || !sel || !el.getBoundingClientRect || (perSelector[sel] || 0) >= MAX_PER_SELECTOR) return;
        var rect = el.getBoundingClientRect();
        if (!rect || rect.width <= 0 || rect.height <= 0) return;
        perSelector[sel] = (perSelector[sel] || 0) + 1;
        heatmaps++;
        var root = d.documentElement || {};
        var dw = Math.max(root.scrollWidth || 0, w.innerWidth || 0, 1);
        var dh = Math.max(root.scrollHeight || 0, (d.body && d.body.scrollHeight) || 0, 1);
        var unit = function (v) { return Math.round(Math.max(0, Math.min(1, v)) * 1000) / 1000; };
        push({
            t: 'heatmap', sel: sel, hk: kind,
            x: unit((x - rect.left) / rect.width), y: unit((y - rect.top) / rect.height),
            px: unit((x + (w.scrollX || w.pageXOffset || 0)) / dw), py: unit((y + (w.scrollY || w.pageYOffset || 0)) / dh), dh: dh,
        });
    }

    /** A JS error within 1 s after a click: error class and message hash only. */
    function onError(event) {
        if (lastClick.done || now() - lastClick.t > REACT_MS) return;
        lastClick.done = true;
        var err = event && (event.error || event.reason);
        var name = err && typeof err.name === 'string' && /^[A-Za-z]{1,40}$/.test(err.name) ? err.name : 'Error';
        frustration('error_click', lastClick.sel, { err: name, eh: hashMessage((err && err.message) || (event && event.message) || '') }, true);
    }

    /** Back within 5 s of arriving on the page. */
    function quickBack() {
        if (current && now() - pageStart < QUICK_BACK_MS) frustration('quick_back', '', null, true);
    }

    function onClick(event) {
        lastInput = now();
        if (event.type === 'click') heatmap(event);

        var marked = closest(event.target, '[data-fsa-event],[data-fsc]');
        if (marked && event.type === 'click') {
            var props = {};
            var attrs = marked.attributes || [];
            for (var i = 0; i < attrs.length; i++) {
                var m = /^data-fs[ac]-prop-(.+)$/.exec(attrs[i].name);
                if (m) props[m[1].replace(/-/g, '_')] = attrs[i].value;
            }
            track(marked.getAttribute('data-fsa-event') || marked.getAttribute('data-fsc'), props);
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
            push({ t: 'outbound', p: { kind: 'link', host: u.hostname.toLowerCase(), path: str(cleanPath(u.pathname), 200) } });
        } else {
            return;
        }
        // The page may unload right after this click.
        flush();
    }

    // --- Video (feature 37): <video data-fsa-video> and, opt-in, YouTube iframes --------------------------------------------------
    // Events (type `event`, no consent needed, nothing is stored on the device): video_start (first playback), video_progress (percent 25,
    // 50, 75; marks skipped by seeking are not counted) and video_complete (ended), each with the props `label` and `player`
    // (html5|youtube). The label is the data-fsa-video value, else the path of the source without query. Once per element per page load.
    // YouTube: only an <iframe data-fsa-video src="https://www.youtube.com/embed/ID?enablejsapi=1"> is watched, through the player's own
    // postMessage channel. No YouTube script is ever loaded; a page without such an iframe does nothing here.

    var VIDEO_MARKS = [25, 50, 75];
    var YT_HOST_RE = /^(www\.)?youtube(-nocookie)?\.com$/;
    var videoStates = typeof WeakMap === 'function' ? new WeakMap() : null;
    var ytFrames = [];
    var ytListening = false;

    function videoLabel(el, fallback) {
        var label = str(scrub(String(el.getAttribute('data-fsa-video') || '').trim()), 100);
        if (label) return label;
        var u = parse(fallback);
        return u && u.pathname ? str(cleanPath(u.pathname), 100) : 'video';
    }

    function videoEvent(st, name, percent) {
        var props = { label: st.label, player: st.player };
        if (percent) props.percent = percent;
        push({ t: 'event', n: name, p: cleanProps(props) });
    }

    function videoStart(st) {
        if (st.started) return;
        st.started = true;
        videoEvent(st, 'video_start');
    }

    function videoEnd(st) {
        if (st.ended) return;
        st.ended = true;
        videoStart(st);
        videoEvent(st, 'video_complete');
        flush();
    }

    function videoTime(st, time, duration) {
        if (!(duration > 0) || !isFinite(duration) || !isFinite(time)) return;
        // A jump of more than a few seconds is a seek: the marks it passes are closed silently instead of reported.
        var jumped = st.last != null && Math.abs(time - st.last) > 2.5;
        st.last = time;
        var pct = time / duration * 100;
        VIDEO_MARKS.forEach(function (mark) {
            if (st.done[mark] || pct < mark) return;
            st.done[mark] = 1;
            if (jumped) return;
            videoStart(st);
            videoEvent(st, 'video_progress', mark);
        });
    }

    function onMedia(event) {
        var el = event && event.target;
        if (!videoStates || !el || el.tagName !== 'VIDEO' || !el.hasAttribute || !el.hasAttribute('data-fsa-video')) return;
        var st = videoStates.get(el);
        if (!st) {
            st = { label: videoLabel(el, el.getAttribute('src') || el.currentSrc || ''), player: 'html5', done: {} };
            videoStates.set(el, st);
        }
        if (event.type === 'playing') videoStart(st);
        else if (event.type === 'timeupdate') videoTime(st, el.currentTime, el.duration);
        else if (event.type === 'ended') videoEnd(st);
    }

    function ytHello(st) {
        try { st.frame.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), st.origin); } catch (e) { /* ignore */ }
    }

    function onYouTube(event) {
        var st = null;
        for (var i = 0; i < ytFrames.length; i++) {
            if (ytFrames[i].frame.contentWindow && ytFrames[i].frame.contentWindow === event.source) st = ytFrames[i];
        }
        var from = st && parse(event.origin);
        if (!st || !from || !YT_HOST_RE.test(from.hostname)) return;
        var msg = event.data;
        try { if (typeof msg === 'string') msg = JSON.parse(msg); } catch (e) { return; }
        if (!msg || typeof msg !== 'object') return;
        var info = msg.info;
        var state = msg.event === 'onStateChange' ? info : info && typeof info === 'object' ? info.playerState : undefined;
        if (info && typeof info === 'object' && info.duration > 0) st.dur = info.duration;
        if (state === 1) videoStart(st);
        if (info && typeof info === 'object' && typeof info.currentTime === 'number') videoTime(st, info.currentTime, st.dur);
        if (state === 0) videoEnd(st);
    }

    function watchFrames() {
        var list;
        try { list = d.querySelectorAll ? d.querySelectorAll('iframe[data-fsa-video]') : []; } catch (e) { return; }
        for (var i = 0; i < list.length; i++) {
            var frame = list[i];
            var seen = false;
            for (var j = 0; j < ytFrames.length; j++) if (ytFrames[j].frame === frame) seen = true;
            var src = parse(frame.getAttribute('src') || '');
            if (seen || !src || !YT_HOST_RE.test(src.hostname) || src.searchParams.get('enablejsapi') !== '1') continue;
            var st = { frame: frame, origin: src.origin, label: videoLabel(frame, src.href), player: 'youtube', done: {} };
            ytFrames.push(st);
            if (frame.addEventListener) frame.addEventListener('load', function (s) { return function () { ytHello(s); }; }(st));
            ytHello(st);
        }
        if (ytFrames.length && !ytListening) {
            ytListening = true;
            w.addEventListener('message', onYouTube);
        }
    }

    // --- Forms: started (first interaction), submitted, and with consent the field a visitor last entered --------------------------
    // Never a field value. `form_start` / `form_submit` carry the form id; `form_submit` also `seconds` since `form_start` (a number, so
    // the Hub reads it as `num.seconds`). With analytics consent (and no Global Privacy Control) an `event` named `form_field` follows the
    // field a visitor enters (focus): the form id, the field's name or id attribute cut down to [a-z0-9_.-] (max 40) and the seconds since
    // the start. Password and hidden inputs are not reported at all. The Hub shows from it after which field visitors left a form unsent.

    var started = {};
    var startedAt = {};
    var lastField = {};
    var fieldCount = {};
    var MAX_FIELD_EVENTS = 40;

    function formId(form) {
        var id = form.getAttribute('data-fsa-form') || form.getAttribute('data-fsc-form') || form.getAttribute('data-form-key') || form.id || form.getAttribute('name');
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

    /** Whole seconds (one decimal) since the form was started, or null when it was not (capped at a day: an open tab is no duration). */
    function secondsSince(id) {
        if (!startedAt[id]) return null;
        var s = Math.round((now() - startedAt[id]) / 100) / 10;
        return s >= 0 && s <= 86400 ? s : null;
    }

    /** The name of a field for the Hub: its name (else id) attribute, lowercased, anything but [a-z0-9_.-] becomes _, max 40. Never a value. */
    function fieldName(target) {
        var raw = (target.getAttribute && (target.getAttribute('name') || target.getAttribute('id'))) || target.name || target.id || '';
        raw = str(raw, 80);
        // A name that holds an e-mail address or phone number is data, not a name.
        if (scrub(raw) !== raw) return '';
        var name = raw.toLowerCase().replace(/[^a-z0-9_.-]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
        // A generated id (long digit run, uuid, hash) names nothing: it would only be noise or a token.
        return /[0-9]{6,}|[0-9a-f]{16,}/.test(name) ? '' : name;
    }

    function onFocus(event) {
        var target = event.target;
        if (!target || !/^(INPUT|SELECT|TEXTAREA)$/.test(target.tagName || '')) return;
        var form = formOf(target);
        if (!form) return;
        var id = formId(form);
        if (!started[id]) {
            started[id] = 1;
            startedAt[id] = now();
            push({ t: 'form_start', p: { form: id } });
        }
        fieldEntered(target, id);
    }

    /**
     * With consent (and no GPC, checked now: consent can change while the page is open) the field a visitor enters is reported, so the
     * last one before a form is left unsent is known. Entering rather than leaving a field: a visitor who closes the tab inside a field
     * never blurs it. Only a change of field counts (tabbing back and forth is one step), at most 40 per form and page view.
     */
    function fieldEntered(target, id) {
        if (!consent || gpc) return;
        var type = String((target.getAttribute && target.getAttribute('type')) || target.type || '').toLowerCase();
        if (type === 'hidden' || type === 'password') return;
        var field = fieldName(target);
        if (!field || lastField[id] === field || (fieldCount[id] || 0) >= MAX_FIELD_EVENTS) return;
        lastField[id] = field;
        fieldCount[id] = (fieldCount[id] || 0) + 1;
        var props = { form: id, field: field };
        var seconds = secondsSince(id);
        if (seconds !== null) props.seconds = seconds;
        push({ t: 'event', n: 'form_field', p: props });
    }

    function onSubmit(event) {
        var form = formOf(event.target);
        if (!form) return;
        var id = formId(form);
        var props = { form: id };
        var seconds = secondsSince(id);
        if (seconds !== null) props.seconds = seconds;
        // A later submit of the same form on this page has no start of its own: no duration.
        delete startedAt[id];
        push({ t: 'form_submit', p: props });
        flush();
    }

    // --- Web Vitals: once per page load, sent when the page is hidden (the values are final then) ----------------------------------

    var vitals = {};
    var vitalsSent = false;
    var LIMITS = { LCP: [2500, 4000], INP: [200, 500], CLS: [0.1, 0.25], FCP: [1800, 3000], TTFB: [800, 1800] };

    function observe(type, callback, extra) {
        try {
            var PO = w.PerformanceObserver;
            if (!PO || (PO.supportedEntryTypes && PO.supportedEntryTypes.indexOf(type) === -1)) return;
            var options = { type: type, buffered: true };
            if (extra) Object.keys(extra).forEach(function (k) { options[k] = extra[k]; });
            new PO(function (list) { list.getEntries().forEach(callback); }).observe(options);
        } catch (e) { /* not supported */ }
    }

    observe('largest-contentful-paint', function (e) { vitals.LCP = e.renderTime || e.startTime; });
    observe('paint', function (e) { if (e.name === 'first-contentful-paint') vitals.FCP = e.startTime; });
    // CLS: the largest session window (shifts < 1 s apart, window max 5 s), without shifts right after input.
    var cls = { value: 0, first: 0, last: 0 };
    observe('layout-shift', function (e) {
        if (e.hadRecentInput) return;
        if (cls.value && (e.startTime - cls.last > 1000 || e.startTime - cls.first > 5000)) cls.value = 0;
        if (!cls.value) cls.first = e.startTime;
        cls.value += e.value;
        cls.last = e.startTime;
        vitals.CLS = Math.max(vitals.CLS || 0, cls.value);
    });
    // INP: the slowest interaction (for the few interactions of a page view this is the p98 of the spec).
    function interaction(e) { if (e.interactionId || e.entryType === 'first-input') vitals.INP = Math.max(vitals.INP || 0, e.duration); }
    observe('event', interaction, { durationThreshold: 40 });
    observe('first-input', interaction);

    function sendVitals() {
        if (vitalsSent || !firstUrl) return;
        vitalsSent = true;
        try {
            var nav0 = w.performance && w.performance.getEntriesByType ? w.performance.getEntriesByType('navigation')[0] : null;
            if (nav0 && nav0.responseStart > 0) vitals.TTFB = nav0.responseStart;
        } catch (e) { /* no navigation timing */ }
        Object.keys(LIMITS).forEach(function (name) {
            var v = vitals[name];
            if (typeof v !== 'number' || !isFinite(v) || v < 0) return;
            var limit = LIMITS[name];
            push({ t: 'vital', n: name, u: firstUrl, v: name === 'CLS' ? Math.round(v * 10000) / 10000 : Math.round(v * 10) / 10, rt: v <= limit[0] ? 'good' : v <= limit[1] ? 'needs-improvement' : 'poor' });
        });
    }

    // --- Public API --------------------------------------------------------------------------------------------------------------

    function track(name, props) {
        name = str(name, 64);
        if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(name)) return;
        // Reserved: the tracker's own consent-only field event. A site must not be able to send it without the consent checks.
        if (name.toLowerCase() === 'form_field') return;
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
    w.addEventListener('popstate', function () {
        if (!w.navigation && pageUrl(loc.href) !== current) quickBack(); // no Navigation API: a popstate to another page within 5 s counts as back
        w.setTimeout(pageview, 0);
    });
    try {
        if (w.navigation && w.navigation.addEventListener) {
            w.navigation.addEventListener('navigate', function (event) {
                var entry = w.navigation.currentEntry;
                if (event.navigationType === 'traverse' && event.destination && entry && event.destination.index < entry.index) quickBack();
            });
        }
    } catch (e) { /* no Navigation API */ }
    w.addEventListener('error', onError);
    w.addEventListener('unhandledrejection', onError);
    w.addEventListener('scroll', onScroll, { passive: true });
    ['mousemove', 'keydown', 'touchstart', 'pointerdown'].forEach(function (type) {
        w.addEventListener(type, function () { tick(); lastInput = now(); }, { passive: true });
    });
    d.addEventListener('click', onClick, true);
    // Media events do not bubble: the capture phase on the document sees every <video>, also ones added later.
    ['playing', 'timeupdate', 'ended'].forEach(function (type) { d.addEventListener(type, onMedia, true); });
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', watchFrames); else watchFrames();
    w.addEventListener('load', watchFrames);
    d.addEventListener('auxclick', onClick, true);
    d.addEventListener('focusin', onFocus, true);
    d.addEventListener('submit', onSubmit, true);
    d.addEventListener('visibilitychange', function () {
        if (visible()) { lastTick = now(); return; }
        heartbeat();
        sendVitals();
        flush();
    });
    // The scroll map leaves at pagehide (and on SPA navigation), not when the tab is merely hidden: scrolling can continue after that.
    w.addEventListener('pagehide', function () { heartbeat(); sendScrollMax(); sendVitals(); flush(); });
    // Restored from the back/forward cache: a new measurement for the same page.
    w.addEventListener('pageshow', function (event) {
        if (!event || !event.persisted) return;
        scrollMax = 0;
        scrollSentMax = 0;
        scrollMaxSent = false;
        thresholds = {};
        pageStart = now();
        lastTick = now();
    });
    w.setInterval(function () { tick(); }, 5000);
    w.setInterval(function () { if (visible()) heartbeat(); }, HEARTBEAT_MS);

    pageview();

    // 404 (feature 28): the site's 404 view marks itself with `data-fsa-404` on <html> or <body> (the SDK's <x-content::analytics :status="404">
    // sets data-status="404" on the tracker tag; modern browsers also report the navigation status). One `not_found` per page load with the
    // scrubbed path and the host of the referrer (a broken link on another site, or on this one). No query, no full referrer URL.
    function marked404() {
        var found = false;
        [d.documentElement, d.body].forEach(function (node) {
            try { if (node && typeof node.hasAttribute === 'function' && node.hasAttribute('data-fsa-404')) found = true; } catch (e) { /* ignore */ }
        });
        return found;
    }

    var status = parseInt(data.status || '0', 10);
    if (!status) {
        try {
            var entry = w.performance && w.performance.getEntriesByType ? w.performance.getEntriesByType('navigation')[0] : null;
            status = entry && entry.responseStatus ? entry.responseStatus : 0;
        } catch (e) { status = 0; }
    }
    if (status === 404 || marked404()) {
        var notFound = { path: cleanPath(loc.pathname || '/') };
        var from = d.referrer ? parse(d.referrer) : null;
        if (from && /^https?:$/.test(from.protocol) && from.hostname) notFound.referrer_host = bareHost(from.hostname);
        track('not_found', notFound);
    }

    w.fsa = {
        __loaded: true,
        track: track,
        revenue: revenue,
        pageview: pageview,
        setConsent: setConsent,
        hasConsent: function () { return consent; },
        pid: function () { return persistentId() || null; },
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

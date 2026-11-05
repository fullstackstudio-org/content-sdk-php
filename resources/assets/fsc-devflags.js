/*
 * Display flags of the dev panel (fsc-devmode.js), served at /_fsc/d/flags.js. The server writes it as a blocking script into the <head>, so it runs before the
 * page's own (deferred) scripts, and only for a request with the devmode cookie (src/Laravel/Devmode.php). A normal visitor never fetches or runs it, and
 * the HTML is the same for everyone (edge cache safe): everything below changes only what THIS browser reports to the page, read from localStorage `fsc-dev-flags`.
 *
 * Flags (names in the comma separated list `fsc-dev-flags`; a site can add its own through an extension of the panel, those are only recorded):
 *   reduce  <html data-fsc-motion="reduce">, matchMedia('(prefers-reduced-motion: reduce)') matches, and every animation and transition ends at once
 *   save    <html data-fsc-save-data>, navigator.connection.saveData = true
 *   nogl    <html data-fsc-no-webgl>, canvas.getContext('webgl' | 'webgl2' | 'experimental-webgl') returns null
 *   weak    <html data-fsc-weak>, navigator.deviceMemory = 1 and hardwareConcurrency = 2
 * Sites that style or script against these read the attributes, or `window.fscDev.has('reduce')`; `window.fscDev.flags` lists every active flag.
 * CSS the browser media query cannot reach (a `@media (prefers-reduced-motion)` rule of the site) is covered by the attribute: write
 * `@media (prefers-reduced-motion: reduce), html[data-fsc-motion="reduce"] ...` or just `html[data-fsc-motion="reduce"] .x { ... }` next to it.
 */
(function (root) {
    var STORE = 'fsc-dev-flags';
    var NAME = /^[a-z][a-z0-9-]{0,30}$/;

    var parse = function (list) {
        var flags = {};
        String(list || '').split(',').forEach(function (name) { if (NAME.test(name)) { flags[name] = true; } });
        return flags;
    };

    var fakeList = function (query, matches) {
        return {
            matches: matches, media: query, onchange: null,
            addEventListener: function () {}, removeEventListener: function () {},
            addListener: function () {}, removeListener: function () {}, dispatchEvent: function () { return false; },
        };
    };

    /** The value a flag forces for a simple media query, or null when the flag says nothing about it. */
    var forced = function (query, flags) {
        var q = String(query).replace(/\s+/g, '');
        if (flags.reduce) {
            if (q === '(prefers-reduced-motion:reduce)') { return true; }
            if (q === '(prefers-reduced-motion:no-preference)') { return false; }
        }
        return null;
    };

    var apply = function (win, flags) {
        var doc = win.document;
        var html = doc && doc.documentElement;
        if (flags.reduce) {
            var real = win.matchMedia.bind(win);
            win.matchMedia = function (query) {
                var value = forced(query, flags);
                return value === null ? real(query) : fakeList(query, value);
            };
        }
        if (flags.save) {
            try {
                Object.defineProperty(win.navigator, 'connection', { configurable: true, get: function () { return { saveData: true, effectiveType: '3g', downlink: 1.5, rtt: 300, addEventListener: function () {}, removeEventListener: function () {} }; } });
            } catch (error) {}
        }
        if (flags.weak) {
            try {
                Object.defineProperty(win.navigator, 'deviceMemory', { configurable: true, get: function () { return 1; } });
                Object.defineProperty(win.navigator, 'hardwareConcurrency', { configurable: true, get: function () { return 2; } });
            } catch (error) {}
        }
        if (flags.nogl && win.HTMLCanvasElement) {
            var getContext = win.HTMLCanvasElement.prototype.getContext;
            win.HTMLCanvasElement.prototype.getContext = function (type) {
                if (/^(experimental-)?webgl2?$/.test(String(type))) { return null; }
                return getContext.apply(this, arguments);
            };
        }
        if (html && html.setAttribute) {
            if (flags.reduce) { html.setAttribute('data-fsc-motion', 'reduce'); }
            if (flags.save) { html.setAttribute('data-fsc-save-data', ''); }
            if (flags.nogl) { html.setAttribute('data-fsc-no-webgl', ''); }
            if (flags.weak) { html.setAttribute('data-fsc-weak', ''); }
        }
        if (flags.reduce && doc) {
            var style = doc.createElement('style');
            style.setAttribute('data-fsc-dev-flags', '');
            style.textContent = '*,*::before,*::after{animation-duration:.001ms!important;animation-delay:0s!important;animation-iteration-count:1!important;transition-duration:.001ms!important;transition-delay:0s!important;scroll-behavior:auto!important}';
            (doc.head || html).append(style);
        }
        win.fscDev = {
            flags: Object.keys(flags),
            has: function (name) { return flags[name] === true; },
        };
    };

    var api = { STORE: STORE, parse: parse, forced: forced, apply: apply, fakeList: fakeList };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }
    var stored = '';
    try { stored = root.localStorage.getItem(STORE) || ''; } catch (error) {}
    apply(root, parse(stored));
})(typeof window !== 'undefined' ? window : globalThis);

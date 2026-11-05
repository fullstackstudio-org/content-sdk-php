/*
 * Dev panel of the FullStack Content SDK, served at /_fsc/d/devmode.js. The server writes the script tag (deferred, with its config as data-* attributes:
 * v, cookie, me, portal, scripts, aliases) into the <head> ONLY for a request with the cookie `fsc_devmode`=1 (see src/Laravel/Devmode.php); the way in is
 * `?fsc_devmode=1` on any page, the way out `?fsc_devmode=0` or the button in the panel. Nothing about the visitor is in the HTML; the panel reads it here.
 *
 * Sections: "Bezoeker" (analytics browser id, consent, cookie fsa_pid, and the FullStack Intelligence profile of POST {me}), "Weergave" (display flags,
 * applied by fsc-devflags.js on the next load, so only this browser is affected) and "Deze pagina" (path, language, SDK version, slot decisions).
 *
 * Extension point for a site (a script listed in config `content.devmode.scripts`, loaded when the panel opens):
 *   (window.fscDevExtensions = window.fscDevExtensions || []).push({
 *       id: 'mine',
 *       title: 'Mijn sectie',                                  // optional: a section of its own
 *       flags: [{ id: 'phone', label: 'Mobiel', hint: '...' }], // optional: more switches under "Weergave" (stored with the others, read via fscDev.has())
 *       mount: function (ctx) {},                              // optional: once, when the panel is built
 *       render: function (host, ctx) {},                       // optional: fills the section each time the panel opens
 *   });
 * ctx: { has(flag), el(tag, text, className), row(label, value), note(text), say(text), inner, lang }.
 *
 * Position: closed it is a small pill, open it is a card. On desktop top right (`--fsc-dev-top`, default 12px) with a height that stops
 * `--fsc-dev-bottom` (default 12px) above the bottom; on a phone the open panel is a bottom sheet raised by `--fsc-dev-bottom`, the pill sits
 * `--fsc-dev-pill-bottom` from the bottom on the left. A site sets these to keep its floating buttons free.
 */
(function () {
    var w = window;
    if (w.__fscDevPanel) { return; }
    var tag = document.currentScript;
    var json = function (name) { try { return JSON.parse(tag.getAttribute('data-' + name) || '[]'); } catch (error) { return []; } };
    var cfg = tag ? {
        v: tag.getAttribute('data-v') || '', cookie: tag.getAttribute('data-cookie') || 'fsc_devmode', me: tag.getAttribute('data-me') || '',
        portal: tag.getAttribute('data-portal') || '', scripts: json('scripts'), aliases: json('aliases'),
    } : {};
    var STORE = 'fsc-dev-flags';
    var inner = w.top !== w.self;
    var lang = (document.documentElement.getAttribute('lang') || 'nl').slice(0, 2).toLowerCase() === 'en' ? 'en' : 'nl';

    var TEXT = {
        nl: {
            open: 'Dev-paneel openen', openOn: 'Dev-paneel openen, {n} weergave-opties aan', collapse: 'Inklappen', panel: 'Dev-paneel',
            visitor: 'Bezoeker', analytics: 'Analytics', intelligence: 'Intelligence', display: 'Weergave', page: 'Deze pagina',
            browserId: 'Browser-id', consent: 'Toestemming', cookie: 'Cookie fsa_pid', yes: 'ja', no: 'nee', unknown: 'onbekend', none: 'geen',
            copy: 'Kopieer', copied: 'Gekopieerd', copiedSay: '{label} gekopieerd', copyFail: 'Kopiëren is niet gelukt', copyLabel: 'Kopieer {label}',
            loading: 'Laden...', unavailable: 'Intelligence-info niet beschikbaar', noConsent: 'Geen profiel: je hebt geen cookies geaccepteerd.',
            noProfile: 'Nog geen profiel: je bezoeken zijn nog niet verwerkt.', alias: 'Alias', warmth: 'Warmte en fase', topics: 'Hete onderwerpen',
            visits: 'Bezoeken', recognized: 'Herkend als klant', ref: 'Profiel-ref', portal: 'Open in portaal',
            reduce: 'Reduced motion', reduceHint: 'Animaties en overgangen staan uit', save: 'Save-Data', saveHint: 'De pagina denkt dat je data wilt besparen',
            nogl: 'Geen WebGL', noglHint: 'Canvas geeft geen WebGL-context', weak: 'Zwak toestel', weakHint: '1 GB geheugen en 2 kernen',
            reset: 'Alles terugzetten', resetNote: 'Geldt alleen in deze browser en wordt na het wisselen herladen.', reloading: 'Pagina wordt opnieuw geladen...',
            path: 'Pad', language: 'Taal', host: 'Omgeving', sdk: 'SDK-versie', slots: 'Slots', noSlots: 'Geen slots op deze pagina', standard: 'standaard',
            off: 'Dev-modus uit', on: 'Dev-modus aan', offDone: 'Dev-modus uit',
        },
        en: {
            open: 'Open dev panel', openOn: 'Open dev panel, {n} display options on', collapse: 'Collapse', panel: 'Dev panel',
            visitor: 'Visitor', analytics: 'Analytics', intelligence: 'Intelligence', display: 'Display', page: 'This page',
            browserId: 'Browser id', consent: 'Consent', cookie: 'Cookie fsa_pid', yes: 'yes', no: 'no', unknown: 'unknown', none: 'none',
            copy: 'Copy', copied: 'Copied', copiedSay: '{label} copied', copyFail: 'Copying failed', copyLabel: 'Copy {label}',
            loading: 'Loading...', unavailable: 'Intelligence info not available', noConsent: 'No profile: you have not accepted cookies.',
            noProfile: 'No profile yet: your visits have not been processed.', alias: 'Alias', warmth: 'Warmth and stage', topics: 'Hot topics',
            visits: 'Visits', recognized: 'Recognised as customer', ref: 'Profile ref', portal: 'Open in portal',
            reduce: 'Reduced motion', reduceHint: 'Animations and transitions are off', save: 'Save-Data', saveHint: 'The page thinks you want to save data',
            nogl: 'No WebGL', noglHint: 'Canvas returns no WebGL context', weak: 'Weak device', weakHint: '1 GB memory and 2 cores',
            reset: 'Reset all', resetNote: 'Only affects this browser and reloads the page after a change.', reloading: 'Reloading the page...',
            path: 'Path', language: 'Language', host: 'Environment', sdk: 'SDK version', slots: 'Slots', noSlots: 'No slots on this page', standard: 'default',
            off: 'Dev mode off', on: 'Dev mode on', offDone: 'Dev mode off',
        },
    };
    var t = function (key, vars) {
        var text = (TEXT[lang] && TEXT[lang][key]) || TEXT.nl[key] || key;
        Object.keys(vars || {}).forEach(function (name) { text = text.replace('{' + name + '}', vars[name]); });
        return text;
    };

    var BASE_FLAGS = [['reduce', 'reduce', 'reduceHint'], ['save', 'save', 'saveHint'], ['nogl', 'nogl', 'noglHint'], ['weak', 'weak', 'weakHint']];

    var root = null;
    var pill = null;
    var panel = null;
    var collapse = null;
    var visitor = null;
    var intel = null;
    var slots = null;
    var pageHost = null;
    var flagHost = null;
    var extHost = null;
    var fps = null;
    var status = null;
    var isOpen = false;
    var asked = false;
    var frames = 0;
    var since = 0;
    var raf = 0;
    var extensions = [];

    var css = [
        '.fscdev{--d-ink:#0c0f14;--d-soft:#3b424b;--d-muted:#565f69;--d-rule:rgba(12,15,20,.1);--d-sunken:#eceee8;--d-accent:var(--fsc-dev-accent,#c7ff4a);--d-accent-ink:#457000;',
        'position:fixed;top:var(--fsc-dev-top,12px);right:16px;z-index:var(--fsc-dev-z,9500);font:14px/1.4 var(--fsc-dev-font,system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--d-ink);-webkit-font-smoothing:antialiased;text-align:left}',
        '.fscdev[hidden]{display:none}',
        '.fscdev *{box-sizing:border-box}',
        '.fscdev button,.fscdev select{font:inherit;color:inherit}',
        '.fscdev button{cursor:pointer}',
        '.fscdev :focus-visible{outline:2px solid var(--d-ink);outline-offset:2px}',
        '.fscdev-pill{display:inline-flex;align-items:center;gap:8px;min-height:44px;min-width:44px;padding:0 14px;border:0;border-radius:22px;background:var(--d-ink);color:#fff;font-weight:600;box-shadow:0 0 0 1px rgba(12,15,20,.06),0 10px 24px -12px rgba(12,15,20,.5);transition:transform .2s ease}',
        '.fscdev-pill:active{transform:scale(.97)}',
        '.fscdev-count{display:inline-grid;place-items:center;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:var(--d-accent);color:var(--d-ink);font-size:12px;font-weight:700}',
        '.fscdev-count[hidden]{display:none}',
        '.fscdev-panel{width:340px;max-width:calc(100vw - 32px);max-height:calc(100dvh - var(--fsc-dev-top,12px) - var(--fsc-dev-bottom,12px) - 12px);display:flex;flex-direction:column;background:#fff;border-radius:12px;box-shadow:0 0 0 1px rgba(12,15,20,.08),0 24px 48px -20px rgba(12,15,20,.45);overflow:hidden;transform-origin:top right;animation:fscdev-in .22s cubic-bezier(.16,1,.3,1) both}',
        '.fscdev-panel[hidden]{display:none}',
        '@keyframes fscdev-in{from{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}',
        '.fscdev-head{display:flex;align-items:center;gap:8px;padding:6px 6px 6px 16px;border-bottom:1px solid var(--d-rule)}',
        '.fscdev-title{flex:1;min-width:0}',
        '.fscdev-title strong{display:block;font-size:16px;line-height:1.2;font-weight:650;letter-spacing:-.01em}',
        '.fscdev-title span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--d-muted);font-size:12px}',
        '.fscdev-icon{display:grid;place-items:center;width:44px;height:44px;border:0;border-radius:8px;background:transparent}',
        '.fscdev-icon:hover{background:var(--d-sunken)}',
        '.fscdev-body{flex:1;min-height:0;overflow:auto;overscroll-behavior:contain;padding:4px 16px 8px}',
        '.fscdev-sec{padding:12px 0 8px;border-top:1px solid var(--d-rule)}',
        '.fscdev-sec:first-child{border-top:0}',
        '.fscdev-sec h2{margin:0 0 4px;font-size:15px;line-height:1.3;font-weight:650;letter-spacing:-.01em}',
        '.fscdev-sub{margin:10px 0 0;font-size:12px;font-weight:600;color:var(--d-soft)}',
        '.fscdev-row{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:36px;padding:4px 0}',
        '.fscdev-row+.fscdev-row{border-top:1px solid var(--d-rule)}',
        '.fscdev-label{flex:none;color:var(--d-muted)}',
        '.fscdev-value{min-width:0;text-align:right;overflow-wrap:anywhere}',
        '.fscdev-stack{min-width:0;flex:1}',
        '.fscdev-id{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font:12.5px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;background:var(--d-sunken);border-radius:4px;padding:2px 6px}',
        '.fscdev-copy{flex:none;min-height:44px;min-width:44px;padding:0 10px;border:0;border-radius:8px;background:transparent;font-size:13px;font-weight:600;text-decoration:underline;text-underline-offset:3px}',
        '.fscdev-copy:hover{background:var(--d-sunken)}',
        '.fscdev-copy[data-done]{color:var(--d-accent-ink);text-decoration:none}',
        '.fscdev-note{margin:0;padding:6px 0;color:var(--d-muted)}',
        '.fscdev-link{display:inline-flex;align-items:center;min-height:44px;color:#0b57d0;font-weight:600;text-underline-offset:3px}',
        '.fscdev-switch{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-height:52px;padding:4px 0;border:0;background:transparent;text-align:left}',
        '.fscdev-switch+.fscdev-switch{border-top:1px solid var(--d-rule)}',
        '.fscdev-switch b{display:block;font-weight:600}',
        '.fscdev-switch small{display:block;color:var(--d-muted);font-size:12px}',
        '.fscdev-track{flex:none;position:relative;width:38px;height:22px;border-radius:11px;background:#858d96;transition:background-color .2s ease}',
        '.fscdev-track::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .2s cubic-bezier(.16,1,.3,1)}',
        '.fscdev-switch[aria-checked=true] .fscdev-track{background:var(--d-ink)}',
        '.fscdev-switch[aria-checked=true] .fscdev-track::after{transform:translateX(16px);background:var(--d-accent)}',
        '.fscdev-wide{display:block;width:100%;min-height:44px;padding:0 12px;border:1px solid rgba(12,15,20,.24);border-radius:8px;background:#fff;font-weight:600}',
        '.fscdev-wide:hover:not(:disabled){background:var(--d-sunken)}',
        '.fscdev-wide:disabled{opacity:.5;cursor:default}',
        '.fscdev select{width:100%;min-height:44px;padding:0 10px;border:1px solid rgba(12,15,20,.24);border-radius:8px;background:#fff;margin-top:6px}',
        '.fscdev-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:4px 6px 4px 16px;border-top:1px solid var(--d-rule);background:#fafaf8}',
        '.fscdev-foot output{min-width:0;color:var(--d-muted);font-size:12px}',
        '.fscdev-off{flex:none;min-height:44px;padding:0 10px;border:0;border-radius:8px;background:transparent;font-size:13px;font-weight:600;color:#b42318}',
        '.fscdev-off:hover{background:var(--d-sunken)}',
        '@media (max-width:640px){',
        '.fscdev{top:auto;right:auto;left:12px;bottom:calc(var(--fsc-dev-pill-bottom,12px) + env(safe-area-inset-bottom,0px))}',
        '.fscdev[data-open]{left:0;right:0;bottom:var(--fsc-dev-bottom,0px);padding:0 8px}',
        '.fscdev-panel{width:100%;max-width:none;max-height:min(calc(100dvh - var(--fsc-dev-bottom,0px) - 16px),640px);border-radius:16px;transform-origin:bottom center;animation-name:fscdev-up}',
        '@keyframes fscdev-up{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}',
        '}',
        '@media (prefers-reduced-motion:reduce){.fscdev-panel{animation:none}.fscdev-pill,.fscdev-track,.fscdev-track::after{transition:none}}',
    ].join('\n');

    var el = function (tag, text, className) {
        var node = document.createElement(tag);
        if (text !== undefined && text !== null) { node.textContent = text; }
        if (className) { node.className = className; }
        return node;
    };
    var button = function (text, className, onclick) {
        var node = el('button', text, className);
        node.type = 'button';
        node.addEventListener('click', onclick);
        return node;
    };
    var svg = function (path) {
        var ns = 'http://www.w3.org/2000/svg';
        var icon = document.createElementNS(ns, 'svg');
        ['viewBox:0 0 24 24', 'width:20', 'height:20', 'fill:none', 'stroke:currentColor', 'stroke-width:2', 'stroke-linecap:round', 'aria-hidden:true'].forEach(function (pair) {
            var at = pair.indexOf(':');
            icon.setAttribute(pair.slice(0, at), pair.slice(at + 1));
        });
        var shape = document.createElementNS(ns, 'path');
        shape.setAttribute('d', path);
        icon.append(shape);
        return icon;
    };
    var section = function (title) {
        var node = el('section', null, 'fscdev-sec');
        node.append(el('h2', title));
        return node;
    };
    var row = function (label, value) {
        var node = el('div', null, 'fscdev-row');
        node.append(el('span', label, 'fscdev-label'), el('span', value, 'fscdev-value'));
        return node;
    };
    var note = function (text) { return el('p', text, 'fscdev-note'); };
    var yes = function (flag) { return flag ? t('yes') : t('no'); };
    var say = function (text) { if (status) { status.textContent = text; } };

    var cookie = function (name) {
        var found = document.cookie.split('; ').filter(function (pair) { return pair.slice(0, name.length + 1) === name + '='; })[0];
        return found ? decodeURIComponent(found.slice(name.length + 1)) : '';
    };
    var browserId = function () {
        var id = null;
        try { id = w.fsa && typeof w.fsa.pid === 'function' ? w.fsa.pid() : null; } catch (error) {}
        if (!id) { try { id = localStorage.getItem('fsa-id'); } catch (error) {} }
        return id || '';
    };
    var stored = function () { try { return localStorage.getItem(STORE) || ''; } catch (error) { return ''; } };
    var activeFlags = function () { return stored().split(',').filter(Boolean); };
    var has = function (name) { return activeFlags().indexOf(name) !== -1; };

    /** A row with an id in mono and a copy button that turns into "Gekopieerd" for a moment. */
    var idRow = function (label, value) {
        var node = el('div', null, 'fscdev-row');
        var text = el('div', null, 'fscdev-stack');
        text.append(el('span', label, 'fscdev-label'), el('code', value, 'fscdev-id'));
        var copy = button(t('copy'), 'fscdev-copy', function () {
            var done = function () {
                copy.textContent = t('copied');
                copy.setAttribute('data-done', '');
                say(t('copiedSay', { label: label }));
                clearTimeout(copy.timer);
                copy.timer = setTimeout(function () { copy.textContent = t('copy'); copy.removeAttribute('data-done'); }, 1600);
            };
            var fallback = function () {
                var area = el('textarea', value);
                area.style.cssText = 'position:fixed;opacity:0';
                document.body.append(area);
                area.select();
                try { document.execCommand('copy'); done(); } catch (error) { say(t('copyFail')); }
                area.remove();
            };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(value).then(done, fallback); } else { fallback(); }
        });
        copy.setAttribute('aria-label', t('copyLabel', { label: label.toLowerCase() }));
        node.append(text, copy);
        return node;
    };

    var loadIntel = function () {
        if (asked || !cfg.me) { return; }
        asked = true;
        intel.replaceChildren(note(t('loading')));
        var fail = function () { intel.replaceChildren(note(t('unavailable'))); };
        fetch(cfg.me, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('status ' + response.status)); })
            .then(function (data) {
                if (!data || typeof data !== 'object' || data.unavailable) { return fail(); }
                var rows = [];
                var profile = data.profile;
                if (!data.consent) {
                    rows.push(note(cookie('fsa_pid') ? t('unavailable') : t('noConsent')));
                } else if (!profile) {
                    rows.push(note(t('noProfile')));
                } else {
                    rows.push(idRow(t('alias'), profile.alias));
                    rows.push(row(t('warmth'), profile.warmth + ', ' + profile.stage));
                    rows.push(row(t('topics'), (profile.hotTopics || []).map(function (topic) { return topic.label; }).join(', ') || t('none')));
                    rows.push(row(t('visits'), String(profile.visits)));
                }
                rows.push(row(t('recognized'), yes(data.recognized)));
                if (profile && profile.ref) {
                    rows.push(idRow(t('ref'), profile.ref));
                    if (cfg.portal && cfg.portal.indexOf('{ref}') !== -1) {
                        var link = el('a', t('portal'), 'fscdev-link');
                        link.href = cfg.portal.replace('{ref}', encodeURIComponent(profile.ref));
                        link.target = '_blank';
                        link.rel = 'noopener';
                        rows.push(link);
                    }
                }
                intel.replaceChildren.apply(intel, rows);
            })
            .catch(fail);
    };

    var pageSlots = function () {
        var nodes = document.querySelectorAll('[data-fsi-slot]');
        if (!nodes.length) { slots.replaceChildren(note(t('noSlots'))); return; }
        slots.replaceChildren.apply(slots, [].map.call(nodes, function (node) {
            var variant = node.getAttribute('data-fsi-variant');
            var group = node.getAttribute('data-fsi-group');
            return row(node.getAttribute('data-fsi-slot'), variant ? variant + (group ? ' (' + group + ')' : '') : t('standard'));
        }));
    };

    var renderVisitor = function () {
        var id = browserId();
        var consent = w.fsa && typeof w.fsa.hasConsent === 'function' ? yes(w.fsa.hasConsent()) : t('unknown');
        var rows = [el('p', t('analytics'), 'fscdev-sub'), id ? idRow(t('browserId'), id) : row(t('browserId'), t('none')), row(t('consent'), consent), row(t('cookie'), yes(cookie('fsa_pid')))];
        if (cfg.me) { rows.push(el('p', t('intelligence'), 'fscdev-sub'), intel); }
        visitor.replaceChildren.apply(visitor, [visitor.firstChild].concat(rows));
        loadIntel();
    };

    var setFlag = function (name) {
        var list = activeFlags();
        var at = list.indexOf(name);
        if (at === -1) { list.push(name); } else { list.splice(at, 1); }
        try { if (list.length) { localStorage.setItem(STORE, list.join(',')); } else { localStorage.removeItem(STORE); } } catch (error) {}
        say(t('reloading'));
        location.reload();
    };

    var allFlags = function () {
        var list = BASE_FLAGS.map(function (flag) { return [flag[0], t(flag[1]), t(flag[2])]; });
        extensions.forEach(function (ext) { (ext.flags || []).forEach(function (flag) { list.push([flag.id, flag.label, flag.hint || '']); }); });
        return list;
    };

    var renderDisplay = function () {
        var active = activeFlags();
        var nodes = allFlags().map(function (flag) {
            var toggle = button(null, 'fscdev-switch', function () { setFlag(flag[0]); });
            toggle.setAttribute('role', 'switch');
            toggle.setAttribute('aria-checked', active.indexOf(flag[0]) !== -1 ? 'true' : 'false');
            var text = el('span');
            text.append(el('b', flag[1]));
            if (flag[2]) { text.append(el('small', flag[2])); }
            toggle.append(text, el('span', null, 'fscdev-track'));
            return toggle;
        });
        fps = el('p', '', 'fscdev-note');
        fps.setAttribute('role', 'status');
        var reset = button(t('reset'), 'fscdev-wide', function () {
            try { localStorage.removeItem(STORE); } catch (error) {}
            location.reload();
        });
        reset.disabled = !active.length;
        flagHost.replaceChildren.apply(flagHost, nodes.concat([fps, note(t('resetNote')), reset]));
    };

    var ctx = function () {
        return { has: has, el: el, row: row, note: note, say: say, inner: inner, lang: lang };
    };

    var renderPage = function () {
        pageHost.replaceChildren(idRow(t('path'), location.pathname), row(t('language'), document.documentElement.getAttribute('lang') || t('unknown')), row(t('host'), location.host), row(t('sdk'), cfg.v || t('unknown')), el('p', t('slots'), 'fscdev-sub'), slots);
        pageSlots();
    };

    var renderExtensions = function () {
        extHost.replaceChildren();
        extensions.forEach(function (ext) {
            if (typeof ext.render !== 'function') { return; }
            var box = ext.title ? section(ext.title) : el('div');
            try { ext.render(box, ctx()); } catch (error) { box.append(note(String(error && error.message || error))); }
            extHost.append(box);
        });
    };

    var tick = function (now) {
        frames++;
        if (now - since > 800) {
            var live = w.__lx;
            if (fps) { fps.textContent = Math.round(frames * 1000 / (now - since)) + ' fps' + (live ? ', ' + live.profile + (live.quality ? ', ' + live.quality() : '') : ''); }
            frames = 0;
            since = now;
        }
        raf = requestAnimationFrame(tick);
    };

    var setOpen = function (open, focus) {
        isOpen = open;
        panel.hidden = !open;
        pill.hidden = open;
        pill.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { root.setAttribute('data-open', ''); } else { root.removeAttribute('data-open'); }
        try { if (open) { sessionStorage.setItem('devmode-open', '1'); } else { sessionStorage.removeItem('devmode-open'); } } catch (error) {}
        cancelAnimationFrame(raf);
        if (open) {
            renderVisitor();
            renderDisplay();
            renderPage();
            renderExtensions();
            frames = 0;
            since = performance.now();
            raf = requestAnimationFrame(tick);
            if (focus) { collapse.focus(); }
        } else if (focus) {
            pill.focus();
        }
    };

    var refresh = function () {
        if (!root) { return; }
        var n = activeFlags().length;
        pill.setAttribute('aria-label', n ? t('openOn', { n: n }) : t('open'));
        pill.lastChild.textContent = String(n);
        pill.lastChild.hidden = !n;
        if (isOpen) { renderDisplay(); renderExtensions(); }
    };

    var addExtension = function (ext) {
        if (!ext || typeof ext !== 'object') { return; }
        extensions.push(ext);
        if (root && typeof ext.mount === 'function') { try { ext.mount(ctx()); } catch (error) {} }
        refresh();
    };

    var loadScripts = function () {
        // Site extensions: push into window.fscDevExtensions (an array until now, this object once the panel is up).
        var queued = Array.isArray(w.fscDevExtensions) ? w.fscDevExtensions : [];
        w.fscDevExtensions = { push: function (ext) { addExtension(ext); return extensions.length; } };
        queued.forEach(addExtension);
        (cfg.scripts || []).forEach(function (src) {
            var script = document.createElement('script');
            script.src = src;
            if (tag && tag.nonce) { script.nonce = tag.nonce; }
            document.head.append(script);
        });
    };

    var build = function () {
        var style = el('style', css);
        style.setAttribute('data-fsc-dev-style', '');
        document.head.append(style);

        root = el('aside', null, 'fscdev');
        root.setAttribute('data-fsc-dev', '');
        pill = button('Dev', 'fscdev-pill', function () { setOpen(true, true); });
        pill.setAttribute('aria-expanded', 'false');
        var count = el('span', '', 'fscdev-count');
        pill.append(count);

        panel = el('div', null, 'fscdev-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', t('panel'));
        var head = el('header', null, 'fscdev-head');
        var title = el('div', null, 'fscdev-title');
        title.append(el('strong', 'Dev'), el('span', location.host));
        collapse = button(null, 'fscdev-icon', function () { setOpen(false, true); });
        collapse.setAttribute('aria-label', t('collapse'));
        collapse.append(svg('M6 9l6 6 6-6'));
        head.append(title, collapse);

        var body = el('div', null, 'fscdev-body');
        visitor = section(t('visitor'));
        intel = el('div');
        var display = section(t('display'));
        flagHost = el('div');
        display.append(flagHost);
        var page = section(t('page'));
        pageHost = el('div');
        slots = el('div');
        page.append(pageHost);
        extHost = el('div');
        body.append(visitor, display, page, extHost);

        var foot = el('footer', null, 'fscdev-foot');
        status = el('output');
        status.setAttribute('aria-live', 'polite');
        var off = button(t('off'), 'fscdev-off', function () { w.devmode(false); });
        foot.append(status, off);

        panel.append(head, body, foot);
        root.append(pill, panel);
        document.body.append(root);
        panel.hidden = true;

        panel.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.stopPropagation(); setOpen(false, true); } });
        document.addEventListener('fsi:slots', function () { if (isOpen) { pageSlots(); } });
        loadScripts();
        extensions.forEach(function (ext) { if (typeof ext.mount === 'function') { try { ext.mount(ctx()); } catch (error) {} } });
    };

    var mount = function (open) {
        if (!root) { build(); }
        root.hidden = false;
        var want = open;
        try { want = want || sessionStorage.getItem('devmode-open') === '1'; } catch (error) {}
        refresh();
        setOpen(Boolean(want), false);
    };

    /** `devmode()` in the console exists only while the panel is loaded: `devmode(false)` turns the mode off (cookie, flags), `devmode()` opens the panel. */
    var devmode = function (on) {
        if (on === false) {
            document.cookie = cfg.cookie + '=; max-age=0; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            try { localStorage.removeItem(STORE); sessionStorage.removeItem('devmode-open'); } catch (error) {}
            say(t('reloading'));
            location.reload();
            return t('offDone');
        }
        mount(true);
        return t('on');
    };
    w.devmode = devmode;
    (cfg.aliases || []).forEach(function (name) { w[name] = devmode; });
    w.__fscDevPanel = { lang: lang, t: t };

    if (!inner) { mount(false); }
})();

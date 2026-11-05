/*!
 * FullStack Intelligence slots (phase 7, personalisation). Loaded once per page by <x-content::slot> while personalisation is on.
 *
 * - The HTML always holds the default content. This step asks the site's own `POST /_fsc/i/decide` which variant fits this visitor and
 *   swaps it in only when the swap cannot move the page: a slot fully below the viewport may change height; a slot in or above the
 *   viewport may only keep or lose height (the lost height stays reserved), otherwise the default stays. A hidden variant keeps its space
 *   while in view. The check is synchronous (swap, measure, undo before any paint), so a refused swap is never visible.
 * - A decision that arrives after `data-deadline` ms is not applied. Refused or late visits are measured as `fallback` in both the
 *   personalised and the control group (the control group runs the same check on the variant it does not get), so the comparison stays fair.
 * - Consent (D5): the tracker's browser id is sent only when the tracker says there is consent; without it the site only sends the current
 *   and the previous page on this site. Nothing is stored on the device. The visitor never sees why a variant was chosen.
 * - Measurement through the tracker (`fsa.track`): `fsi_slot_view` once per slot when half of it is in view, `fsi_slot_click` for a click
 *   on a link or button in it, with `fsi_slot`, `fsi_variant` and `fsi_group` (personal, control, customer, none or fallback).
 * - API for data slots (no visible content, e.g. the chat opening line): `fsiSlots.get(name)` -> `{variant, group, fields}` or null,
 *   `fsiSlots.ready(callback)`, `fsiSlots.view(name)` (call it in every group when shown, so control and fallback count too) and
 *   `fsiSlots.chatMessage(name)` (`fsi_slot_chat_message`, the first chat message after it was shown); the event `fsi:slots` on document
 *   when the decisions are in.
 * - Field variants change only the text of `data-fsi-field` elements and the `href` of `data-fsi-href` links in place, so listeners and
 *   animations on the slot stay intact; a variant with its own `<template>` replaces the default nodes (re-init scroll effects on `fsi:slots`).
 * - The page language (`<html lang>`) goes along: the Hub only picks variants written for it.
 * - CSP-safe: no inline styles (CSSOM only), no innerHTML, no eval. Text goes in with textContent.
 */
(function (w, d) {
    'use strict';

    if (w.fsiSlots || w.fsaOverlayMode || /(^|[#&])fsa_overlay=/.test(w.location.hash || '')) return;

    var script = d.currentScript;
    var endpoint = (script && script.getAttribute('data-endpoint')) || '/_fsc/i/decide';
    var deadline = parseInt((script && script.getAttribute('data-deadline')) || '1500', 10) || 1500;
    var now = function () { return w.performance && w.performance.now ? w.performance.now() : Date.now(); };
    var started = now();
    var KEY = /^[a-z0-9][a-z0-9-]{0,39}$/;
    var MAX = 10;

    var slots = {};
    var names = [];
    var decided = false;
    var waiting = [];

    var nodes = d.querySelectorAll('[data-fsi-slot]');
    for (var i = 0; i < nodes.length && names.length < MAX; i++) {
        var name = nodes[i].getAttribute('data-fsi-slot') || '';
        if (!KEY.test(name) || slots[name]) continue;
        slots[name] = { name: name, el: nodes[i], data: nodes[i].hasAttribute('data-fsi-data'), result: null, viewed: false, clicked: false };
        names.push(name);
    }

    function track(event, slot) {
        if (!slot.result) return;
        var props = { fsi_slot: slot.name, fsi_variant: slot.result.variant, fsi_group: slot.result.group };
        if (w.fsa && w.fsa.__loaded && typeof w.fsa.track === 'function') {
            w.fsa.track(event, props);
        } else {
            // The tracker's queue: replayed once fsa.js is loaded.
            if (typeof w.fsa !== 'function') w.fsa = function () { (w.fsa.q = w.fsa.q || []).push(arguments); };
            w.fsa('track', event, props);
        }
    }

    var api = {
        get: function (name) {
            var slot = slots[name];
            return slot && slot.result ? { variant: slot.result.variant, group: slot.result.group, fields: slot.result.fields } : null;
        },
        ready: function (callback) {
            if (typeof callback !== 'function') return;
            if (decided) callback(api); else waiting.push(callback);
        },
        view: function (name) {
            var slot = slots[name];
            if (slot && slot.result && !slot.viewed) {
                slot.viewed = true;
                track('fsi_slot_view', slot);
            }
        },
        // The first chat message after a chat-type data slot was shown (the measure of the chat opening line), once per page.
        chatMessage: function (name) {
            var slot = slots[name];
            if (slot && slot.result && slot.viewed && !slot.chatted) {
                slot.chatted = true;
                track('fsi_slot_chat_message', slot);
            }
        },
    };
    w.fsiSlots = api;

    function finish() {
        decided = true;
        for (var j = 0; j < waiting.length; j++) {
            try { waiting[j](api); } catch (e) { /* a consumer never breaks the others */ }
        }
        waiting = [];
        try { d.dispatchEvent(new CustomEvent('fsi:slots', { detail: api })); } catch (e) { /* old browser */ }
    }

    if (!names.length) { finish(); return; }

    // --- Building and swapping ------------------------------------------------------------------------------------------------------

    function isSafeUrl(value) {
        return /^\/(?![\/\\])\S*$/.test(value) || /^https:\/\/[^\s\/]+(\/\S*)?$/.test(value);
    }

    function applyFields(root, fields) {
        if (!fields) return;
        var texts = root.querySelectorAll('[data-fsi-field]');
        for (var a = 0; a < texts.length; a++) {
            var key = texts[a].getAttribute('data-fsi-field');
            if (typeof fields[key] === 'string' && fields[key] !== '') texts[a].textContent = fields[key];
        }
        var links = root.querySelectorAll('[data-fsi-href]');
        for (var b = 0; b < links.length; b++) {
            var href = fields[links[b].getAttribute('data-fsi-href')];
            if (typeof href === 'string' && isSafeUrl(href)) links[b].setAttribute('href', href);
        }
    }

    function defaultNodes(el) {
        var out = [];
        for (var n = el.firstChild; n; n = n.nextSibling) {
            if (!(n.nodeType === 1 && n.tagName === 'TEMPLATE')) out.push(n);
        }
        return out;
    }

    function templateFor(el, variant) {
        for (var n = el.firstElementChild; n; n = n.nextElementSibling) {
            if (n.tagName === 'TEMPLATE' && n.getAttribute('data-fsi-variant') === variant) return n;
        }
        return null;
    }

    // Field variants change the existing nodes in place (text and links only), so listeners and animations on them stay intact. Returns
    // an undo function.
    function applyInPlace(el, fields) {
        var undo = [];
        if (fields) {
            var texts = el.querySelectorAll('[data-fsi-field]');
            for (var a = 0; a < texts.length; a++) {
                var key = texts[a].getAttribute('data-fsi-field');
                if (typeof fields[key] === 'string' && fields[key] !== '') {
                    var node = texts[a];
                    var kids = [];
                    for (var k = node.firstChild; k; k = k.nextSibling) kids.push(k);
                    undo.push((function (target, children) {
                        return function () {
                            while (target.firstChild) target.removeChild(target.firstChild);
                            for (var c = 0; c < children.length; c++) target.appendChild(children[c]);
                        };
                    })(node, kids));
                    node.textContent = fields[key];
                }
            }
            var links = el.querySelectorAll('[data-fsi-href]');
            for (var b = 0; b < links.length; b++) {
                var href = fields[links[b].getAttribute('data-fsi-href')];
                if (typeof href === 'string' && isSafeUrl(href)) {
                    undo.push((function (link, previous) {
                        return function () { if (previous === null) link.removeAttribute('href'); else link.setAttribute('href', previous); };
                    })(links[b], links[b].getAttribute('href')));
                    links[b].setAttribute('href', href);
                }
            }
        }
        return function () { for (var u = undo.length - 1; u >= 0; u--) undo[u](); };
    }

    // A variant with its own markup (template) replaces the default nodes; returns an undo function.
    function applyTemplate(el, tpl, fields) {
        var old = defaultNodes(el);
        var anchor = old.length ? old[old.length - 1].nextSibling : el.firstChild;
        var frag = d.createDocumentFragment();
        frag.appendChild(tpl.content.cloneNode(true));
        applyFields(frag, fields);
        var added = [];
        for (var n = frag.firstChild; n; n = n.nextSibling) added.push(n);
        for (var o = 0; o < old.length; o++) el.removeChild(old[o]);
        el.insertBefore(frag, anchor && anchor.parentNode === el ? anchor : el.firstChild);
        return function () {
            for (var r = 0; r < added.length; r++) if (added[r].parentNode === el) el.removeChild(added[r]);
            var first = el.firstChild;
            for (var p = 0; p < old.length; p++) el.insertBefore(old[p], first);
        };
    }

    // Swap in the variant; with `keep` false undo it again in the same task (the control group's check). True when it may stay.
    function swap(slot, decision, keep) {
        var el = slot.el;
        var before = el.getBoundingClientRect();
        var below = before.top >= (w.innerHeight || d.documentElement.clientHeight);
        var tpl = templateFor(el, decision.v);
        var undo = tpl ? applyTemplate(el, tpl, decision.f) : applyInPlace(el, decision.f);
        var hide = decision.h === 1;

        if (hide) {
            if (below) el.hidden = true; else el.style.visibility = 'hidden';
        }

        var after = el.getBoundingClientRect();
        var ok = below || after.height <= before.height + 0.5;

        if (!ok || !keep) {
            undo();
            el.hidden = false;
            el.style.visibility = '';
            return ok;
        }

        if (!below && after.height < before.height - 0.5) el.style.minHeight = before.height + 'px';
        return true;
    }

    function observe(slot) {
        if (slot.data) return;
        slot.el.addEventListener('click', function (event) {
            var target = event.target && event.target.closest ? event.target.closest('a, button, [data-fsc]') : null;
            if (target && slot.el.contains(target) && !slot.clicked) {
                slot.clicked = true;
                track('fsi_slot_click', slot);
            }
        });
        if (slot.el.hidden || !('IntersectionObserver' in w)) { api.view(slot.name); return; }
        var io = new IntersectionObserver(function (entries) {
            for (var e = 0; e < entries.length; e++) {
                if (entries[e].isIntersecting && entries[e].intersectionRatio >= 0.5) {
                    io.disconnect();
                    api.view(slot.name);
                }
            }
        }, { threshold: [0.5] });
        io.observe(slot.el);
    }

    function apply(decisions) {
        var late = now() - started > deadline;
        for (var k = 0; k < names.length; k++) {
            var slot = slots[names[k]];
            var decision = decisions[slot.name];
            if (!decision || typeof decision.v !== 'string') continue; // off or unknown: leave it alone, measure nothing
            var group = decision.g;
            var fields = decision.f || null;

            if (group === 'personal' || group === 'customer' || group === 'control') {
                if (late) {
                    group = 'fallback';
                } else if (!slot.data) {
                    var ok = false;
                    try { ok = swap(slot, decision, group !== 'control'); } catch (e) { ok = false; }
                    if (!ok) group = 'fallback';
                }
            } else {
                group = 'none';
            }

            slot.result = {
                variant: group === 'none' ? 'default' : decision.v,
                group: group,
                // A data slot hands its fields to the page only when the variant applies.
                fields: group === 'personal' || group === 'customer' ? fields : null,
            };
            observe(slot);
        }
        finish();
    }

    // --- Asking -----------------------------------------------------------------------------------------------------------------------

    function referrerPath() {
        try {
            if (!d.referrer) return '';
            var url = new URL(d.referrer);
            return url.origin === w.location.origin ? url.pathname : '';
        } catch (e) { return ''; }
    }

    function ask() {
        var fsa = w.fsa && w.fsa.__loaded ? w.fsa : null;
        var consent = !!(fsa && typeof fsa.hasConsent === 'function' && fsa.hasConsent());
        var pid = consent && typeof fsa.pid === 'function' ? fsa.pid() : null;
        var body = { s: names, p: w.location.pathname || '/', c: consent ? 1 : 0 };
        var lang = String((d.documentElement && d.documentElement.lang) || '').toLowerCase();
        if (/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/.test(lang)) body.l = lang;
        var ref = referrerPath();
        if (ref) body.r = ref;
        if (pid) body.pid = pid;

        var controller = 'AbortController' in w ? new AbortController() : null;
        var timer = controller ? w.setTimeout(function () { controller.abort(); }, deadline) : 0;

        w.fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal: controller ? controller.signal : undefined,
        }).then(function (response) {
            return response.ok ? response.json() : { d: {} };
        }).then(function (data) {
            if (timer) w.clearTimeout(timer);
            apply(data && typeof data.d === 'object' && data.d ? data.d : {});
        }).catch(function () {
            if (timer) w.clearTimeout(timer);
            finish(); // the default content stays, nothing is measured
        });
    }

    if (typeof w.fetch !== 'function') { finish(); return; }

    // The tracker (fsa.js) is deferred in the head and normally ready; give it a moment when it is not, then ask without consent.
    var tries = 0;
    (function wait() {
        if ((w.fsa && w.fsa.__loaded) || tries >= 6) { ask(); return; }
        tries++;
        w.setTimeout(wait, 50);
    })();
})(window, document);

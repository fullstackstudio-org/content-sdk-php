/*
 * Contact click events of the site standards (module `site`, `clickEvents`), printed inline (with the page nonce) by
 * `<x-content::body-end />` when the analytics module is active; this comment is stripped. One delegated capture-phase listener sends
 * `umami.track('contact-click', {kind, href})` for tel:, mailto: and WhatsApp links (mailto and WhatsApp without their query, which
 * can hold a prefilled message). Does nothing when Umami is
 * not loaded (blocked, or no analytics); never throws and never stops the click.
 */
(function (w, d) {
    if (w.fscClicks) return;
    w.fscClicks = true;
    d.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var h = a.getAttribute('href') || '', l = h.trim().toLowerCase(), k = null;
        if (l.indexOf('tel:') === 0) k = 'tel';
        else if (l.indexOf('mailto:') === 0) k = 'mailto';
        else if (l.indexOf('whatsapp:') === 0 || /^https?:\/\/(wa\.me|(api|web|chat)\.whatsapp\.com)(\/|$)/.test(l)) k = 'whatsapp';
        if (!k) return;
        if (k !== 'tel') h = h.split('?')[0].split('#')[0];
        try { var u = w.umami; if (u && typeof u.track === 'function') u.track('contact-click', { kind: k, href: h }); } catch (x) {}
    }, { capture: true, passive: true });
})(window, document);

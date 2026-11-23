/* Default cookie banner behaviour. Consent contract: the choice goes to localStorage (key from data-key, default `fsc-consent`) as
   {choice, categories?, version}, the window event (data-event, default `fsc:consent`) carries the same detail, and the proof is posted to
   data-endpoint (default /_fsc/c). No cookie is set. Nothing runs before a choice: this script only shows the banner. */
(function () {
    var box = document.getElementById('fsc-consent');
    if (!box) return;
    // The portal preview never shows or records consent.
    if (window.fscPreviewMode || document.documentElement.hasAttribute('data-fsc-preview')) return;

    var data = box.dataset || {};
    var key = data.key || 'fsc-consent';
    var eventName = data.event || 'fsc:consent';
    var endpoint = data.endpoint || '/_fsc/c';
    var version = data.version || '1';

    var choices = box.querySelector('[data-fsc-choices]');
    var customize = box.querySelector('[data-fsc-customize]');
    var save = box.querySelector('[data-fsc-save]');
    var inputs = box.querySelectorAll('[data-fsc-category]');
    var opener = null;

    function read() {
        try {
            var value = JSON.parse(window.localStorage.getItem(key) || 'null');
            return value && typeof value === 'object' ? value : null;
        } catch (e) {
            return null;
        }
    }

    function closePanel() {
        if (choices) choices.hidden = true;
        if (save) save.hidden = true;
        if (customize) customize.hidden = false;
    }

    function openPanel() {
        var stored = read();
        var picked = stored && stored.choice === 'custom' && Array.isArray(stored.categories) ? stored.categories : [];
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].checked = picked.indexOf(inputs[i].value) !== -1;
        }
        if (choices) choices.hidden = false;
        if (save) save.hidden = false;
        if (customize) customize.hidden = true;
        var first = inputs[0];
        if (first && first.focus) first.focus();
    }

    function show(focus) {
        closePanel();
        box.hidden = false;
        if (focus && box.focus) box.focus();
    }

    function record(detail) {
        try {
            window.fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(detail),
                keepalive: true
            }).catch(function () {});
        } catch (e) { /* the choice already works in the browser */ }
    }

    function choose(choice) {
        var categories = [];
        if (choice === 'custom') {
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].checked) categories.push(inputs[i].value);
            }
        }
        var detail = choice === 'custom' ? { choice: choice, categories: categories, version: version } : { choice: choice, version: version };
        try { window.localStorage.setItem(key, JSON.stringify(detail)); } catch (e) { /* private mode: the choice lives for this page only */ }
        window.dispatchEvent(new CustomEvent(eventName, { detail: detail }));
        box.hidden = true;
        record(detail);
        if (opener && opener.focus) opener.focus();
        opener = null;
    }

    var buttons = box.querySelectorAll('[data-fsc-choice]');
    for (var b = 0; b < buttons.length; b++) {
        (function (button) {
            button.addEventListener('click', function () { choose(button.dataset.fscChoice); });
        })(buttons[b]);
    }
    if (customize) customize.addEventListener('click', openPanel);
    if (save) save.addEventListener('click', function () { choose('custom'); });

    box.addEventListener('keydown', function (event) {
        // Escape closes a banner that was reopened (a choice exists); a first-time banner stays until the visitor chooses.
        if (event.key === 'Escape' && read()) {
            box.hidden = true;
            if (opener && opener.focus) opener.focus();
            opener = null;
        }
    });

    var links = document.querySelectorAll('[data-fsc-consent-open]');
    for (var l = 0; l < links.length; l++) {
        (function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                opener = link;
                show(true);
            });
        })(links[l]);
    }

    var stored = read();
    if (!stored || stored.version !== version) show(false);
})();

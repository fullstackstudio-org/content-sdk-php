/*
 * Frame sizer of the page builder's `html` block, printed inline (with the page nonce) once per page by src/Laravel/HtmlBlock.php; this
 * comment is stripped.
 *
 * An isolated block is an <iframe data-fsc-html-frame sandbox="allow-scripts ..."> without allow-same-origin, so its document has the opaque
 * origin "null". The reporter inside it (html-block-frame.js) posts {ns: 'fsc-html', v: 1, type: 'height', height}. A message is used only
 * when it comes from the origin "null" AND its source is the contentWindow of one of those iframes; only that iframe is resized, to at most
 * 20000 px. Nothing else is read from the message.
 *
 * Damping against a feedback loop (content that grows with its frame, such as height: 100vh plus a margin): after the first height, growth
 * by the same delta 5 times in a row stops further growth until the frame shrinks, and the frame grows at most 4000 px per second (the rest
 * follows a second later). Shrinking always applies. Idempotent: a second copy does nothing.
 */
(function (w, d) {
    if (w.fscHtmlBlock) { return; }
    var MAX = 20000;
    var REPEAT = 5;
    var BUDGET = 4000;
    var states = typeof w.WeakMap === 'function' ? new w.WeakMap() : null;
    function frames() { return d.querySelectorAll('iframe[data-fsc-html-frame]'); }
    function now() { return Date.now(); }
    function stateOf(frame) {
        var state = states ? states.get(frame) : frame.__fscHtmlState;
        if (!state) {
            state = { height: 0, delta: 0, repeats: 0, stuck: false, windowStart: 0, grown: 0, timer: null, wanted: 0 };
            if (states) { states.set(frame, state); } else { frame.__fscHtmlState = state; }
        }
        return state;
    }
    function apply(frame, wanted) {
        var state = stateOf(frame);
        var height = Math.min(MAX, Math.ceil(wanted));
        if (height === state.height) { return; }
        if (state.height > 0 && height > state.height) {
            if (state.stuck) { return; }
            var delta = height - state.height;
            if (delta === state.delta) {
                state.repeats += 1;
                if (state.repeats >= REPEAT) { state.stuck = true; return; }
            } else {
                state.repeats = 0;
            }
            state.delta = delta;
            var at = now();
            if (at - state.windowStart >= 1000) { state.windowStart = at; state.grown = 0; }
            var room = BUDGET - state.grown;
            if (delta > room) {
                state.wanted = wanted;
                if (!state.timer) {
                    state.timer = w.setTimeout(function () { state.timer = null; state.delta = 0; apply(frame, state.wanted); }, Math.max(0, 1000 - (at - state.windowStart)));
                }
                if (room <= 0) { return; }
                height = state.height + room;
            }
            state.grown += height - state.height;
        } else if (height < state.height) {
            state.stuck = false;
            state.repeats = 0;
            state.delta = 0;
        }
        state.height = height;
        frame.style.height = height + 'px';
    }
    function resize(event) {
        var message = event.data;
        if (event.origin !== 'null' || !message || typeof message !== 'object' || message.ns !== 'fsc-html' || message.v !== 1 || message.type !== 'height') { return; }
        var height = message.height;
        if (typeof height !== 'number' || !isFinite(height) || height < 0) { return; }
        var list = frames();
        for (var i = 0; i < list.length; i++) {
            if (event.source && list[i].contentWindow === event.source) {
                apply(list[i], height);
                return;
            }
        }
    }
    w.addEventListener('message', resize);
    w.fscHtmlBlock = { frames: frames, resize: resize };
})(window, document);

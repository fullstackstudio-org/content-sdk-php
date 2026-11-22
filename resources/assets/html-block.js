/*
 * Frame sizer of the page builder's `html` block, printed inline (with the page nonce) once per page by src/Laravel/HtmlBlock.php; this
 * comment is stripped.
 *
 * An isolated block is an <iframe data-fsc-html-frame sandbox="allow-scripts ..." srcdoc> without allow-same-origin, so its document has the
 * opaque origin "null". The reporter inside it (html-block-frame.js) posts {ns: 'fsc-html', v: 1, type: 'height', height}. A message is used
 * only when it comes from the origin "null" AND its source is the contentWindow of one of those iframes; only that iframe is resized, to at
 * most 20000 px. Nothing else is read from the message. Idempotent: a second copy does nothing.
 */
(function (w, d) {
    if (w.fscHtmlBlock) { return; }
    var MAX = 20000;
    function frames() { return d.querySelectorAll('iframe[data-fsc-html-frame]'); }
    function resize(event) {
        var message = event.data;
        if (event.origin !== 'null' || !message || typeof message !== 'object' || message.ns !== 'fsc-html' || message.v !== 1 || message.type !== 'height') { return; }
        var height = message.height;
        if (typeof height !== 'number' || !isFinite(height) || height < 0) { return; }
        var list = frames();
        for (var i = 0; i < list.length; i++) {
            if (event.source && list[i].contentWindow === event.source) {
                list[i].style.height = Math.min(MAX, Math.ceil(height)) + 'px';
                return;
            }
        }
    }
    w.addEventListener('message', resize);
    w.fscHtmlBlock = { frames: frames, resize: resize };
})(window, document);

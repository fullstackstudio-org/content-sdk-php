/*
 * Height reporter inside the sandboxed frame of an isolated `html` block (src/Laravel/HtmlBlock.php puts it, with the nonce, at the end of the
 * srcdoc document); this comment is stripped. It posts the content height to the parent page whenever it changes. The message holds a number
 * only; the target is '*' because the opaque frame does not know the page's origin, and the page checks origin and source (html-block.js).
 */
(function (w, d) {
    var parent = w.parent;
    if (!parent || parent === w) { return; }
    var last = -1;
    function measure() {
        var root = d.documentElement;
        var body = d.body;
        // The root box is as tall as the content (html has height:auto); scrollHeight of the root never drops below the frame height.
        return Math.ceil(Math.max(root.getBoundingClientRect().height, body ? body.scrollHeight : 0));
    }
    function send() {
        var height = measure();
        if (height === last) { return; }
        last = height;
        parent.postMessage({ ns: 'fsc-html', v: 1, type: 'height', height: height }, '*');
    }
    if (w.ResizeObserver) {
        var observer = new w.ResizeObserver(send);
        observer.observe(d.documentElement);
        if (d.body) { observer.observe(d.body); }
    }
    w.addEventListener('load', send);
    d.addEventListener('DOMContentLoaded', send);
    send();
})(window, document);

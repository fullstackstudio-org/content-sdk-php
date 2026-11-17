# Umami tracker fixture

`script.js` is the Umami tracker (MIT, https://github.com/umami-software/umami) exactly as served by the FSS analytics host on
2026-11-17 (Umami 3.4). `tests/js/fsc-analytics.test.mjs` runs it in a fake browser to prove that `data-before-send` reaches the
helper's `fscUmamiBeforeSend` and that the url and same-site referrer it sends lose the `stripParams` parameters.

Refresh it when the analytics host moves to a new Umami version, and rerun the JS tests.

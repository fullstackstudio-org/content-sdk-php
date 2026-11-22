{{-- Custom code (the `html` block, "HTML-code"): isolated in a sandboxed iframe served by the site route `/_fsc/h/{pageId}/{block}` (its own CSP)
     by default, inline only when `isolate` is false (staff only), behind the visitor's consent unless `consent` is `necessary`. The markup comes
     from FullStackStudio\Content\Laravel\HtmlBlock, which also tells `Content::siteCsp()` / `siteCspMerge()` what the page needs (`frame-src 'self'`,
     and the hosts of inline code only). Replace it only if you know what you give up: `content.blocks.html`. --}}
{!! \FullStackStudio\Content\Laravel\HtmlBlock::current()->render($block) !!}

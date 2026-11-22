{{-- Custom code (the `html` block, "HTML-code"): isolated in a sandboxed iframe by default, inline only when `isolate` is false (staff only),
     behind the visitor's consent unless `consent` is `necessary`. The markup comes from FullStackStudio\Content\Laravel\HtmlBlock, which also adds the
     code's external origins to `Content::siteCsp()` / `siteCspMerge()`. Replace it only if you know what you give up: `content.blocks.html`. --}}
{!! \FullStackStudio\Content\Laravel\HtmlBlock::current()->render($block) !!}

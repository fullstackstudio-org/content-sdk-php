{{-- Content sanitizes this HTML on save (see article-body). --}}
{!! \FullStackStudio\Content\Laravel\RichStyle::for($block['html'] ?? null) !!}<div class="fsc-block fsc-block--richText">{!! $block['html'] ?? '' !!}</div>

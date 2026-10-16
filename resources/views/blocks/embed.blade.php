<div class="fsc-block fsc-block--embed">
    <iframe class="fsc-embed__frame" src="{{ \FullStackStudio\Content\Html::href($block['embedUrl'] ?? '') }}" title="{{ ($block['title'] ?? '') !== '' ? $block['title'] : 'Video (' . ($block['provider'] ?? '') . ')' }}" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
</div>

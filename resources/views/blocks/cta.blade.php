<section class="fsc-block fsc-block--cta{{ ($look ?? '') === '' ? '' : ' ' . $look }}">
    @if (($block['heading'] ?? '') !== '' || $preview)<h2 class="fsc-cta__heading"{!! $edit('heading') !!}>{{ $block['heading'] ?? '' }}</h2>@endif
    @if (($block['text'] ?? '') !== '' || $preview)<p class="fsc-cta__text"{!! $edit('text') !!}>{{ $block['text'] ?? '' }}</p>@endif
    <a class="fsc-button fsc-button--{{ ($block['variant'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary' }}" href="{{ \FullStackStudio\Content\Html::href($block['href'] ?? '') }}"{!! $edit('label') !!}>{{ $block['label'] ?? '' }}</a>
</section>

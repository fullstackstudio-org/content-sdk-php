<section class="fsc-block fsc-block--cta">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-cta__heading">{{ $block['heading'] }}</h2>@endif
    @if (($block['text'] ?? '') !== '')<p class="fsc-cta__text">{{ $block['text'] }}</p>@endif
    <a class="fsc-button fsc-button--{{ ($block['variant'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary' }}" href="{{ \FullStackStudio\Content\Html::href($block['href'] ?? '') }}">{{ $block['label'] ?? '' }}</a>
</section>

@php($media = ! empty($block['mediaId']) ? $resolve((string) $block['mediaId']) : null)
<section class="fsc-block fsc-block--hero{{ $media ? ' fsc-hero--has-media' : '' }}">
    @if ($media)<x-content::image :media="$media" :base-url="$baseUrl" class="fsc-hero__media" :eager="$index === 0" />@endif
    <div class="fsc-hero__content">
        <h1 class="fsc-hero__heading">{{ $block['heading'] ?? '' }}</h1>
        @if (($block['subheading'] ?? '') !== '')<p class="fsc-hero__subheading">{{ $block['subheading'] }}</p>@endif
        @if (($block['ctaLabel'] ?? '') !== '' && ($block['ctaHref'] ?? '') !== '')<a class="fsc-button fsc-button--primary" href="{{ \FullStackStudio\Content\Html::href($block['ctaHref']) }}">{{ $block['ctaLabel'] }}</a>@endif
    </div>
</section>

{{-- The current version of a legal document (module `legal`) by `kind`. Renders nothing while the module is off, nothing is in force yet or the
     Hub cannot be reached. Replace it with a view of your own: `content.blocks.legalDocument`. --}}
@php($document = \FullStackStudio\Content\Laravel\BlockData::legalDocument($block))
@if ($document !== null)
<section class="fsc-block fsc-block--legalDocument" data-kind="{{ $block['kind'] }}">
    @if ($document['title'] !== '')<h2 class="fsc-legal__title">{{ $document['title'] }}</h2>@endif
    @if ($document['effectiveAt'] !== null)<p class="fsc-legal__meta">Geldt vanaf <time datetime="{{ $document['effectiveAt'] }}">{{ \FullStackStudio\Content\Laravel\BlockData::date($document['effectiveAt']) }}</time></p>@endif
    {{-- HTML from the Hub, sanitised with the legal allowlist on save. --}}
    <x-content::article-body :html="$document['bodyHtml']" class="fsc-legal__body" />
</section>
@endif

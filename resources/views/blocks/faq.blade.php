{{-- Published questions (module `faq`) as a <details> list, optionally of one category, with FAQPage JSON-LD when `structuredData` is on
     (the script carries the page nonce). Renders nothing when empty, on any Hub error or with the module off. Replace it: `content.blocks.faq`. --}}
@php($faq = \FullStackStudio\Content\Laravel\ModuleBlockData::faq($block))
@if ($faq !== null)
<section class="fsc-block fsc-block--faq">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-faq__heading">{{ $block['heading'] }}</h2>@endif
    <div class="fsc-faq">
    @foreach ($faq['items'] as $item)
        <details class="fsc-faq__item">
            <summary class="fsc-faq__question">{{ $item['question'] }}</summary>
            {{-- HTML from the Hub, sanitised with the article allowlist on save. --}}
            <x-content::article-body :html="$item['answerHtml']" class="fsc-faq__answer" />
        </details>
    @endforeach
    </div>
    @if ($faq['jsonLd'] !== null)
    @php($nonce = \FullStackStudio\Content\Laravel\SiteSettings::renderOptions()['nonce'])
    <script type="application/ld+json"@if ($nonce !== null) nonce="{{ $nonce }}"@endif>{!! $faq['jsonLd'] !!}</script>
    @endif
</section>
@endif

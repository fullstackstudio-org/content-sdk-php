{{-- Published questions (module `faq`) as a <details> list, optionally of one category, with FAQPage JSON-LD when `structuredData` is on
     (the script carries the page nonce). Layout `open` prints every answer as a `<dl>` instead of the `<details>` list. Renders nothing when empty, on any Hub error or with the module off. Replace it: `content.blocks.faq`. --}}
@php($faq = \FullStackStudio\Content\Laravel\ModuleBlockData::faq($block))
@if ($faq !== null)
<section class="fsc-block fsc-block--faq{{ ($look ?? '') === '' ? '' : ' ' . $look }}">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-faq__heading">{{ $block['heading'] }}</h2>@endif
    @if (($layout ?? 'accordion') === 'open')<dl class="fsc-faq">
    @foreach ($faq['items'] as $item)
        <div class="fsc-faq__item">
            <dt class="fsc-faq__question">{{ $item['question'] }}</dt>
            <dd class="fsc-faq__answer-wrap"><x-content::article-body :html="$item['answerHtml']" class="fsc-faq__answer" /></dd>
        </div>
    @endforeach
    </dl>@else<div class="fsc-faq">
    @foreach ($faq['items'] as $item)
        <details class="fsc-faq__item">
            <summary class="fsc-faq__question">{{ $item['question'] }}</summary>
            {{-- HTML from the Hub, sanitised with the article allowlist on save. --}}
            <x-content::article-body :html="$item['answerHtml']" class="fsc-faq__answer" />
        </details>
    @endforeach
    </div>@endif
    @if ($faq['jsonLd'] !== null)
    @php($nonce = \FullStackStudio\Content\Laravel\SiteSettings::renderOptions()['nonce'])
    <script type="application/ld+json"@if ($nonce !== null) nonce="{{ $nonce }}"@endif>{!! $faq['jsonLd'] !!}</script>
    @endif
</section>
@endif

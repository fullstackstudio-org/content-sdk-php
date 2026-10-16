{{-- The block holds no articles, only what should be shown. Fetch them yourself (`Content::listArticles(...)`) in a view of your own:
     set `content.blocks.articleList` to its name, or pass `:views="['articleList' => 'blocks.articles']"`. --}}
<section class="fsc-block fsc-block--articleList" @if (! empty($block['category'])) data-category="{{ $block['category'] }}" @endif @if (! empty($block['locale'])) data-locale="{{ $block['locale'] }}" @endif data-limit="{{ (int) ($block['limit'] ?? 0) }}">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-articlelist__heading">{{ $block['heading'] }}</h2>@endif
</section>

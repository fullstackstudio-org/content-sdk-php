{{-- Published articles as cards (title, date, excerpt, cover), linked through the Hub's SEO `articlePathTemplate`. Renders nothing when the list
     is empty or the Hub cannot be reached. Replace it with a view of your own: `content.blocks.articleList`, or
     `:views="['articleList' => 'blocks.articles']"`. --}}
@php($articles = \FullStackStudio\Content\Laravel\BlockData::articleList($block))
@if ($articles !== null)
<section class="fsc-block fsc-block--articleList" @if (! empty($block['category'])) data-category="{{ $block['category'] }}" @endif @if (! empty($block['locale'])) data-locale="{{ $block['locale'] }}" @endif data-limit="{{ (int) ($block['limit'] ?? 0) }}">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-articlelist__heading">{{ $block['heading'] }}</h2>@endif
    <ul class="fsc-articlelist">
    @foreach ($articles as $article)
        <li class="fsc-articlelist__item">
            <article class="fsc-articlecard">
                @if ($article['cover'] !== null)
                <a class="fsc-articlecard__media" href="{{ $article['href'] }}" tabindex="-1" aria-hidden="true"><x-content::image :media="$article['cover']" :base-url="$baseUrl" sizes="(min-width: 48rem) 33vw, 100vw" /></a>
                @endif
                <h3 class="fsc-articlecard__title"><a href="{{ $article['href'] }}">{{ $article['title'] }}</a></h3>
                @if ($article['date'] !== null)<time class="fsc-articlecard__date" datetime="{{ $article['date'] }}">{{ \FullStackStudio\Content\Laravel\BlockData::date($article['date']) }}</time>@endif
                @if ($article['excerpt'] !== '')<p class="fsc-articlecard__excerpt">{{ $article['excerpt'] }}</p>@endif
            </article>
        </li>
    @endforeach
    </ul>
</section>
@endif

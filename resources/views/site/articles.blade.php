{{-- Default view of the article list ($articles, $path, $resolved; paging in $resolved->extra). Publish: php artisan vendor:publish --tag=content-site-articles --}}
@use('FullStackStudio\Content\Laravel\Auto\Auto')
@use('FullStackStudio\Content\Laravel\Site\Layout')
@use('FullStackStudio\Content\Laravel\Site\SiteStyle')
@use('FullStackStudio\Content\Laravel\BlockData')
@use('FullStackStudio\Content\Laravel\Facades\Content')
@extends(Layout::name())

@section(Auto::section())
{!! SiteStyle::for() !!}
@php($urls = Content::urls())
<div class="fsc-site-view">
<div class="{{ Auto::wrap() ?? 'fsc-wrap' }}">
    <header class="fsc-site-head">
        <h1 class="fsc-site-title">Artikelen</h1>
    </header>
    @if ($articles === [])
    <p class="fsc-site-empty">Er zijn nog geen artikelen.</p>
    @else
    <ul class="fsc-articlelist">
        @foreach ($articles as $item)
        @php($href = $urls->article($item))
        <li class="fsc-articlelist__item">
            <article class="fsc-articlecard">
                @if (is_array($item['cover'] ?? null))
                <a class="fsc-articlecard__media" href="{{ $href }}" tabindex="-1" aria-hidden="true"><x-content::image :media="$item['cover']" sizes="(min-width: 48rem) 33vw, 100vw" /></a>
                @endif
                <h2 class="fsc-articlecard__title"><a href="{{ $href }}">{{ $item['title'] ?? '' }}</a></h2>
                @if (! empty($item['publishedAt']))<time class="fsc-articlecard__date" datetime="{{ $item['publishedAt'] }}">{{ BlockData::date($item['publishedAt']) }}</time>@endif
                @if (($item['excerpt'] ?? '') !== '')<p class="fsc-articlecard__excerpt">{{ $item['excerpt'] }}</p>@endif
            </article>
        </li>
        @endforeach
    </ul>
    @endif
    @php($current = (int) ($resolved->extra['page'] ?? 1))
    @php($last = (int) ($resolved->extra['pages'] ?? 1))
    @if ($last > 1)
    @php($base = request()->getPathInfo())
    <nav class="fsc-pager" aria-label="Paginering">
        @if ($current > 1)<a rel="prev" href="{{ $current === 2 ? $base : $base . '?page=' . ($current - 1) }}">Vorige</a>@else<span></span>@endif
        <span class="fsc-pager__status">Pagina {{ $current }} van {{ $last }}</span>
        @if ($current < $last)<a rel="next" href="{{ $base . '?page=' . ($current + 1) }}">Volgende</a>@else<span></span>@endif
    </nav>
    @endif
</div>
</div>
@endsection

{{-- Default view of a Hub article ($article, $path, $resolved). Publish: php artisan vendor:publish --tag=content-site-article --}}
@use('FullStackStudio\Content\Laravel\Auto\Auto')
@use('FullStackStudio\Content\Laravel\Site\Layout')
@use('FullStackStudio\Content\Laravel\Site\SiteStyle')
@use('FullStackStudio\Content\Laravel\BlockData')
@extends(Layout::name())

@section(Auto::section())
{!! SiteStyle::for() !!}
<div class="fsc-site-view">
<div class="{{ Auto::wrap() ?? 'fsc-wrap' }}">
    <article class="fsc-site-prose">
        <header class="fsc-site-head">
            <h1 class="fsc-site-title">{{ $article['title'] ?? '' }}</h1>
            @if (($article['excerpt'] ?? '') !== '')<p class="fsc-site-lead">{{ $article['excerpt'] }}</p>@endif
            @if (($article['authorName'] ?? '') !== '' || ! empty($article['publishedAt']))
            <p class="fsc-site-meta">
                @if (($article['authorName'] ?? '') !== '')<span class="fsc-site-author">{{ $article['authorName'] }}</span>@endif
                @if (! empty($article['publishedAt']))<time datetime="{{ $article['publishedAt'] }}">{{ BlockData::date($article['publishedAt']) }}</time>@endif
            </p>
            @endif
        </header>
        @if (is_array($article['cover'] ?? null))
        <figure class="fsc-site-cover"><x-content::image :media="$article['cover']" :eager="true" sizes="(min-width: 48rem) 46rem, 100vw" /></figure>
        @endif
        <x-content::article-body :html="$article['bodyHtml'] ?? null" />
    </article>
</div>
</div>
@endsection

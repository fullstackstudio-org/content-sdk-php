{{-- Default view of a legal document ($document, $path, $resolved; the kind is in $resolved->extra). Publish: php artisan vendor:publish --tag=content-site-legal --}}
@use('FullStackStudio\Content\Laravel\Auto\Auto')
@use('FullStackStudio\Content\Laravel\Site\Layout')
@use('FullStackStudio\Content\Laravel\Site\SiteStyle')
@use('FullStackStudio\Content\Laravel\BlockData')
@extends(Layout::name())

@section(Auto::section())
{!! SiteStyle::for() !!}
@php
    $title = ($document['title'] ?? '') !== '' ? $document['title'] : match ($resolved->extra['kind'] ?? null) {
        'terms' => 'Algemene voorwaarden',
        'cookie_statement' => 'Cookieverklaring',
        default => 'Privacyverklaring',
    };
@endphp
<div class="fsc-site-view">
<div class="{{ Auto::wrap() ?? 'fsc-wrap' }}">
    <article class="fsc-site-prose">
        <header class="fsc-site-head">
            <h1 class="fsc-site-title">{{ $title }}</h1>
            @if (! empty($document['effectiveAt']))<p class="fsc-site-meta">Geldt vanaf <time datetime="{{ $document['effectiveAt'] }}">{{ BlockData::date($document['effectiveAt']) }}</time></p>@endif
        </header>
        {{-- HTML from the Hub, sanitised with the legal allowlist on save. --}}
        <x-content::article-body :html="$document['bodyHtml'] ?? null" />
    </article>
</div>
</div>
@endsection

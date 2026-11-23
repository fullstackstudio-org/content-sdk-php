{{-- Default view of a Hub event ($event, $path, $resolved). Publish: php artisan vendor:publish --tag=content-site-event --}}
@use('FullStackStudio\Content\Laravel\Auto\Auto')
@use('FullStackStudio\Content\Laravel\Site\Layout')
@use('FullStackStudio\Content\Laravel\Site\SiteStyle')
@use('FullStackStudio\Content\Laravel\ModuleBlockData')
@extends(Layout::name())

@section(Auto::section())
{!! SiteStyle::for() !!}
@php($view = ModuleBlockData::eventData($event))
@php(abort_if($view === null, 404))
@php($register = is_string($event['registrationUrl'] ?? null) && str_starts_with($event['registrationUrl'], 'https://') ? $event['registrationUrl'] : null)
<div class="fsc-site-view">
<div class="{{ Auto::wrap() ?? 'fsc-wrap' }}">
    <article class="fsc-site-prose">
        <header class="fsc-site-head">
            <h1 class="fsc-site-title">{{ $view['title'] }}</h1>
            @if ($view['summary'] !== '')<p class="fsc-site-lead">{{ $view['summary'] }}</p>@endif
        </header>
        @if ($view['cover'] !== null)
        <figure class="fsc-site-cover"><x-content::image :media="$view['cover']" :eager="true" sizes="(min-width: 48rem) 46rem, 100vw" /></figure>
        @endif
        <dl class="fsc-site-facts">
            @if ($view['date'] !== '')<dt>Datum</dt><dd><time datetime="{{ $view['startsAt'] }}">{{ $view['date'] }}</time></dd>@endif
            @if ($view['time'] !== '')<dt>Tijd</dt><dd>{{ $view['time'] }}</dd>@endif
            @if ($view['location'] !== '')<dt>Locatie</dt><dd>{{ $view['location'] }}</dd>@endif
            <dt>Prijs</dt><dd>{{ $view['price'] }}</dd>
        </dl>
        {{-- HTML from the Hub, sanitised on save and again by ModuleBlockData. --}}
        @if ($view['descriptionHtml'] !== '')<x-content::article-body :html="$view['descriptionHtml']" />@endif
        @if ($register !== null && $view['state'] === 'open')
        <p class="fsc-site-action"><a class="fsc-button fsc-button--primary" href="{{ $register }}" rel="noopener">Aanmelden</a></p>
        @elseif (ModuleBlockData::stateLabel($view['state']) !== null)
        <p class="fsc-site-action fsc-event__state">{{ ModuleBlockData::stateLabel($view['state']) }}</p>
        @endif
    </article>
</div>
</div>
@endsection

{{-- One event (module `events`, `eventRef` = its id): cover (when the event has one), title, date, time, location, price, optionally the description, and a button to the
     site's own event page (`content.links.event`) or, without one, to the Hub's registration page. The button shows only while registering is
     open. Renders nothing when the event is gone or not published, on any Hub error or with the module off. Replace it: `content.blocks.event`. --}}
@php($event = \FullStackStudio\Content\Laravel\ModuleBlockData::event($block))
@if ($event !== null)
<section class="fsc-block fsc-block--event">
    @if ($event['cover'] !== null)
    <div class="fsc-event__media"><x-content::image :media="$event['cover']" :base-url="$baseUrl" sizes="(min-width: 48rem) 720px, 100vw" /></div>
    @endif
    <h2 class="fsc-event__title">{{ $event['title'] }}</h2>
    <p class="fsc-event__meta">
        @if ($event['date'] !== '')<time class="fsc-event__date" datetime="{{ $event['startsAt'] }}">{{ $event['date'] }}</time>@endif
        @if ($event['time'] !== '')<span class="fsc-event__time">{{ $event['time'] }}</span>@endif
        @if ($event['location'] !== '')<span class="fsc-event__location">{{ $event['location'] }}</span>@endif
        <span class="fsc-event__price">{{ $event['price'] }}</span>
    </p>
    @if (($block['showDescription'] ?? true) !== false)
        {{-- HTML from the Hub, sanitised with the article allowlist on save. --}}
        @if ($event['descriptionHtml'] !== '')<x-content::article-body :html="$event['descriptionHtml']" class="fsc-event__description" />
        @elseif ($event['summary'] !== '')<p class="fsc-event__summary">{{ $event['summary'] }}</p>@endif
    @endif
    @if ($event['href'] !== null && $event['state'] === 'open')
    <a class="fsc-button fsc-button--primary" href="{{ $event['href'] }}"@if ($event['external']) rel="noopener"@endif>{{ ($block['ctaLabel'] ?? '') !== '' ? $block['ctaLabel'] : 'Aanmelden' }}</a>
    @elseif (\FullStackStudio\Content\Laravel\ModuleBlockData::stateLabel($event['state']) !== null)
    <p class="fsc-event__state">{{ \FullStackStudio\Content\Laravel\ModuleBlockData::stateLabel($event['state']) }}</p>
    @endif
</section>
@endif

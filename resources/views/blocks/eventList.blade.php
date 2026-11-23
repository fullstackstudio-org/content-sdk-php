{{-- Upcoming events (module `events`) as a list: cover (when the event has one), date, title, time, location, price. The title links to the site's own event page
     (`content.links.event`, e.g. `/evenementen/{slug}`) when set, else to the Hub's registration page. Renders nothing on any Hub error or with
     the module off; an empty agenda prints `emptyText` when the block has one. Replace it with a view of your own: `content.blocks.eventList`. --}}
@php($events = \FullStackStudio\Content\Laravel\ModuleBlockData::eventList($block))
@if ($events !== null && ($events !== [] || trim((string) ($block['emptyText'] ?? '')) !== ''))
<section class="fsc-block fsc-block--eventList">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-eventlist__heading">{{ $block['heading'] }}</h2>@endif
    @if ($events === [])
    <p class="fsc-eventlist__empty">{{ $block['emptyText'] }}</p>
    @else
    <ul class="fsc-eventlist">
    @foreach ($events as $event)
        <li class="fsc-eventlist__item">
            <article class="fsc-eventcard">
                @if ($event['cover'] !== null)
                <div class="fsc-eventcard__media"><x-content::image :media="$event['cover']" :base-url="$baseUrl" sizes="(min-width: 48rem) 33vw, 100vw" /></div>
                @endif
                @if ($event['date'] !== '')<time class="fsc-eventcard__date" datetime="{{ $event['startsAt'] }}">{{ $event['date'] }}</time>@endif
                <h3 class="fsc-eventcard__title">@if ($event['href'] !== null)<a href="{{ $event['href'] }}"@if ($event['external']) rel="noopener"@endif>{{ $event['title'] }}</a>@else{{ $event['title'] }}@endif</h3>
                <p class="fsc-eventcard__meta">
                    @if ($event['time'] !== '')<span class="fsc-eventcard__time">{{ $event['time'] }}</span>@endif
                    @if ($event['location'] !== '')<span class="fsc-eventcard__location">{{ $event['location'] }}</span>@endif
                    <span class="fsc-eventcard__price">{{ $event['price'] }}</span>
                </p>
                @if (\FullStackStudio\Content\Laravel\ModuleBlockData::stateLabel($event['state']) !== null)<p class="fsc-eventcard__state">{{ \FullStackStudio\Content\Laravel\ModuleBlockData::stateLabel($event['state']) }}</p>@endif
            </article>
        </li>
    @endforeach
    </ul>
    @endif
</section>
@endif

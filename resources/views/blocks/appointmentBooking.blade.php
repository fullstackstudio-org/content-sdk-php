{{-- A heading, a text and a button to book an appointment (module `appointments`), optionally for one service. The button goes to the site's
     own booking route (`content.links.booking`) or, without one, to the Hub's booking page. Renders nothing without a target, when the chosen
     service is no longer active, on any Hub error or with the module off. Replace it: `content.blocks.appointmentBooking`. --}}
@php($booking = \FullStackStudio\Content\Laravel\ModuleBlockData::appointmentBooking($block))
@if ($booking !== null)
<section class="fsc-block fsc-block--appointmentBooking{{ ($look ?? '') === '' ? '' : ' ' . $look }}">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-booking__heading">{{ $block['heading'] }}</h2>@endif
    @if (($block['text'] ?? '') !== '')<p class="fsc-booking__text">{{ $block['text'] }}</p>@endif
    <a class="fsc-button fsc-button--primary" href="{{ $booking['href'] }}">{{ ($block['ctaLabel'] ?? '') !== '' ? $block['ctaLabel'] : 'Afspraak maken' }}</a>
</section>
@endif

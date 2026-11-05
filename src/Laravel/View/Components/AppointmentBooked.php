<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\View\Components;

use FullStackStudio\Content\Laravel\ConversionMarker;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-content::appointment-booked />` on the page that is shown after a successful booking (render it only when `Appointments::book`
 * succeeded): the event `appointment_booked`, once per page view, and once per browser session per `reference`.
 *
 * - `service`: the service slug or id (optional).
 * - `reference`: the booking id, so a reload of the confirmation page does not count twice (optional; the id is not personal data).
 * - `page`: the path of the booking page, sent as `booking_path`. It defaults to the current path, which is right when the booking
 *   confirmation is shown on the booking page itself; pass it when the confirmation lives on another URL. This is how the Hub learns
 *   where the booking page is (the appointment funnel's first step).
 */
final class AppointmentBooked extends Component
{
    public function __construct(
        public readonly ?string $service = null,
        public readonly ?string $reference = null,
        public readonly ?string $page = null,
    ) {
    }

    public function render(): View
    {
        $path = ConversionMarker::path($this->page ?? '/' . ltrim((string) request()->path(), '/'));

        return view('content::components.marker', ['marker' => ConversionMarker::html(
            ConversionMarker::APPOINTMENT_BOOKED,
            ['service' => $this->service, 'booking_path' => $path !== '/' ? $path : null],
            $this->reference,
        )]);
    }
}

{{--
    503 of the out of the box site (the Hub or the site is unavailable). Standalone on purpose: no layout and no components, because those
    may wait for the same Hub that just failed. Sent with `Retry-After: 300` and `Cache-Control: no-store`.
    Publish it to resources/views/errors/503.blade.php with `php artisan vendor:publish --tag=content-errors-503` to change it; keep it
    standalone.
--}}
@include('content::errors.standalone', [
    'status' => 503,
    'title' => 'Even niet bereikbaar',
    'message' => 'De website is tijdelijk niet bereikbaar. Probeer het over een paar minuten opnieuw.',
    'home' => false,
])

{{-- Written by `php artisan content:install`. The site's layout: replace the header and footer with the design of the site; keep the three
     content:: parts (head, content section, scripts). The default views of the SDK extend this layout (`content.auto.layout`). --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-content::head />
    @vite(['resources/css/app.css'])
</head>
<body>
    <header class="site-header">
        <div class="fsc-wrap">
            <a href="/" class="site-brand">{{ config('app.name') }}</a>
        </div>
    </header>

    <main class="site-main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="fsc-wrap">
            <a href="/privacyverklaring">Privacyverklaring</a>
            <a href="#" data-fsc-consent-open>Cookie-instellingen</a>
        </div>
    </footer>

    <x-content::scripts />
</body>
</html>

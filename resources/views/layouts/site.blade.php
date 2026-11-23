{{-- The layout of the default site views when the site has no layout of its own (`content.auto.layout`, else `layouts.app`). --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-content::head />
</head>
<body>
    <main class="fsc-site">
        @yield('content')
    </main>
    <x-content::scripts />
</body>
</html>

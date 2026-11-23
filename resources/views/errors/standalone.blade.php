{{--
    Standalone error page of the out of the box site: a whole document, no layout, no components and no Hub call, so it renders while
    the Hub is down. Themed by the site's `--fsc-*` variables where they exist (only in this page's own <style>, so mostly the defaults).
    Variables: $status, $title, $message, $home (show a link to the homepage).
--}}
@php($fscNonce = \Illuminate\Support\Facades\Vite::cspNonce())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style @if ($fscNonce) nonce="{{ $fscNonce }}" @endif>
        :root { color-scheme: light; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--fsc-surface, #f7f7f5); color: var(--fsc-text, #1f2328); font-family: var(--fsc-font, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif); line-height: var(--fsc-line-height, 1.6); }
        main { max-width: 32rem; padding: 2rem var(--fsc-content-pad, 1.25rem); text-align: center; }
        h1 { margin: 0 0 .75rem; font-family: var(--fsc-heading-font, inherit); font-size: var(--fsc-h2-size, 1.75rem); line-height: 1.25; }
        p { margin: 0 0 1.5rem; color: var(--fsc-muted, #57606a); }
        a { display: inline-block; padding: .7rem 1.2rem; border-radius: var(--fsc-button-radius, .5rem); background: var(--fsc-button-bg, var(--fsc-accent, #1f2328)); color: var(--fsc-button-text, #fff); text-decoration: none; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if ($home ?? false)
            <a href="{{ url('/') }}">Naar de homepage</a>
        @endif
    </main>
</body>
</html>

{{--
    404 of the out of the box site, inside the site's layout (`content.auto.layout`, else layouts.app) and its content section. The status
    is in the request's PageContext, so <x-content::scripts /> counts it; `fscStatus` is passed too for older layouts. Without that layout,
    or when it fails, the standalone page is shown instead.
    Publish it to resources/views/errors/404.blade.php with `php artisan vendor:publish --tag=content-errors-404` to change it.
--}}
@extends(\FullStackStudio\Content\Laravel\Auto\AutoErrors::layout(), ['fscStatus' => 404])

@section(\FullStackStudio\Content\Laravel\Auto\Auto::section())
    @php($fscNonce = \Illuminate\Support\Facades\Vite::cspNonce())
    <style @if ($fscNonce) nonce="{{ $fscNonce }}" @endif>
        :where(.fsc-error) { padding-block: 4rem; text-align: center; }
        :where(.fsc-error h1) { margin: 0 0 .75rem; font-family: var(--fsc-heading-font, inherit); font-size: var(--fsc-h2-size, 1.75rem); line-height: 1.25; }
        :where(.fsc-error p) { margin: 0 0 1.5rem; color: var(--fsc-muted, inherit); }
        :where(.fsc-error a) { display: inline-block; padding: .7rem 1.2rem; border-radius: var(--fsc-button-radius, .5rem); background: var(--fsc-button-bg, var(--fsc-accent, #1f2328)); color: var(--fsc-button-text, #fff); text-decoration: none; }
    </style>
    <div class="{{ \FullStackStudio\Content\Laravel\Auto\Auto::wrap() ?? 'fsc-wrap' }}">
        <section class="fsc-error">
            <h1>Pagina niet gevonden</h1>
            <p>Deze pagina bestaat niet (meer) of is verplaatst.</p>
            <a href="{{ url('/') }}">Naar de homepage</a>
        </section>
    </div>
@endsection

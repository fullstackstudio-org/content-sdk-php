{{--
    The default cookie banner, on the consent contract of AGENTS.md ("Cookiebanner"). Variable: $nonce.
    Texts, categories and the policy link come from the Hub (module `privacy`). Nothing is printed without banner text: module off, Hub down,
    or the portal preview. The behaviour lives in /_fsc/c/consent.js (cacheable, nonce on the tag), the styles are inline in :where() with --fsc-*.
    A link with `data-fsc-consent-open` (footer "Cookie-instellingen") opens the banner again.
--}}
@php
    use FullStackStudio\Content\Laravel\Preview\Preview;
    use FullStackStudio\Content\Laravel\Site\ConsentController;

    $consent = [];

    if (! Preview::isActive()) {
        try {
            $consent = app(\FullStackStudio\Content\ContentClient::class)->getConsentConfig();
        } catch (\Throwable) {
            $consent = [];
        }
    }

    $text = is_array($consent) ? trim((string) ($consent['bannerText'] ?? '')) : '';
    $labels = ['analytics' => 'Statistieken', 'marketing' => 'Marketing', 'preferences' => 'Voorkeuren', 'functional' => 'Functioneel'];
    $categories = is_array($consent) ? array_values(array_filter((array) ($consent['categories'] ?? []), 'is_string')) : [];
    $nonceAttribute = isset($nonce) && $nonce !== null && $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '';
@endphp
@if ($text !== '')
<style{!! $nonceAttribute !!}>{!! ConsentController::styles() !!}</style>
<div id="fsc-consent" class="fsc-consent" role="dialog" aria-modal="false" aria-labelledby="fsc-consent-title" aria-describedby="fsc-consent-text" tabindex="-1" data-version="{{ $consent['version'] ?? '1' }}" data-key="{{ config('content.analytics.consent_key', 'fsc-consent') }}" data-event="{{ config('content.analytics.consent_event', 'fsc:consent') }}" data-endpoint="{{ ConsentController::PATH }}" hidden>
    <p id="fsc-consent-title" class="fsc-consent__title">{{ $consent['bannerTitle'] ?? 'Cookies' }}</p>
    <p id="fsc-consent-text" class="fsc-consent__text">
        {{ $text }}
        @if (! empty($consent['policyUrl']))
            <a href="{{ $consent['policyUrl'] }}">{{ $consent['policyLabel'] ?? 'Privacyverklaring' }}</a>
        @endif
    </p>
    @if ($categories !== [])
        <button type="button" class="fsc-consent__customize" data-fsc-customize aria-controls="fsc-consent-choices">{{ $consent['customizeLabel'] ?? 'Instellen' }}</button>
        <fieldset id="fsc-consent-choices" class="fsc-consent__choices" data-fsc-choices hidden>
            <legend class="fsc-consent__legend">Kies welke cookies je toestaat</legend>
            <label><input type="checkbox" checked disabled> Noodzakelijk <span class="fsc-consent__always">altijd aan</span></label>
            @foreach ($categories as $category)
                <label><input type="checkbox" value="{{ $category }}" data-fsc-category> {{ $labels[$category] ?? ucfirst($category) }}</label>
            @endforeach
        </fieldset>
    @endif
    <div class="fsc-consent__buttons">
        <button type="button" class="fsc-consent__button" data-fsc-choice="none">{{ $consent['rejectAllLabel'] ?? 'Alleen noodzakelijk' }}</button>
        <button type="button" class="fsc-consent__button" data-fsc-choice="all">{{ $consent['acceptAllLabel'] ?? 'Alles accepteren' }}</button>
        @if ($categories !== [])
            <button type="button" class="fsc-consent__button fsc-consent__save" data-fsc-save hidden>{{ $consent['saveLabel'] ?? 'Keuze opslaan' }}</button>
        @endif
    </div>
</div>
<script src="{{ ConsentController::scriptUrl() }}"{!! $nonceAttribute !!} defer></script>
@endif

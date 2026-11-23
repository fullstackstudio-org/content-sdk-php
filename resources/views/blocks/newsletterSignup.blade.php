{{-- A newsletter signup form (module `newsletter`): heading, text, e-mail field, a hidden honeypot and CSRF. It posts to the SDK's newsletter
     route (`content.newsletter.path`, default `content/newsletter`), which subscribes with double opt-in and redirects back with a message.
     In a portal preview the form is disabled. Renders nothing while the route is not registered. Replace it: `content.blocks.newsletterSignup`. --}}
@php($signup = \FullStackStudio\Content\Laravel\ModuleBlockData::newsletterSignup($block, $index))
@if ($signup !== null)
<section class="fsc-block fsc-block--newsletterSignup{{ ($look ?? '') === '' ? '' : ' ' . $look }}" id="{{ $signup['anchor'] }}">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-newsletter__heading">{{ $block['heading'] }}</h2>@endif
    @if (($block['text'] ?? '') !== '')<p class="fsc-newsletter__text">{{ $block['text'] }}</p>@endif
    @if (($signup['flash']['status'] ?? null) === 'success')
    <p class="fsc-newsletter__success" role="status">{{ $signup['flash']['message'] ?? '' }}</p>
    @else
    <form class="fsc-newsletter" method="POST" action="{{ $signup['action'] }}" novalidate>
        @csrf
        <input type="hidden" name="block" value="{{ $signup['key'] }}">
        @if ($signup['list'] !== '')<input type="hidden" name="list" value="{{ $signup['list'] }}">@endif
    @if ($signup['preview'])<fieldset disabled class="fsc-form__preview" style="border:0;margin:0;padding:0;min-width:0">@endif
        <div class="fsc-newsletter__row">
            <label class="fsc-sr-only" for="{{ $signup['anchor'] }}-email">E-mailadres</label>
            <input id="{{ $signup['anchor'] }}-email" class="fsc-field__control fsc-newsletter__email" type="email" name="email" autocomplete="email" maxlength="254" placeholder="E-mailadres" required @if (($signup['flash']['status'] ?? null) === 'error') aria-invalid="true" aria-describedby="{{ $signup['anchor'] }}-error" @endif>
            <button class="fsc-submit fsc-newsletter__submit" type="submit">{{ ($block['buttonLabel'] ?? '') !== '' ? $block['buttonLabel'] : 'Aanmelden' }}</button>
        </div>
        {{-- Honeypot: people never see or fill this field. --}}
        <div aria-hidden="true" class="fsc-hp" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden"><label for="{{ $signup['anchor'] }}-hp">Laat dit veld leeg <input id="{{ $signup['anchor'] }}-hp" type="text" name="website" tabindex="-1" autocomplete="off" value=""></label></div>
        @if (($signup['flash']['status'] ?? null) === 'error')<p class="fsc-form__error" id="{{ $signup['anchor'] }}-error" role="alert">{{ $signup['flash']['message'] ?? '' }}</p>@endif
    @if ($signup['preview'])</fieldset>@endif
    </form>
    @endif
</section>
@endif

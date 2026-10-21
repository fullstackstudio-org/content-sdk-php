@php
    $filled = $values['street'] !== '' && $values['postcode'] !== '';
    $listId = $id . '-list';
@endphp
<div {{ $attributes->class(['fsc-address', 'fsc-address--nojs']) }} id="{{ $id }}" data-fsc-address data-fsc-config="{{ json_encode($settings(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG) }}" data-fsc-filled="{{ $filled ? '1' : '0' }}">
    <div class="fsc-field fsc-address__search" data-fsc-search>
        <label class="fsc-field__label" for="{{ $id }}-q">{{ $labels['label'] }}@if ($required)<span class="fsc-field__required"><span aria-hidden="true"> *</span><span class="fsc-sr-only"> ({{ $labels['required'] }})</span></span>@endif</label>
        <div class="fsc-address__combo">
            <input id="{{ $id }}-q" class="fsc-field__control" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $listId }}" aria-describedby="{{ $id }}-hint" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="100" placeholder="{{ $labels['search'] }}" data-fsc-q>
            <ul id="{{ $listId }}" class="fsc-address__list" role="listbox" hidden data-fsc-list></ul>
        </div>
        <p class="fsc-address__hint" id="{{ $id }}-hint">{{ $labels['searchHint'] }} <button type="button" class="fsc-address__link" data-fsc-manual>{{ $labels['manual'] }}</button></p>
        <p class="fsc-sr-only" role="status" aria-live="polite" data-fsc-status></p>
    </div>

    <div class="fsc-address__summary" hidden data-fsc-summary>
        <span class="fsc-address__summary-text" data-fsc-summary-text></span>
        <button type="button" class="fsc-address__link" data-fsc-change>{{ $labels['change'] }}</button>
    </div>

    <div class="fsc-address__manual" data-fsc-manual-fields>
        <div class="fsc-address__row fsc-address__row--street">
            <div class="fsc-field">
                <label class="fsc-field__label" for="{{ $id }}-street">{{ $labels['street'] }}</label>
                <input id="{{ $id }}-street" class="fsc-field__control" type="text" name="{{ $names['street'] }}" value="{{ $values['street'] }}" maxlength="200" autocomplete="address-line1" @required($required) data-fsc-key="street">
            </div>
            <div class="fsc-field">
                <label class="fsc-field__label" for="{{ $id }}-houseNumber">{{ $labels['houseNumber'] }}</label>
                <input id="{{ $id }}-houseNumber" class="fsc-field__control" type="text" inputmode="numeric" name="{{ $names['houseNumber'] }}" value="{{ $values['houseNumber'] }}" maxlength="10" @required($required) data-fsc-key="houseNumber">
            </div>
            <div class="fsc-field">
                <label class="fsc-field__label" for="{{ $id }}-addition">{{ $labels['addition'] }}</label>
                <input id="{{ $id }}-addition" class="fsc-field__control" type="text" name="{{ $names['addition'] }}" value="{{ $values['addition'] }}" maxlength="20" data-fsc-key="addition">
            </div>
        </div>
        <div class="fsc-address__row fsc-address__row--place">
            <div class="fsc-field">
                <label class="fsc-field__label" for="{{ $id }}-postcode">{{ $labels['postcode'] }}</label>
                <input id="{{ $id }}-postcode" class="fsc-field__control" type="text" name="{{ $names['postcode'] }}" value="{{ $values['postcode'] }}" maxlength="10" autocomplete="postal-code" @required($required) data-fsc-key="postcode">
            </div>
            <div class="fsc-field">
                <label class="fsc-field__label" for="{{ $id }}-city">{{ $labels['city'] }}</label>
                <input id="{{ $id }}-city" class="fsc-field__control" type="text" name="{{ $names['city'] }}" value="{{ $values['city'] }}" maxlength="200" autocomplete="address-level2" @required($required) data-fsc-key="city">
            </div>
            <div class="fsc-field">
                <label class="fsc-field__label" for="{{ $id }}-country">{{ $labels['country'] }}</label>
                <select id="{{ $id }}-country" class="fsc-field__control" name="{{ $names['country'] }}" autocomplete="country" data-fsc-key="country">
                    @foreach ($countryOptions as $code => $countryLabel)
                    <option value="{{ $code }}" @selected($values['country'] === (string) $code)>{{ $countryLabel }}</option>
                    @endforeach
                </select>
            </div>
        </div>
@if ($geo)
        <input type="hidden" name="{{ $names['lat'] }}" value="{{ $values['lat'] }}" data-fsc-key="lat">
        <input type="hidden" name="{{ $names['lon'] }}" value="{{ $values['lon'] }}" data-fsc-key="lon">
@endif
    </div>
</div>
@once
@if ($styles)
<link rel="stylesheet" href="{{ $stylesUrl }}">
@endif
<script defer src="{{ $scriptUrl }}"@if ($scriptNonce) nonce="{{ $scriptNonce }}"@endif></script>
@endonce

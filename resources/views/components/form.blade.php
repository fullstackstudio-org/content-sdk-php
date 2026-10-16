<div id="{{ $anchor }}">
@if ($definition === null)
    <p class="fsc-form__error" role="alert">{{ $loadError }}</p>
@elseif (($flash['status'] ?? null) === 'success')
    <div class="fsc-form fsc-form--success" role="status"><p class="fsc-form__success">{{ $flash['message'] ?? '' }}</p></div>
@else
@php
    $turnstileKey = $definition['turnstileSiteKey'] ?? null;
@endphp
<form {{ $attributes->class(['fsc-form']) }} method="POST" action="{{ $action }}" novalidate>
    @csrf
@foreach ($definition['fields'] ?? [] as $field)
    @php
        $name = $field['name'];
        $id = $anchor . '-' . $name;
        $type = $field['type'] ?? 'text';
        $error = $fieldErrors[$name] ?? null;
        $errorId = $id . '-error';
        $value = old($name);
        $aria = ($error ? 'aria-invalid="true" aria-describedby="' . e($errorId) . '"' : '');
        $payment = $field['payment'] ?? [];
    @endphp
    @continue($type === 'payment' && ($payment['mode'] ?? '') !== 'choice')
    <div class="fsc-field fsc-field--{{ $type }}{{ $error ? ' fsc-field--invalid' : '' }}">
    @if ($type === 'checkbox')
        <label class="fsc-field__checkbox" for="{{ $id }}">
            <input id="{{ $id }}" name="{{ $name }}" type="checkbox" value="1" @checked($value !== null && $value !== '' && $value !== '0') @required($field['required'] ?? false) {!! $aria !!}>
            <span>{{ $field['label'] ?? $name }}@if ($field['required'] ?? false)<span class="fsc-field__required"><span aria-hidden="true"> *</span><span class="fsc-sr-only"> ({{ $labels['required'] }})</span></span>@endif</span>
        </label>
    @else
        <label class="fsc-field__label" for="{{ $id }}">{{ $field['label'] ?? $name }}@if ($field['required'] ?? false)<span class="fsc-field__required"><span aria-hidden="true"> *</span><span class="fsc-sr-only"> ({{ $labels['required'] }})</span></span>@endif</label>
        @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" class="fsc-field__control" rows="5" maxlength="{{ (int) ($field['maxLength'] ?? 5000) }}" @required($field['required'] ?? false) {!! $aria !!}>{{ $value }}</textarea>
        @elseif ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" class="fsc-field__control" @required($field['required'] ?? false) {!! $aria !!}>
            <option value="">{{ $labels['selectPlaceholder'] }}</option>
            @foreach ($field['options'] ?? [] as $option)
            <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $option }}</option>
            @endforeach
        </select>
        @elseif ($type === 'payment' || $type === 'number')
        <input id="{{ $id }}" name="{{ $name }}" class="fsc-field__control" type="number" step="{{ $type === 'payment' ? '0.01' : 'any' }}" inputmode="decimal" @if ($type === 'payment') min="{{ isset($payment['minCents']) ? $payment['minCents'] / 100 : 0 }}" @if (isset($payment['maxCents'])) max="{{ $payment['maxCents'] / 100 }}" @endif @endif value="{{ $value }}" @required($field['required'] ?? false) {!! $aria !!}>
        @else
        <input id="{{ $id }}" name="{{ $name }}" class="fsc-field__control" type="{{ in_array($type, ['text', 'email', 'tel', 'url'], true) ? $type : 'text' }}" maxlength="{{ (int) ($field['maxLength'] ?? 500) }}" value="{{ $value }}" @required($field['required'] ?? false) @if ($type === 'email') autocomplete="email" @elseif ($type === 'tel') autocomplete="tel" @elseif ($type === 'url') autocomplete="url" @elseif ($type === 'text' && preg_match('/^(naam|name)$/i', $name)) autocomplete="name" @endif {!! $aria !!}>
        @endif
    @endif
    @if ($error)
        <p class="fsc-field__error" id="{{ $errorId }}" role="alert">{{ $error }}</p>
    @endif
    </div>
@endforeach

    {{-- Honeypot: a human neither sees nor fills this field, a bot does. Off-screen (not display:none, some bots skip that), out of the tab order, hidden from screen readers. --}}
    <div aria-hidden="true" class="fsc-hp" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">
        <label for="{{ $anchor }}-hp">{{ $labels['honeypot'] }}
            <input id="{{ $anchor }}-hp" name="{{ $definition['honeypotField'] ?? 'website' }}" type="text" tabindex="-1" autocomplete="off" value="">
        </label>
    </div>

@if ($turnstileKey)
    <div class="fsc-turnstile"><div class="cf-turnstile" data-sitekey="{{ $turnstileKey }}"></div></div>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif

@if (($flash['status'] ?? null) === 'error')
    <p class="fsc-form__error" role="alert">{{ $flash['message'] ?? '' }}</p>
@endif

    <button class="fsc-submit" type="submit">{{ $labels['submit'] }}</button>
</form>
@endif
</div>

<div {{ $attributes->class(['fsc-blocks']) }}@if ($preview) data-fsc-preview-blocks @endif>
@foreach ($items as $item)
@if ($preview)<div data-fsc-block="{{ $item['marker'] }}" data-fsc-block-index="{{ $item['index'] }}" style="display:contents">@endif
    @include($item['view'], ['block' => $item['block'], 'index' => $item['index'], 'baseUrl' => $baseUrl, 'resolve' => $mediaFor])
@if ($preview)</div>@endif
@endforeach
</div>

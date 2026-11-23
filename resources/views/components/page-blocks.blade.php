@php($out = $rendered())
<div {{ $attributes->class(['fsc-blocks']) }}@if ($preview) data-fsc-preview-blocks @endif>
@if ($out['styles'] !== '')
{!! $out['styles'] !!}
@endif
@foreach ($out['blocks'] as $item)
@if ($preview)<div data-fsc-block="{{ $item['marker'] }}" data-fsc-block-index="{{ $item['index'] }}" data-fsc-block-type="{{ $item['type'] }}" data-fsc-block-default="{{ $item['default'] ? '1' : '0' }}" data-fsc-layouts="{{ $item['layouts'] }}" style="display:contents">@endif
{!! $item['html'] !!}
@if ($preview)</div>@endif
@endforeach
</div>

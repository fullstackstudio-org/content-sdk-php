@php($out = $rendered())
<div {{ $attributes->class(['fsc-blocks']) }}@if ($preview) data-fsc-preview-blocks @endif>
@if ($out['styles'] !== '')
{!! $out['styles'] !!}
@endif
@foreach ($out['blocks'] as $item)
@if ($preview)<div data-fsc-block="{{ $item['marker'] }}" data-fsc-block-index="{{ $item['index'] }}" style="display:contents">@endif
{!! $item['html'] !!}
@if ($preview)</div>@endif
@endforeach
</div>

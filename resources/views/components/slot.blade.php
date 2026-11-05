@if ($dataOnly)
@if ($enabled)<template data-fsi-slot="{{ $name }}" data-fsi-data{!! $marks !!}></template>{!! $scriptTag !!}@endif
@elseif ($enabled)
<{{ $tag }} {{ $attributes->merge(['data-fsi-slot' => $name]) }}{!! $marks !!}>{!! $content((string) $slot) !!}</{{ $tag }}>{!! $scriptTag !!}
@else
<{{ $tag }} {{ $attributes }}>{{ $slot }}</{{ $tag }}>
@endif

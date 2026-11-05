@if ($dataOnly)
@if ($enabled)<template data-fsi-slot="{{ $name }}" data-fsi-data></template>{!! $scriptTag !!}@endif
@elseif ($enabled)
<{{ $tag }} {{ $attributes->merge(['data-fsi-slot' => $name]) }}>{{ $slot }}</{{ $tag }}>{!! $scriptTag !!}
@else
<{{ $tag }} {{ $attributes }}>{{ $slot }}</{{ $tag }}>
@endif

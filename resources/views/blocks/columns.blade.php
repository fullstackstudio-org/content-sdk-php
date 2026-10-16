<div class="fsc-block fsc-block--columns" style="--fsc-columns: {{ max(1, count($block['columns'] ?? [])) }}">
@foreach ($block['columns'] ?? [] as $column)
    <div class="fsc-column">{!! $column['html'] ?? '' !!}</div>
@endforeach
</div>

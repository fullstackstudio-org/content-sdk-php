{!! \FullStackStudio\Content\Laravel\RichStyle::for(implode(' ', array_map(static fn ($c) => (string) ($c['html'] ?? ''), (array) ($block['columns'] ?? [])))) !!}<div class="fsc-block fsc-block--columns" style="--fsc-columns: {{ max(1, count($block['columns'] ?? [])) }}">
@foreach ($block['columns'] ?? [] as $column)
    <div class="fsc-column">{!! $column['html'] ?? '' !!}</div>
@endforeach
</div>

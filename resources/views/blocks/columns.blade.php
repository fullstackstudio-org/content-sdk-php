{!! \FullStackStudio\Content\Laravel\RichStyle::for(implode(' ', array_map(static fn ($c) => (string) ($c['html'] ?? ''), (array) ($block['columns'] ?? [])))) !!}<div class="fsc-block fsc-block--columns{{ ($look ?? '') === '' ? '' : ' ' . $look }}" style="--fsc-columns: {{ max(1, count($block['columns'] ?? [])) }}">
@foreach ($block['columns'] ?? [] as $columnIndex => $column)
    <div class="fsc-column"{!! $edit('columns.' . $columnIndex . '.html', 'rich') !!}>{!! $column['html'] ?? '' !!}</div>
@endforeach
</div>

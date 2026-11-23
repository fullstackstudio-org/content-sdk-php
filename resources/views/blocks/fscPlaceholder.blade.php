{{-- An incomplete block the Hub keeps in a builder preview (`{type: 'fscPlaceholder', id, label, reason}`), so the editor can still select it. It never
     reaches a live page; outside a preview this view prints nothing. --}}
@if ($preview)<div class="fsc-preview-empty">{{ $block['label'] ?? '' }}</div>@endif

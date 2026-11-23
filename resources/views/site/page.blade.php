{{-- Default view of a Hub page ($page, $path, $resolved). Publish: php artisan vendor:publish --tag=content-site-page --}}
@use('FullStackStudio\Content\Laravel\Auto\Auto')
@use('FullStackStudio\Content\Laravel\Site\Layout')
@use('FullStackStudio\Content\Laravel\Site\SiteStyle')
@extends(Layout::name())

@section(Auto::section())
{!! SiteStyle::for() !!}
@php($blocks = $page['blocks'] ?? [])
<div class="fsc-site-view">
{{-- One <h1> per page: the title, unless the first block is a hero (it prints the heading). --}}
@if (($page['title'] ?? '') !== '' && ($blocks[0]['type'] ?? null) !== 'hero')
<div class="{{ Auto::wrap() ?? 'fsc-wrap' }}">
    <header class="fsc-site-head"><h1 class="fsc-site-title">{{ $page['title'] }}</h1></header>
</div>
@endif
<x-content::page-blocks :blocks="$blocks" :media="$page['media'] ?? null" :wrap="Auto::wrap()" />
</div>
@endsection

{{-- Bridge for a component layout of the site (`{{ $slot }}`): the default views extend this view, which renders their content section and
     hands it to the layout as `$slot` (with an empty attribute bag for `@props`/`$attributes`). See FullStackStudio\Content\Laravel\Site\Layout. --}}
@include(\FullStackStudio\Content\Laravel\Site\Layout::target(), [
    'slot' => new \Illuminate\Support\HtmlString($__env->yieldContent(\FullStackStudio\Content\Laravel\Auto\Auto::section())),
    'attributes' => new \Illuminate\View\ComponentAttributeBag(),
])

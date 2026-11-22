{{-- Active products (module `products`) as cards: photo, name, short text and price, optionally of one category. The link follows
     `content.links.product` when set, else the path the Hub publishes (the products `pathTemplate`). Renders nothing when empty, on any Hub
     error or with the module off. Replace it: `content.blocks.productList`. --}}
@php($products = \FullStackStudio\Content\Laravel\ModuleBlockData::productList($block))
@if ($products !== null)
<section class="fsc-block fsc-block--productList">
    @if (($block['heading'] ?? '') !== '')<h2 class="fsc-productlist__heading">{{ $block['heading'] }}</h2>@endif
    <ul class="fsc-productlist">
    @foreach ($products as $product)
        <li class="fsc-productlist__item">
            <article class="fsc-productcard">
                @if ($product['cover'] !== null)
                <a class="fsc-productcard__media" href="{{ $product['href'] }}" tabindex="-1" aria-hidden="true"><x-content::image :media="$product['cover']" :base-url="$baseUrl" sizes="(min-width: 48rem) 25vw, 50vw" /></a>
                @endif
                <h3 class="fsc-productcard__title"><a href="{{ $product['href'] }}">{{ $product['name'] }}</a></h3>
                @if ($product['summary'] !== '')<p class="fsc-productcard__summary">{{ $product['summary'] }}</p>@endif
                @if ($product['price'] !== '')<p class="fsc-productcard__price">{{ $product['price'] }}</p>@endif
            </article>
        </li>
    @endforeach
    </ul>
</section>
@endif

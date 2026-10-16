<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;
use FullStackStudio\Content\Tags;

/**
 * Products (`$client->products`): read with any token (a pub token only sees active products), manage with a server token and `products:write`.
 */
final class Products
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * Without description, variants and photos (cover, "from" price and `inStock` only).
     *
     * @param array{category?: string, q?: string, status?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed> `products`, `page`, `limit`, `total`
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->get('/products', [Tags::PRODUCTS], Arr::pick($params, ['category', 'q', 'status', 'page', 'limit']), $options));
    }

    /**
     * By slug or id, with variants, photos and `descriptionHtml`. 404 for a draft or archived product with a pub token.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function get(string $ref, array $options = []): array
    {
        return $this->ctx->get('/products/' . ModuleContext::seg($ref), [Tags::PRODUCTS, Tags::product($ref)], null, $options)->data['product'] ?? [];
    }

    /**
     * The product categories as a tree.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function categories(array $options = []): array
    {
        return $this->ctx->get('/product-categories', [Tags::PRODUCT_CATEGORIES], null, $options)->data['categories'] ?? [];
    }

    /** Server token, `products:write`. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> the product */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/products', $input, $options)['product'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> `product`, `changed` */
    public function update(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/products/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/products/' . ModuleContext::seg($id), null, $options);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> the variant */
    public function createVariant(string $productId, array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/products/' . ModuleContext::seg($productId) . '/variants', $input, $options)['variant'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> `variant`, `changed` */
    public function updateVariant(string $productId, string $variantId, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/products/' . ModuleContext::seg($productId) . '/variants/' . ModuleContext::seg($variantId), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteVariant(string $productId, string $variantId, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/products/' . ModuleContext::seg($productId) . '/variants/' . ModuleContext::seg($variantId), null, $options);
    }

    /**
     * Atomic: `['delta' => -1]` or `['set' => 8]`. Stock never goes below zero or below what is reserved (409 `out_of_stock`).
     *
     * @param array{delta?: int, set?: int} $change
     * @param array<string, mixed> $options
     * @return array<string, mixed> the stock
     */
    public function setStock(string $productId, string $variantId, array $change, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/products/' . ModuleContext::seg($productId) . '/variants/' . ModuleContext::seg($variantId) . '/stock', $change, $options)['stock'] ?? [];
    }

    /**
     * @param array{limit?: int} $params
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function revisions(string $productId, array $params = [], array $options = []): array
    {
        return $this->ctx->execute(['path' => '/products/' . ModuleContext::seg($productId) . '/revisions', 'query' => Arr::pick($params, ['limit']), 'options' => $options])->data['revisions'] ?? [];
    }

    /** @param array<string, mixed> $input `name` is required @param array<string, mixed> $options @return array<string, mixed> the category */
    public function createCategory(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/product-categories', $input, $options)['category'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> `category`, `changed` */
    public function updateCategory(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/product-categories/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteCategory(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/product-categories/' . ModuleContext::seg($id), null, $options);
    }
}

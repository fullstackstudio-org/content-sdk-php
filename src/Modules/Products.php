<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\ContentException;
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
     * Product ids for "you might also like", ranked in the Hub (FullStack Intelligence): the hot topics of the consenting visitor first, then the
     * best sellers of the last 30 days, then the newest products. Only active products that can be bought. Needs a SERVER token; a pub token gets
     * a 403. Nothing is written and the answer holds no personal data.
     *
     * `$pid` is the visitor's consented browser id (`IntelligenceProxy::pid($request)` in Laravel: the `fsa_pid` cookie, null without consent or
     * under Global Privacy Control). Without one, or when the Hub cannot recognise it, you get the fallback. Pass `['gpc' => true]` in the options
     * when the visitor's request carried `Sec-GPC: 1`: the Hub then ignores the id even if one is given. `['locale' => 'en']` picks the language
     * (default: the project's default language). When personalisation is off for the project the Hub still answers with the fallback ids, so there
     * is always something to show.
     *
     * Not cached by the SDK (the answer depends on the visitor); the Hub caches per topic set for 60 seconds. For the full answer use
     * {@see self::recommendations()}, to get products instead of ids use {@see self::recommendedProducts()}.
     *
     * @param array{gpc?: bool, locale?: string, timeoutMs?: int} $options
     * @return list<string> product ids, best first (at most `$limit`, 1 to 24)
     */
    public function recommended(?string $pid = null, int $limit = 8, array $options = []): array
    {
        return $this->recommendations($pid, $limit, $options)['ids'];
    }

    /**
     * Like {@see self::recommended()}, with the Hub's flags: `active` (personalisation is on for the project) and `source` (`interest` when at
     * least one id came from the visitor's interests, otherwise `fallback`).
     *
     * @param array{gpc?: bool, locale?: string, timeoutMs?: int} $options
     * @return array{active: bool, source: string, ids: list<string>}
     */
    public function recommendations(?string $pid = null, int $limit = 8, array $options = []): array
    {
        $query = ['limit' => max(1, min(24, $limit)), 'locale' => $options['locale'] ?? null];

        // Only a well-formed browser id travels; anything else is the same as no id.
        if ($pid !== null && preg_match('/^[A-Za-z0-9-]{16,64}$/D', $pid) === 1) {
            $query['pid'] = $pid;
        }

        $data = $this->ctx->execute([
            'path' => '/products/recommended',
            'query' => $query,
            'headers' => ($options['gpc'] ?? false) === true ? ['Sec-GPC' => '1'] : [],
            'options' => Arr::pick($options, ['timeoutMs']),
        ])->data;
        $ids = array_values(array_filter(is_array($data['ids'] ?? null) ? $data['ids'] : [], 'is_string'));

        return ['active' => ($data['active'] ?? false) === true, 'source' => is_string($data['source'] ?? null) ? $data['source'] : 'fallback', 'ids' => $ids];
    }

    /**
     * The recommended products themselves: {@see self::recommended()} followed by one product read per id (`get()`, so the SDK cache and its
     * `product:{id}` tags apply). A product that disappeared in between is skipped, so the list can be shorter than `$limit`.
     *
     * @param array{gpc?: bool, locale?: string, timeoutMs?: int} $options
     * @return list<array<string, mixed>> as {@see self::get()}, in the Hub's order
     */
    public function recommendedProducts(?string $pid = null, int $limit = 8, array $options = []): array
    {
        $products = [];

        foreach ($this->recommended($pid, $limit, $options) as $id) {
            try {
                $product = $this->get($id, Arr::pick($options, ['timeoutMs']));
            } catch (ContentException $error) {
                if ($error->status() === 404) {
                    continue;
                }

                throw $error;
            }

            if ($product !== []) {
                $products[] = $product;
            }
        }

        return $products;
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

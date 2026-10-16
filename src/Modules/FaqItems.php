<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/** FAQ questions (`$client->faq->items`; server token). */
final class FaqItems
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $params the filters of the API (`status`, `category`, `q`, `page`, `limit`, ...) @param array<string, mixed> $options @return array<string, mixed> `items`, `total` */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute(['path' => '/faq/items', 'query' => $params, 'options' => $options]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/faq/items/' . ModuleContext::seg($id), 'options' => $options])->data['item'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/faq/items', $input, $options)['item'] ?? [];
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/faq/items/' . ModuleContext::seg($id), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function publish(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/faq/items/' . ModuleContext::seg($id) . '/publish', [], $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function unpublish(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/faq/items/' . ModuleContext::seg($id) . '/unpublish', [], $options);
    }

    /** @param list<string> $ids the new order @param array<string, mixed> $options @return array<string, mixed> */
    public function reorder(array $ids, array $options = []): array
    {
        return $this->ctx->write('POST', '/faq/items/reorder', ['ids' => $ids], $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/faq/items/' . ModuleContext::seg($id), null, $options);
    }
}

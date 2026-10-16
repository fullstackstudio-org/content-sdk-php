<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Newsletter subscribers (`$client->newsletter->subscribers`; server token). */
final class NewsletterSubscribers
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array{status?: string, list?: string, q?: string, page?: int, limit?: int} $params @param array<string, mixed> $options @return array<string, mixed> `subscribers`, `page`, `limit`, `total` */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/newsletter/subscribers',
            'query' => Arr::pick($params, ['status', 'list', 'q', 'page', 'limit']),
            'options' => $options,
        ]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/newsletter/subscribers/' . ModuleContext::seg($id), 'options' => $options])->data['subscriber'] ?? [];
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/newsletter/subscribers/' . ModuleContext::seg($id), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/newsletter/subscribers/' . ModuleContext::seg($id), null, $options);
    }

    /** Bulk import. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> the import result */
    public function import(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/subscribers', $input, $options)['result'] ?? [];
    }

    /** CSV export. @param array{status?: string, list?: string, q?: string} $params @param array<string, mixed> $options @return array{bytes: string, contentType: string, fileName: string|null} */
    public function export(array $params = [], array $options = []): array
    {
        return $this->ctx->download(['path' => '/newsletter/subscribers/export', 'query' => Arr::pick($params, ['status', 'list', 'q']), 'options' => $options]);
    }
}

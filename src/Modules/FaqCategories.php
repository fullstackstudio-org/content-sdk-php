<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/** FAQ categories (`$client->faq->categories`; server token). */
final class FaqCategories
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function list(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/faq/categories', 'options' => $options])->data['categories'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/faq/categories', $input, $options)['category'] ?? [];
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/faq/categories/' . ModuleContext::seg($id), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/faq/categories/' . ModuleContext::seg($id), null, $options);
    }
}

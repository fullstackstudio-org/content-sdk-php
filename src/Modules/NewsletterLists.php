<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/** Newsletter lists (`$client->newsletter->lists`; server token with the newsletter scopes). */
final class NewsletterLists
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $options @return array{lists: list<array<string, mixed>>, total: int} */
    public function list(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/newsletter/lists', 'options' => $options])->data;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/newsletter/lists/' . ModuleContext::seg($id), 'options' => $options])->data['list'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/lists', $input, $options)['list'] ?? [];
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/newsletter/lists/' . ModuleContext::seg($id), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/newsletter/lists/' . ModuleContext::seg($id), null, $options);
    }
}

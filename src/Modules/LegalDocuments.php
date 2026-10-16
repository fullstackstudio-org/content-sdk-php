<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Legal document management (`$client->legal->documents`; server token with `legal:write`). */
final class LegalDocuments
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array{locale?: string} $params @param array<string, mixed> $options @return array{documents: list<array<string, mixed>>} */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->execute(['path' => '/legal/documents', 'query' => Arr::pick($params, ['locale']), 'options' => $options])->data;
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array{document: array<string, mixed>} */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/legal/documents', $input, $options);
    }

    /** @param array<string, mixed> $options @return array{document: array<string, mixed>, versions: list<array<string, mixed>>} */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/legal/documents/' . ModuleContext::seg($id), 'options' => $options])->data;
    }

    /** @param array{slug?: string, title?: string} $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/legal/documents/' . ModuleContext::seg($id), $patch, $options);
    }

    /** Only a document without a published version (409 `version_immutable` otherwise). @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/legal/documents/' . ModuleContext::seg($id), null, $options);
    }

    /** @param array<string, mixed> $options @return array{versions: list<array<string, mixed>>} */
    public function versions(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/legal/documents/' . ModuleContext::seg($id) . '/versions', 'options' => $options])->data;
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function createVersion(string $id, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/legal/documents/' . ModuleContext::seg($id) . '/versions', $input, $options);
    }

    /** @param array<string, mixed> $options @return array{version: array<string, mixed>} */
    public function getVersion(string $id, int $version, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/legal/documents/' . ModuleContext::seg($id) . '/versions/' . ModuleContext::seg($version), 'options' => $options])->data;
    }

    /** Drafts only; a published version gives 409 `version_immutable`. @param array{title?: string, bodyHtml?: string, summary?: string|null} $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function updateVersion(string $id, int $version, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/legal/documents/' . ModuleContext::seg($id) . '/versions/' . ModuleContext::seg($version), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array{deleted: true, version: int} */
    public function deleteVersion(string $id, int $version, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/legal/documents/' . ModuleContext::seg($id) . '/versions/' . ModuleContext::seg($version), null, $options);
    }

    /** Publishes a draft, now or at `effectiveAt` (ISO with time zone). Afterwards it is immutable. @param array{effectiveAt?: string} $input @param array<string, mixed> $options @return array{version: array<string, mixed>, document: array<string, mixed>} */
    public function publish(string $id, int $version, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/legal/documents/' . ModuleContext::seg($id) . '/versions/' . ModuleContext::seg($version) . '/publish', $input, $options);
    }
}

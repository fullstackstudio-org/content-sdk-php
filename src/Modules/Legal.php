<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;
use FullStackStudio\Content\Tags;

/**
 * Legal documents (`$client->legal`; module `legal`). Reading and accepting work with a pub token (`legal:read`, `legal:accept`); managing documents
 * and versions (`$legal->documents`) needs a server token with `legal:write`.
 */
final class Legal
{
    /** Server token (`legal:write`). */
    public readonly LegalDocuments $documents;

    public function __construct(private readonly ModuleContext $ctx)
    {
        $this->documents = new LegalDocuments($ctx);
    }

    /**
     * Documents that have a version in force (`legal:read`).
     *
     * @param array{locale?: string} $params
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->get('/legal', [Tags::LEGAL], Arr::pick($params, ['locale']), $options)->data['documents'] ?? [];
    }

    /**
     * The CURRENT version of a document by kind (`terms`, `privacy_statement`, ...), slug or id; 404 while nothing is in force.
     *
     * @param array{locale?: string} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function get(string $ref, array $params = [], array $options = []): array
    {
        return $this->ctx->get('/legal/' . ModuleContext::seg($ref), [Tags::LEGAL, Tags::legalDocument($ref)], Arr::pick($params, ['locale']), $options)->data['document'] ?? [];
    }

    /**
     * A published version by number (also an earlier or a scheduled one); a draft is 404.
     *
     * @param array{locale?: string} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getVersion(string $ref, int $version, array $params = [], array $options = []): array
    {
        return $this->ctx->get('/legal/' . ModuleContext::seg($ref) . '/versions/' . ModuleContext::seg($version), [Tags::legalDocument($ref)], Arr::pick($params, ['locale']), $options)->data['document'] ?? [];
    }

    /**
     * The PDF of the current version, or of version `version`.
     *
     * @param array{version?: int, locale?: string} $params
     * @param array<string, mixed> $options
     * @return array{bytes: string, contentType: string, fileName: string|null}
     */
    public function pdf(string $ref, array $params = [], array $options = []): array
    {
        $version = $params['version'] ?? null;

        return $this->ctx->download([
            'path' => $version ? '/legal/' . ModuleContext::seg($ref) . '/versions/' . ModuleContext::seg($version) . '/pdf' : '/legal/' . ModuleContext::seg($ref) . '/pdf',
            'query' => Arr::pick($params, ['locale']),
            'options' => $options,
        ]);
    }

    /**
     * Records that a visitor, member (`memberSession`) or order (server token) accepted these versions.
     *
     * @param array<string, mixed> $input
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function accept(array $input, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/legal/accept',
            'body' => $input,
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }
}

<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Newsletter campaigns (`$client->newsletter->campaigns`; server token). */
final class NewsletterCampaigns
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array{status?: string, page?: int, limit?: int} $params @param array<string, mixed> $options @return array<string, mixed> `campaigns`, `page`, `limit`, `total` */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute(['path' => '/newsletter/campaigns', 'query' => Arr::pick($params, ['status', 'page', 'limit']), 'options' => $options]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/newsletter/campaigns/' . ModuleContext::seg($id), 'options' => $options])->data['campaign'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/campaigns', $input, $options)['campaign'] ?? [];
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $id, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/newsletter/campaigns/' . ModuleContext::seg($id), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/newsletter/campaigns/' . ModuleContext::seg($id), null, $options);
    }

    /** Sends to real subscribers. @param array<string, mixed> $options @return array<string, mixed> */
    public function send(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/campaigns/' . ModuleContext::seg($id) . '/send', [], $options);
    }

    /** `$at` is an ISO 8601 string or a DateTimeInterface (sent as UTC, like `Date#toISOString`). @param array<string, mixed> $options @return array<string, mixed> the campaign */
    public function schedule(string $id, string|DateTimeInterface $at, array $options = []): array
    {
        if ($at instanceof DateTimeInterface) {
            $at = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }

        return $this->ctx->write('POST', '/newsletter/campaigns/' . ModuleContext::seg($id) . '/schedule', ['at' => $at], $options)['campaign'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> the campaign */
    public function pause(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/campaigns/' . ModuleContext::seg($id) . '/pause', [], $options)['campaign'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> the campaign */
    public function resume(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/campaigns/' . ModuleContext::seg($id) . '/resume', [], $options)['campaign'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function cancel(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/campaigns/' . ModuleContext::seg($id) . '/cancel', [], $options);
    }

    /** Sends a test mail to `$to`. @param array<string, mixed> $options @return array<string, mixed> */
    public function test(string $id, string $to, array $options = []): array
    {
        return $this->ctx->write('POST', '/newsletter/campaigns/' . ModuleContext::seg($id) . '/test', ['to' => $to], $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function progress(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/newsletter/campaigns/' . ModuleContext::seg($id) . '/progress', 'options' => $options])->data['progress'] ?? [];
    }
}

<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/** Ticket types of an event (`$client->events->ticketTypes`; server token). */
final class EventTicketTypes
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $options @return array{ticketTypes: list<array<string, mixed>>} */
    public function list(string $idOrSlug, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/events/' . ModuleContext::seg($idOrSlug) . '/ticket-types', 'options' => $options])->data;
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(string $idOrSlug, array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/events/' . ModuleContext::seg($idOrSlug) . '/ticket-types', $input, $options);
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $idOrSlug, string $typeId, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/events/' . ModuleContext::seg($idOrSlug) . '/ticket-types/' . ModuleContext::seg($typeId), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $idOrSlug, string $typeId, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/events/' . ModuleContext::seg($idOrSlug) . '/ticket-types/' . ModuleContext::seg($typeId), null, $options);
    }
}

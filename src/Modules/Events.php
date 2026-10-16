<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/** Events (`$client->events`; module `events`): list, register and pay as a visitor; with a server token also the attendance list, check-in and management. */
final class Events
{
    public readonly EventTicketTypes $ticketTypes;

    public function __construct(private readonly ModuleContext $ctx)
    {
        $this->ticketTypes = new EventTicketTypes($ctx);
    }

    /** @param array{upcoming?: bool, page?: int, limit?: int} $params @param array<string, mixed> $options @return array<string, mixed> `events`, `page`, `limit`, `total` */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/events',
            'query' => ['upcoming' => !empty($params['upcoming']) ? 'true' : null, ...Arr::pick($params, ['page', 'limit'])],
            'options' => $options,
        ]));
    }

    /** By slug or id. @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $idOrSlug, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/events/' . ModuleContext::seg($idOrSlug), 'options' => $options])->data['event'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function register(string $idOrSlug, array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/events/' . ModuleContext::seg($idOrSlug) . '/registrations', $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> `payment` */
    public function startPayment(string $registrationId, array $options = []): array
    {
        return $this->ctx->write('POST', '/events/registrations/' . ModuleContext::seg($registrationId) . '/payment', [], $options);
    }

    /** The attendance list. @param array{q?: string, checkedIn?: bool, page?: int, limit?: int} $params @param array<string, mixed> $options @return array<string, mixed> */
    public function tickets(string $idOrSlug, array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/events/' . ModuleContext::seg($idOrSlug) . '/tickets',
            'query' => Arr::pick($params, ['q', 'checkedIn', 'page', 'limit']),
            'options' => $options,
        ]));
    }

    /** @param array{eventId?: string} $input @param array<string, mixed> $options @return array<string, mixed> */
    public function checkIn(string $token, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/events/checkin', ['token' => $token, ...(!empty($input['eventId']) ? ['eventId' => $input['eventId']] : [])], $options);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function create(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/events', $input, $options);
    }

    /** @param array<string, mixed> $patch @param array<string, mixed> $options @return array<string, mixed> */
    public function update(string $idOrSlug, array $patch, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/events/' . ModuleContext::seg($idOrSlug), $patch, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $idOrSlug, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/events/' . ModuleContext::seg($idOrSlug), null, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> `registration` and more */
    public function getRegistration(string $registrationId, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/events/registrations/' . ModuleContext::seg($registrationId), 'options' => $options])->data;
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function cancelRegistration(string $registrationId, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/events/registrations/' . ModuleContext::seg($registrationId) . '/cancel', $input, $options);
    }
}

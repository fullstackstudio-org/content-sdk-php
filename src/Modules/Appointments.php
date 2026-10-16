<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;
use FullStackStudio\Content\Tags;

/**
 * Appointments (`$client->appointments`; module `appointments`). Booking works with a pub token as well as a server token; managing the agenda
 * needs a server token with `appointments:write`. All times are ISO 8601 with an offset in Europe/Amsterdam.
 */
final class Appointments
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** The active services (a server token also sees inactive ones, with buffers). @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function services(array $options = []): array
    {
        return $this->ctx->get('/appointments/services', [Tags::APPOINTMENT_SERVICES], null, $options)->data['services'] ?? [];
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function service(string $id, array $options = []): array
    {
        return $this->ctx->get('/appointments/services/' . ModuleContext::seg($id), [Tags::APPOINTMENT_SERVICES], null, $options)->data['service'] ?? [];
    }

    /** The active resources (staff members, chairs, rooms) a visitor can pick; `id` and `name` only. @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function resources(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/appointments/resources', 'options' => $options])->data['resources'] ?? [];
    }

    /**
     * Free times; never cached. Pick one and pass its `startsAt` unchanged to `book`.
     *
     * @param array{service: string, from: string, to: string, resource?: string} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function availability(array $params, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/appointments/availability', 'query' => Arr::pick($params, ['service', 'from', 'to', 'resource']), 'options' => $options])->data;
    }

    /**
     * Books a time. `409 slot_taken` when someone was faster. The returned `cancelToken` is the booker's key to cancel (also in their mail); it is shown once.
     * With a server token and `memberSession` the booking belongs to that member.
     *
     * @param array<string, mixed> $input
     * @param array{memberSession?: string, clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function book(array $input, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/appointments',
            'body' => $input,
            'headers' => $this->ctx->visitorHeaders($options['memberSession'] ?? null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }

    /** The booker cancels with the token from `book` / the mail (your `cancelUrl` page). @param array<string, mixed> $options @return array{appointment: array<string, mixed>, changed: bool} */
    public function cancelWithToken(string $id, string $token, array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/' . ModuleContext::seg($id) . '/cancel', ['token' => $token], $options);
    }

    /** Starts the payment in advance again (status `pending_payment`), with the token from `book`. @param array<string, mixed> $options @return array<string, mixed> the payment */
    public function restartPayment(string $id, string $token, array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/' . ModuleContext::seg($id) . '/payment', ['token' => $token], $options)['payment'] ?? [];
    }

    // --- Agenda management (server token, `appointments:write`) ---------------------------------------------------------------

    /**
     * @param array{status?: string, service?: string, resource?: string, from?: string, to?: string, q?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed> `appointments`, `page`, `limit`, `total`
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/appointments',
            'query' => Arr::pick($params, ['status', 'service', 'resource', 'from', 'to', 'q', 'page', 'limit']),
            'options' => $options,
        ]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/appointments/' . ModuleContext::seg($id), 'options' => $options])->data['appointment'] ?? [];
    }

    /** Links the booking to a customer (module `customers`); `null` unlinks. @param array<string, mixed> $options @return array<string, mixed> */
    public function linkCustomer(string $id, ?string $customerId, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/appointments/' . ModuleContext::seg($id), ['customerId' => $customerId], $options);
    }

    /** @param array{notifyCustomer?: bool} $input @param array<string, mixed> $options @return array<string, mixed> */
    public function confirm(string $id, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/' . ModuleContext::seg($id) . '/confirm', $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function complete(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/' . ModuleContext::seg($id) . '/complete', [], $options);
    }

    /** @param array{reason?: string, notifyCustomer?: bool} $input @param array<string, mixed> $options @return array<string, mixed> */
    public function cancel(string $id, array $input = [], array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/' . ModuleContext::seg($id) . '/cancel', $input, $options);
    }

    /** `name` and `durationMinutes` are required. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function createService(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/services', $input, $options)['service'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updateService(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/appointments/services/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteService(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/appointments/services/' . ModuleContext::seg($id), null, $options);
    }

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listResources(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/appointments/resources', 'options' => $options])->data['resources'] ?? [];
    }

    /** `name` is required. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function createResource(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/resources', $input, $options)['resource'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updateResource(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/appointments/resources/' . ModuleContext::seg($id), $input, $options);
    }

    /** 409 while it has appointments: set `active => false` instead. @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteResource(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/appointments/resources/' . ModuleContext::seg($id), null, $options);
    }

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listRules(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/appointments/rules', 'options' => $options])->data['rules'] ?? [];
    }

    /** `weekday`, `startTime` and `endTime` are required. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function createRule(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/rules', $input, $options)['rule'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updateRule(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/appointments/rules/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteRule(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/appointments/rules/' . ModuleContext::seg($id), null, $options);
    }

    /** @param array{includePast?: bool} $params @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listExceptions(array $params = [], array $options = []): array
    {
        return $this->ctx->execute(['path' => '/appointments/exceptions', 'query' => Arr::pick($params, ['includePast']), 'options' => $options])->data['exceptions'] ?? [];
    }

    /** `kind` (`closed`|`open`) and `startsOn` are required. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function createException(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/appointments/exceptions', $input, $options)['exception'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function updateException(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/appointments/exceptions/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteException(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/appointments/exceptions/' . ModuleContext::seg($id), null, $options);
    }
}

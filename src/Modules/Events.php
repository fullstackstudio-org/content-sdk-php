<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;
use FullStackStudio\Content\Tags;

/** Events (`$client->events`; module `events`): list, register and pay as a visitor; with a server token also the attendance list, check-in and management. */
final class Events
{
    public readonly EventTicketTypes $ticketTypes;

    public function __construct(private readonly ModuleContext $ctx)
    {
        $this->ticketTypes = new EventTicketTypes($ctx);
    }

    /**
     * The published events by start time. Not cached by default (registration flows want the seats left as they are now); pass
     * `['cache' => true]` to cache the answer under `content:events`, which the webhook clears on every `event.*` change (the page builder
     * blocks do). `status` filters a server token's answer (`draft|published|cancelled`).
     *
     * With a content language (the client's, or `$options['locale']`) the texts of events and ticket types come in that language and every
     * event has `locale` and `availableLocales` (multilanguage plan, task 16); a single-language project sends nothing extra.
     *
     * @param array{upcoming?: bool, status?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed> `events`, `page`, `limit`, `total`
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute([
            'path' => '/events',
            'query' => ['upcoming' => !empty($params['upcoming']) ? 'true' : null, ...Arr::pick($params, ['status', 'page', 'limit']), 'locale' => $this->ctx->localeQuery($options)],
            ...self::cacheSpec($options),
            'options' => $options,
        ]));
    }

    /** By slug or id. Cached only with `['cache' => true]`, see {@see self::list()}. @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $idOrSlug, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/events/' . ModuleContext::seg($idOrSlug), 'query' => ['locale' => $this->ctx->localeQuery($options)], ...self::cacheSpec($options), 'options' => $options])->data['event'] ?? [];
    }

    /** @param array<string, mixed> $options @return array{tags?: list<string>} */
    private static function cacheSpec(array $options): array
    {
        return ($options['cache'] ?? null) === true ? ['tags' => [Tags::EVENTS]] : [];
    }

    /**
     * Registers a visitor. With a server token the visitor's IP goes along as `X-FSC-Client-Ip` (`clientIp` in the options, else the
     * client's configured IP), so the Hub's per-visitor limits count the visitor and not your server. The visitor's language goes in the body
     * as `locale` (an own `$input['locale']` wins, else the client's content language on a multilingual project): the registration and
     * its ticket mail then speak it.
     *
     * @param array<string, mixed> $input @param array{clientIp?: string, timeoutMs?: int, locale?: string} $options @return array<string, mixed>
     */
    public function register(string $idOrSlug, array $input, array $options = []): array
    {
        return $this->ctx->execute([
            'method' => 'POST',
            'path' => '/events/' . ModuleContext::seg($idOrSlug) . '/registrations',
            'body' => $this->ctx->bodyWithLocale($input, $options),
            'headers' => $this->ctx->visitorHeaders(null, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
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

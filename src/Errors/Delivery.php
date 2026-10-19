<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Errors;

use FullStackStudio\Content\Cache\Store;

/**
 * Picks the ingest for an event: the private backend host (errors.int0.net, needs network egress from the pod) first, the public ingest
 * (errors.fullstackstudio.nl, the browser DSN) when the backend did not answer. After a failed backend attempt the backend is skipped for
 * 10 minutes, so a site without the egress rule does not wait a second on every error.
 */
final class Delivery
{
    private const BACKEND_DOWN_KEY = 'fsc:errors:backend-down';

    public function __construct(
        private readonly EnvelopeSender $sender,
        private readonly ?Store $store = null,
        private readonly string $preferred = 'backend',
    ) {}

    /**
     * @param array<string, mixed> $event
     * @return int the final HTTP status (0 = nothing arrived)
     */
    public function deliver(array $event, ErrorsConfig $config, ?string $origin): int
    {
        $backend = $config->backendDsn;
        $public = $config->browserDsn;

        if ($backend !== null && $public !== null && ($this->preferred === 'public' || $this->store?->get(self::BACKEND_DOWN_KEY) !== null)) {
            return $this->sender->send($public, $event, $origin);
        }

        if ($backend === null) {
            return $public === null ? 0 : $this->sender->send($public, $event, $origin);
        }

        $status = $this->sender->send($backend, $event, $origin);

        if ($status !== 0 || $public === null) {
            return $status;
        }

        $this->store?->put(self::BACKEND_DOWN_KEY, ['at' => time()], 600);

        return $this->sender->send($public, $event, $origin);
    }
}

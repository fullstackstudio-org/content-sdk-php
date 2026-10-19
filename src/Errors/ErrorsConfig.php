<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Errors;

/**
 * The error-monitoring settings of a site, from `GET /api/v1/project/errors` (module `errors` in Content) or from env overrides.
 * A DSN is `https://<32 hex key>@<host>/<numeric project>`; anything else is treated as "off".
 */
final class ErrorsConfig
{
    public const DSN_PATTERN = '#^https://([a-f0-9]{32})@([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::(\d{1,5}))?/([1-9]\d{0,9})$#D';

    public const ENVIRONMENT_PATTERN = '/^[a-z0-9_-]{1,32}$/D';

    public const RELEASE_PATTERN = '/^[A-Za-z0-9._@+-]{1,64}$/D';

    public function __construct(
        public readonly ?string $backendDsn,
        public readonly ?string $browserDsn,
        public readonly string $environment = 'production',
        public readonly ?string $release = null,
    ) {}

    /** @param array<string, mixed> $data the `errors` object of the API answer */
    public static function fromApi(array $data, ?string $release): self
    {
        $backend = is_array($data['backend'] ?? null) ? self::dsn($data['backend']['dsn'] ?? null) : null;
        $browser = is_array($data['browser'] ?? null) ? self::dsn($data['browser']['dsn'] ?? null) : null;

        return new self($backend, $browser, self::environment($data['environment'] ?? null), self::release($release));
    }

    public function isActive(): bool
    {
        return $this->backendDsn !== null || $this->browserDsn !== null;
    }

    /** @return array{key: string, host: string, port: ?int, projectId: int}|null */
    public static function parseDsn(?string $dsn): ?array
    {
        if ($dsn === null || preg_match(self::DSN_PATTERN, $dsn, $m) !== 1) {
            return null;
        }

        $port = $m[3] !== '' ? (int) $m[3] : null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return ['key' => $m[1], 'host' => $m[2], 'port' => $port, 'projectId' => (int) $m[4]];
    }

    public static function dsn(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return self::parseDsn($value) !== null ? $value : null;
    }

    public static function environment(mixed $value): string
    {
        return is_string($value) && preg_match(self::ENVIRONMENT_PATTERN, $value) === 1 ? $value : 'production';
    }

    public static function release(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::RELEASE_PATTERN, $value) === 1 ? $value : null;
    }

    /** The envelope endpoint of a DSN: `https://host[:port]/api/<project>/envelope/`. */
    public static function envelopeUrl(string $dsn): ?string
    {
        $parts = self::parseDsn($dsn);

        return $parts === null ? null : 'https://' . $parts['host'] . ($parts['port'] !== null ? ':' . $parts['port'] : '') . '/api/' . $parts['projectId'] . '/envelope/';
    }

    /** @return array{backendDsn: ?string, browserDsn: ?string, environment: string, release: ?string} */
    public function toArray(): array
    {
        return ['backendDsn' => $this->backendDsn, 'browserDsn' => $this->browserDsn, 'environment' => $this->environment, 'release' => $this->release];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(self::dsn($data['backendDsn'] ?? null), self::dsn($data['browserDsn'] ?? null), self::environment($data['environment'] ?? null), self::release($data['release'] ?? null));
    }
}

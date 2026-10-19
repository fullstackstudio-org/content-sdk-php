<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Errors;

use Throwable;

/**
 * Sends one error event as a Sentry envelope to GlitchTip (`POST https://<host>/api/<project>/envelope/`). A minimal sender instead of
 * `sentry/sentry-laravel`: no extra dependencies (and no version clash with a site that has its own Sentry SDK), and nothing can be sent
 * that `EventBuilder`/`BrowserEvent` did not build from their allowlist.
 *
 * Short timeouts (1 s connect, 2 s total), no redirects, no cookies. The transport is injectable for tests; the default is cURL.
 * Returns the HTTP status, 0 when nothing arrived (timeout, DNS, connection refused, e.g. a missing network policy).
 */
final class EnvelopeSender
{
    /** @var callable(string, array<string, string>, string): int */
    private $transport;

    /** @param callable(string, array<string, string>, string): int|null $transport (url, headers, body) => status */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? self::curl(...);
    }

    /** @param array<string, mixed> $event */
    public function send(string $dsn, array $event, ?string $origin = null): int
    {
        $parts = ErrorsConfig::parseDsn($dsn);
        $url = ErrorsConfig::envelopeUrl($dsn);

        if ($parts === null || $url === null) {
            return 0;
        }

        $headers = [
            'Content-Type' => 'application/x-sentry-envelope',
            'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=' . EventBuilder::SDK_NAME . '/' . \FullStackStudio\Content\ContentClient::VERSION . ', sentry_key=' . $parts['key'],
        ];

        if ($origin !== null && preg_match('#^https?://[a-z0-9.-]+(?::\d+)?$#iD', $origin) === 1) {
            $headers['Origin'] = $origin;
        }

        try {
            return ($this->transport)($url, $headers, self::envelope($dsn, $event));
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param array<string, mixed> $event */
    public static function envelope(string $dsn, array $event): string
    {
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $header = json_encode(['event_id' => $event['event_id'] ?? null, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'dsn' => $dsn], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $item = json_encode(['type' => 'event', 'length' => strlen($payload)], JSON_THROW_ON_ERROR);

        return $header . "\n" . $item . "\n" . $payload . "\n";
    }

    /** @param array<string, string> $headers */
    private static function curl(string $url, array $headers, string $body): int
    {
        if (! function_exists('curl_init')) {
            return 0;
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return 0;
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 2000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return $status;
    }
}

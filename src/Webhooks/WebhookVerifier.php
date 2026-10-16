<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Webhooks;

use InvalidArgumentException;
use stdClass;

/**
 * Verifies webhooks from Fullstack Content (mirror of `webhooks.ts`).
 *
 * Scheme (docs/webhooks.md): header `X-FSC-Signature: t=<unix-seconds>,v1=<hex>` with
 * `v1 = HMAC-SHA256(secret, "<t>.<raw body>")`. Check against the RAW body (never re-serialized JSON), keep `t` within five
 * minutes of your own clock, compare in constant time and try every `v1=` (during a secret rotation there can be two).
 */
final class WebhookVerifier
{
    public const SIGNATURE_HEADER = 'x-fsc-signature';
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verifies a received webhook and returns the event. Never throws on bad input: check `$result->ok`.
     *
     * @param string $body the RAW request body
     * @param string|null $signatureHeader the `X-FSC-Signature` header value
     * @param int|null $now unix seconds, for tests only
     */
    public static function verify(string $body, ?string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE_SECONDS, ?int $now = null): VerifyResult
    {
        if ($secret === '') {
            return VerifyResult::failure(VerifyResult::MISSING_SECRET);
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            return VerifyResult::failure(VerifyResult::MISSING_SIGNATURE);
        }

        $parsed = self::parseSignatureHeader($signatureHeader);

        if ($parsed === null) {
            return VerifyResult::failure(VerifyResult::MALFORMED);
        }

        if (abs(($now ?? time()) - $parsed['timestamp']) > $tolerance) {
            return VerifyResult::failure(VerifyResult::EXPIRED);
        }

        $expected = hash_hmac('sha256', $parsed['timestamp'] . '.' . $body, $secret);

        // Compare all candidates (do not stop at the first match): the duration then does not depend on which one matches.
        $match = false;

        foreach ($parsed['signatures'] as $signature) {
            $match = hash_equals($expected, $signature) || $match;
        }

        if (!$match) {
            return VerifyResult::failure(VerifyResult::MISMATCH);
        }

        $object = json_decode($body);

        if (!$object instanceof stdClass
            || !is_string($object->id ?? null)
            || !is_string($object->type ?? null)
            || !is_string($object->createdAt ?? null)
            || !is_string($object->projectId ?? null)
            || !($object->data ?? null) instanceof stdClass) {
            return VerifyResult::failure(VerifyResult::INVALID_PAYLOAD);
        }

        /** @var array{id: string, type: string, createdAt: string, projectId: string, data: array<string, mixed>} $event */
        $event = json_decode($body, true);

        return VerifyResult::success($event);
    }

    /**
     * Computes the header value for `$body` at time `$timestamp` (unix seconds). For tests and for calling your receiver locally
     * without Content; Content itself signs with the same computation.
     */
    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        if ($timestamp <= 0) {
            throw new InvalidArgumentException('Invalid timestamp for the webhook signature');
        }

        return "t={$timestamp},v1=" . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** @return array{timestamp: int, signatures: list<string>}|null */
    private static function parseSignatureHeader(string $header): ?array
    {
        if (strlen($header) > 1024) {
            return null;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $separator = strpos($part, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($part, 0, $separator));
            $value = trim(substr($part, $separator + 1));

            if ($key === 't') {
                if (preg_match('/^[0-9]{1,12}$/D', $value) !== 1) {
                    return null;
                }

                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/D', $value) === 1) {
                $signatures[] = $value;
            }
        }

        return $timestamp === null || $signatures === [] ? null : ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}

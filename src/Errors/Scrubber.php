<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Errors;

/**
 * Text filter for everything that goes to GlitchTip (exception messages). A verbatim port of `FullStackStudio\Core\Telemetry\Scrubber`
 * (fss-core v0.63.0) and `scrubText` in `resources/js/fsc-errors.js`: both run against `tests/Fixtures/telemetry/scrub-vectors.json`, a copy of
 * the fss-core vectors. Change all three together and add a vector.
 *
 * Order matters: SQL first (a QueryException carries the whole query with bindings), then URL query strings, then the single
 * patterns (keys/tokens before numbers, otherwise half a token stays).
 */
final class Scrubber
{
    public const MAX_LENGTH = 300;

    /** @var array<string, string> pattern => replacement, in this order */
    private const PATTERNS = [
        // Authorization-like values: "Bearer <x>", "Basic <x>", "token=<x>", "password: <x>".
        '/\b(bearer|basic)\s+[^\s,;]+/i' => '$1 [token]',
        '/\b(password|passwd|pwd|secret|(?:access[_-]?|refresh[_-]?)?token|code|api[_-]?key|apikey|authorization|auth|signature|sig|otp|session|cookie)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;]+)/i' => '$1$2[redacted]',
        // JWT
        '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/' => '[token]',
        // Tokens with a known prefix (own platform, Stripe-like, GitHub, Slack, Mollie, Moneybird).
        '/\b(?:fss|fsc|fsv|sk|pk|rk|whsec|ghp|gho|ghs|github_pat|xox[abpr]|test|live|access)_[A-Za-z0-9_-]{8,}/' => '[token]',
        // Email addresses
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[email]',
        // UUID (capability tokens are often a uuid too)
        '/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/' => '[uuid]',
        // IBAN (with or without spaces)
        '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b/' => '[iban]',
        // Long random strings (hex/base64url) = keys, hashes, tokens
        '/\b(?=[A-Za-z0-9_-]{20,}\b)(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]+/' => '[token]',
        // Phone numbers: international (+31 / 0031) and Dutch national (06…, 0xx…)
        '/(?<![\w])(?:\+|00)[1-9](?:[\s().-]?\d){7,14}(?!\w)/' => '[phone]',
        '/(?<![\w])0(?:[\s.-]?\d){9}(?!\w)/' => '[phone]',
        // Postcode (NL)
        '/\b\d{4}\s?[A-Z]{2}\b/' => '[postcode]',
        // IPv4
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[ip]',
        // Long numbers (customer/invoice/card numbers, amounts in cents)
        '/\b\d{6,}\b/' => '[number]',
    ];

    /**
     * Removes personal and secret data from free text and cuts it at MAX_LENGTH characters.
     */
    public static function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $text = self::sql($value);
        $text = self::stripUrlQueries($text);
        $text = self::quoted($text);
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH - 1).'…' : $text;
    }

    private static function sql(string $text): string
    {
        // QueryException: "SQLSTATE[23505]: Unique violation: 7 ERROR: ... (Connection: pgsql, SQL: insert ...)".
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\](?::\s*([^:(]+))?/', $text, $m)) {
            return 'SQLSTATE['.$m[1].']'.(isset($m[2]) ? ': '.trim($m[2]) : '');
        }
        // Bare SQL in a message (e.g. from an own exception)
        if (preg_match('/\b(select\s.+\sfrom|insert\s+into|update\s+\S+\s+set|delete\s+from)\b/is', $text)) {
            return (string) preg_replace('/\b(select|insert|update|delete|with)\b.*$/is', '[sql]', $text);
        }

        return $text;
    }

    private static function stripUrlQueries(string $text): string
    {
        return (string) preg_replace_callback(
            '{(\bhttps?://[^\s?#"\'<>]+|(?<![\w/.:])/[A-Za-z0-9._~/%-]+)(\?[^\s#"\'<>]*)?(#[^\s"\'<>]*)?}',
            fn (array $m): string => self::path($m[1]).(($m[2] ?? '') !== '' ? '?[query]' : ''),
            $text,
        );
    }

    /** Replaces path segments that look like a token, id or email address. */
    private static function path(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        $prefix = '';
        if (preg_match('~^(https?://[^/]+)(.*)$~i', $path, $m)) {
            $prefix = (string) preg_replace('~^(https?://)[^/]*@~i', '$1', $m[1]);
            $path = $m[2];
        }
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '') {
                continue;
            }
            if (str_contains($decoded, '@')) {
                $segments[$i] = '[email]';
            } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $decoded)) {
                $segments[$i] = '[uuid]';
            } elseif (preg_match('/^\d+$/', $decoded)) {
                $segments[$i] = '[id]';
            } elseif (strlen($decoded) >= 16 && preg_match('/^[A-Za-z0-9_.~-]+$/', $decoded) && preg_match('/\d/', $decoded) && preg_match('/[A-Za-z]/', $decoded)) {
                $segments[$i] = '[token]';
            }
        }

        return $prefix.implode('/', $segments);
    }

    /**
     * Quoted values are almost always input ("Customer 'Jansen' not found"). Classes, methods and config keys ("App\Foo",
     * "Foo::bar", "fss-core.auth") stay readable.
     */
    private static function quoted(string $text): string
    {
        return (string) preg_replace_callback(
            '/(["\'`])([^"\'`\r\n]{1,200})\1/u',
            static function (array $m): string {
                $inner = $m[2];
                $identifier = preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:::[A-Za-z_][A-Za-z0-9_]*(?:\(\))?)?$/', $inner) === 1
                    && (str_contains($inner, '\\') || str_contains($inner, '::'));
                $dotted = preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/', $inner) === 1;

                return $identifier || $dotted ? $m[0] : $m[1].'[value]'.$m[1];
            },
            $text,
        );
    }
}

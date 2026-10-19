<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Errors;

/**
 * The server side of the browser tunnel: parses an envelope from the browser reporter and REBUILDS its error event from an allowlist
 * (a PHP port of `prepareBrowserEvent` in fss-core and `resources/js/fsc-errors.js`). Nothing the browser sent is forwarded as is: the
 * tunnel builds a fresh envelope with the site's own DSN, environment and release.
 *
 * Kept: event id, time, the exception types (built-in JS errors only, else `Error`), the message after `Scrubber::text`, the mechanism and
 * the stack frames of scripts on the site's own origin (path without query or fragment, line/column, a plain function name). An event
 * without one same-origin script frame is dropped (an error of a third-party script is not the site's error).
 */
final class BrowserEvent
{
    public const ERROR_TYPES = ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'URIError', 'EvalError', 'AggregateError', 'DOMException'];

    public const MECHANISMS = ['onerror', 'onunhandledrejection', 'generic'];

    /**
     * The event items of an envelope (`header\nitem-header\npayload…`), or null when it is not a valid envelope or names another DSN.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function eventsFromEnvelope(string $body, ?string $expectedDsn): ?array
    {
        $newline = strpos($body, "\n");
        $header = json_decode($newline === false ? $body : substr($body, 0, $newline), true);

        if (! is_array($header)) {
            return null;
        }

        // No open relay: an envelope that names a DSN must name exactly the site's own browser DSN.
        if (array_key_exists('dsn', $header) && ($expectedDsn === null || ErrorsConfig::dsn($header['dsn']) !== $expectedDsn)) {
            return null;
        }

        $events = [];
        $offset = $newline === false ? strlen($body) : $newline + 1;

        while ($offset < strlen($body) && count($events) < 3) {
            $end = strpos($body, "\n", $offset);
            $itemHeader = json_decode($end === false ? substr($body, $offset) : substr($body, $offset, $end - $offset), true);

            if (! is_array($itemHeader)) {
                return null;
            }

            $offset = $end === false ? strlen($body) : $end + 1;
            $length = $itemHeader['length'] ?? null;

            if (is_int($length) && $length >= 0) {
                $payload = substr($body, $offset, $length);
                $offset += $length + 1;
            } else {
                $end = strpos($body, "\n", $offset);
                $payload = $end === false ? substr($body, $offset) : substr($body, $offset, $end - $offset);
                $offset = $end === false ? strlen($body) : $end + 1;
            }

            if (($itemHeader['type'] ?? null) === 'event') {
                $event = json_decode($payload, true, 32);

                if (is_array($event)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function prepare(array $event, ErrorsConfig $config, string $origin): ?array
    {
        $values = $event['exception']['values'] ?? null;

        if (! is_array($values) || ! array_is_list($values) || isset($event['type'])) {
            return null;
        }

        $clean = [];
        $sameOriginFrames = 0;

        foreach (array_slice($values, 0, EventBuilder::MAX_EXCEPTIONS) as $value) {
            if (! is_array($value)) {
                continue;
            }

            $item = [
                'type' => in_array($value['type'] ?? null, self::ERROR_TYPES, true) ? $value['type'] : 'Error',
                'value' => EventBuilder::message(is_string($value['value'] ?? null) ? $value['value'] : null),
            ];
            $frames = [];

            foreach (array_slice(is_array($value['stacktrace']['frames'] ?? null) ? $value['stacktrace']['frames'] : [], -50) as $frame) {
                $location = self::frame($frame, $origin);

                if ($location !== null) {
                    $frames[] = $location;
                }
            }

            if ($frames !== []) {
                $item['stacktrace'] = ['frames' => $frames];
                $sameOriginFrames += count($frames);
            }

            $mechanism = $value['mechanism'] ?? null;

            if (is_array($mechanism) && in_array($mechanism['type'] ?? null, self::MECHANISMS, true)) {
                $item['mechanism'] = ['type' => $mechanism['type'], 'handled' => ($mechanism['handled'] ?? false) === true];
            }

            $clean[] = $item;
        }

        if ($clean === [] || $sameOriginFrames === 0) {
            return null;
        }

        $prepared = [
            'event_id' => is_string($event['event_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/iD', $event['event_id']) === 1 ? strtolower($event['event_id']) : EventBuilder::eventId(),
            'platform' => 'javascript',
            'level' => 'error',
            'environment' => $config->environment,
            'sdk' => ['name' => EventBuilder::SDK_NAME . '.browser', 'version' => \FullStackStudio\Content\ContentClient::VERSION],
            'exception' => ['values' => $clean],
        ];

        $timestamp = $event['timestamp'] ?? null;
        $now = microtime(true);
        $prepared['timestamp'] = (is_int($timestamp) || is_float($timestamp)) && $timestamp > $now - 86_400 && $timestamp < $now + 300 ? $timestamp : round($now, 3);

        if ($config->release !== null) {
            $prepared['release'] = $config->release;
        }

        $route = is_string($event['transaction'] ?? null) && preg_match('~^[A-Z]{3,7} /[A-Za-z0-9_\-/{}.?:*]*$~D', $event['transaction']) === 1 ? $event['transaction'] : null;

        if ($route !== null) {
            $prepared['transaction'] = mb_substr($route, 0, 200);
        }

        return $prepared;
    }

    /** @return array<string, mixed>|null */
    private static function frame(mixed $frame, string $origin): ?array
    {
        if (! is_array($frame) || ! is_string($frame['filename'] ?? null)) {
            return null;
        }

        $parts = parse_url($frame['filename']);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $frameOrigin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '';

        // Only own scripts; no query string or fragment (cache busters, tokens).
        if ($frameOrigin !== $origin || isset($parts['user']) || preg_match('~^/[A-Za-z0-9_./-]+\.m?js$~D', $path) !== 1) {
            return null;
        }

        $clean = ['filename' => $origin . $path, 'in_app' => true];

        if (is_string($frame['function'] ?? null) && preg_match('/^[A-Za-z0-9_$.<>]{1,120}$/D', $frame['function']) === 1) {
            $clean['function'] = $frame['function'];
        }

        foreach (['lineno', 'colno'] as $key) {
            if (is_int($frame[$key] ?? null) && $frame[$key] > 0 && $frame[$key] <= 10_000_000) {
                $clean[$key] = $frame[$key];
            }
        }

        return $clean;
    }
}

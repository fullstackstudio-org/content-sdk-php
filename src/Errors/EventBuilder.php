<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Errors;

use Throwable;

/**
 * Builds a GlitchTip (Sentry protocol) error event from a PHP exception, from an ALLOWLIST only (the same boundary as
 * `FullStackStudio\Core\Telemetry\ErrorFilter` in fss-core v0.63.0):
 *
 * Sent: event id, time, level, platform, release, environment, sdk, the route TEMPLATE (`/klanten/{customer}`, never the path), the exception
 * classes (chained, at most 5) with their message after `Scrubber::text` (an empty result becomes `[message removed]`) and the source
 * locations (path relative to the app + line + function; vendor frames marked not in-app).
 * Never sent: request (URL, query, body, headers, cookies), user, IP, server name, breadcrumbs, contexts, extra, tags, stack variables,
 * source code. Frames outside the app (absolute paths, compiled views, eval) become `[external]`.
 */
final class EventBuilder
{
    public const MAX_EXCEPTIONS = 5;

    public const MAX_FRAMES = 60;

    public const SDK_NAME = 'fullstackstudio.content';

    private const PATH = '~^(app|bootstrap|config|routes|resources|database|src|vendor|lib|modules|packages|tests)/[A-Za-z0-9_./-]+\.php$~D';

    /** @return array<string, mixed> */
    public static function fromThrowable(Throwable $error, ErrorsConfig $config, string $basePath, ?string $route = null, ?string $eventId = null, ?float $now = null): array
    {
        $chain = [];

        for ($current = $error; $current !== null && count($chain) < self::MAX_EXCEPTIONS; $current = $current->getPrevious()) {
            $chain[] = $current;
        }

        $values = [];

        // Sentry wants the innermost cause first and the exception that reached the handler last.
        foreach (array_reverse($chain) as $exception) {
            $value = [
                'type' => self::type($exception::class),
                'value' => self::message($exception->getMessage()),
                'mechanism' => ['type' => 'generic', 'handled' => true],
            ];
            $frames = self::frames($exception, $basePath);

            if ($frames !== []) {
                $value['stacktrace'] = ['frames' => $frames];
            }

            $values[] = $value;
        }

        $event = [
            'event_id' => $eventId !== null && preg_match('/^[a-f0-9]{32}$/D', $eventId) === 1 ? $eventId : self::eventId(),
            'timestamp' => round($now ?? microtime(true), 3),
            'platform' => 'php',
            'level' => 'error',
            'environment' => $config->environment,
            'sdk' => ['name' => self::SDK_NAME, 'version' => \FullStackStudio\Content\ContentClient::VERSION],
            'exception' => ['values' => $values],
        ];

        if ($config->release !== null) {
            $event['release'] = $config->release;
        }

        $transaction = self::route($route);

        if ($transaction !== null) {
            $event['transaction'] = $transaction;
        }

        return $event;
    }

    /** The exception message after scrubbing; never blank (a blank issue title is useless). */
    public static function message(?string $value): string
    {
        $text = Scrubber::text($value);

        return $text === '' ? '[message removed]' : $text;
    }

    /** Only a route template (`/klanten/{customer}`, optionally with a method) or a class name can be the transaction. */
    public static function route(?string $route): ?string
    {
        if ($route === null || $route === '') {
            return null;
        }

        if (preg_match('~^(?:[A-Z]{3,7} )?/[A-Za-z0-9_\-/{}.?:*]*$~D', $route) === 1 || preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $route) === 1) {
            return mb_substr($route, 0, 200);
        }

        return null;
    }

    public static function eventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function type(string $class): string
    {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/D', $class) === 1 ? mb_substr($class, 0, 200) : 'Exception';
    }

    /**
     * Frames oldest first. Location 0 is where the exception was thrown and runs inside the function of trace entry 0; the call site of
     * trace entry i runs inside the function of entry i + 1 (the last one inside the main script).
     *
     * @return list<array<string, mixed>>
     */
    private static function frames(Throwable $error, string $basePath): array
    {
        $trace = $error->getTrace();
        $locations = [['file' => $error->getFile(), 'line' => $error->getLine()]];

        foreach ($trace as $entry) {
            $locations[] = ['file' => is_string($entry['file'] ?? null) ? $entry['file'] : '', 'line' => is_int($entry['line'] ?? null) ? $entry['line'] : 0];
        }

        $frames = [];

        foreach ($locations as $index => $location) {
            $entry = $trace[$index] ?? null;
            $function = is_array($entry) ? self::function($entry) : null;
            $path = self::path($location['file'], $basePath);
            $frame = ['filename' => $path, 'in_app' => $path !== '[external]' && ! str_starts_with($path, 'vendor/')];

            if ($function !== null) {
                $frame['function'] = $function;
            }

            if ($location['line'] > 0) {
                $frame['lineno'] = $location['line'];
            }

            $frames[] = $frame;
        }

        return array_reverse(array_slice($frames, 0, self::MAX_FRAMES));
    }

    /** @param array<string, mixed> $entry */
    private static function function(array $entry): ?string
    {
        $function = is_string($entry['function'] ?? null) ? $entry['function'] : null;

        if ($function === null) {
            return null;
        }

        $name = is_string($entry['class'] ?? null) ? $entry['class'] . (is_string($entry['type'] ?? null) ? $entry['type'] : '::') . $function : $function;

        return preg_match('/^[A-Za-z0-9_\\\\:{}<>$ -]{1,200}$/D', $name) === 1 ? $name : null;
    }

    private static function path(string $file, string $basePath): string
    {
        $base = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
        $path = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
        $path = str_replace('\\', '/', $path);

        return preg_match(self::PATH, $path) === 1 ? $path : '[external]';
    }
}

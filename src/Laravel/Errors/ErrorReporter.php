<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Errors;

use FullStackStudio\Content\Errors\EventBuilder;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Backend reporting: registered as a `reportable` callback on Laravel's exception handler, so it sees exactly what Laravel reports (the
 * handler's `dontReport` list and `report()` overrides apply) and never stops the normal logging.
 *
 * Sending happens after the response (`terminating`), or right away in the console. At most `content.errors.max_per_minute` events per
 * minute per site and the same exception (class + location) once a minute, so an error loop cannot flood GlitchTip. Never throws.
 * Skipped when the site has its own configured `sentry/sentry-laravel` (`sentry.dsn`), so nothing is reported twice.
 */
final class ErrorReporter
{
    /** @var array<string, int> fingerprint => time first seen (a long-running worker forgets them after a minute) */
    private array $seen = [];

    private bool $busy = false;

    public function __construct(private readonly Application $app, private readonly Errors $errors)
    {
    }

    public function report(Throwable $error): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;

        try {
            $config = $this->app['config'];

            if (! $config->get('content.errors.backend', true) || ! $this->errors->active() || (string) $config->get('sentry.dsn', '') !== '') {
                return;
            }

            $fingerprint = $error::class . '|' . $error->getFile() . '|' . $error->getLine();

            $now = time();
            $this->seen = array_filter($this->seen, fn (int $at): bool => $now - $at < 60);

            if (isset($this->seen[$fingerprint]) || count($this->seen) >= 20) {
                return;
            }

            $this->seen[$fingerprint] = $now;

            if (! $this->allow()) {
                return;
            }

            // Everything that may wait on the network (the config from Content, GlitchTip) runs after the response.
            $route = $this->errors->route();
            $send = function () use ($error, $route): void {
                try {
                    $settings = $this->errors->config();

                    if ($settings === null || ($settings->backendDsn === null && $settings->browserDsn === null)) {
                        return;
                    }

                    $event = EventBuilder::fromThrowable($error, $settings, $this->app->basePath(), $route);
                    $this->errors->delivery()->deliver($event, $settings, $this->errors->origin());
                } catch (Throwable) {
                    // Monitoring never breaks the app.
                }
            };

            if ($this->app->runningInConsole() || ! method_exists($this->app, 'terminating')) {
                $send();
            } else {
                $this->app->terminating($send);
            }
        } catch (Throwable) {
            // Monitoring never breaks the app.
        } finally {
            $this->busy = false;
        }
    }

    private function allow(): bool
    {
        try {
            $limiter = $this->app->make(\Illuminate\Cache\RateLimiter::class);
            $key = 'fsc-errors:backend';

            if ($limiter->tooManyAttempts($key, max(1, (int) $this->app['config']->get('content.errors.max_per_minute', 60)))) {
                return false;
            }

            $limiter->hit($key, 60);

            return true;
        } catch (Throwable) {
            return true;
        }
    }
}

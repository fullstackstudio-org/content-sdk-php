<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Errors;

use FullStackStudio\Content\Cache\LaravelStore;
use FullStackStudio\Content\Cache\Store;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Errors\Delivery;
use FullStackStudio\Content\Errors\EnvelopeSender;
use FullStackStudio\Content\Errors\ErrorsConfig;
use FullStackStudio\Content\Errors\ErrorsConfigResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Throwable;

/**
 * The Laravel side of error monitoring (one instance per app, `app(Errors::class)`): the resolved config, the delivery and the small
 * checks every entry point shares (quiet environments, the site origin, the route template).
 */
final class Errors
{
    private ?ErrorsConfigResolver $resolver = null;

    private ?Delivery $delivery = null;

    public function __construct(private readonly Application $app)
    {
    }

    /** Monitoring is off in `local` and `testing` (a dev machine or a test run never sends), unless `content.errors.allow_local`. */
    public function active(): bool
    {
        $config = $this->app['config'];

        if (! $config->get('content.errors.enabled', true)) {
            return false;
        }

        return ! in_array((string) $config->get('app.env', 'production'), ['local', 'testing'], true) || $config->get('content.errors.allow_local') === true;
    }

    public function config(): ?ErrorsConfig
    {
        $config = $this->active() ? $this->resolver()->resolve() : null;
        $environment = $this->app['config']->get('content.errors.environment');

        // A local environment override wins over the one from Content (e.g. `staging` on a test deployment).
        if ($config !== null && is_string($environment) && $environment !== '') {
            return new ErrorsConfig($config->backendDsn, $config->browserDsn, ErrorsConfig::environment($environment), $config->release);
        }

        return $config;
    }

    public function delivery(): Delivery
    {
        return $this->delivery ??= new Delivery(
            $this->app->bound(EnvelopeSender::class) ? $this->app->make(EnvelopeSender::class) : new EnvelopeSender(),
            $this->store(),
            (string) $this->app['config']->get('content.errors.tunnel.target', 'backend'),
        );
    }

    /** `https://www.example.nl` from `app.url`, or null. */
    public function origin(): ?string
    {
        $url = (string) $this->app['config']->get('app.url', '');
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** The route template of the current request (`/klanten/{customer}`), never the path; `GET /…` with `$withMethod`. */
    public function route(bool $withMethod = false): ?string
    {
        try {
            if (! $this->app->bound('request')) {
                return null;
            }

            $request = $this->app['request'];
            $route = $request->route();

            if (! $route instanceof Route) {
                return null;
            }

            $template = '/' . ltrim($route->uri(), '/');

            return $withMethod ? strtoupper($request->method()) . ' ' . $template : $template;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Text to remove from exception messages of the current request: on a visitor page of the Hub (plan sdk-out-of-the-box, section 8) the
     * path holds capability tokens, so the path becomes its scrubbed form (`/a/l/…`) and each token `…`. Empty for every other request.
     *
     * @return array<string, string>
     */
    public function redactions(): array
    {
        try {
            if (! $this->app->bound('request')) {
                return [];
            }

            $request = $this->app['request'];
            $path = $request->getPathInfo();

            if (! \FullStackStudio\Content\Laravel\Visitor\VisitorRoutes::matches($path)) {
                return [];
            }

            $scrubbed = \FullStackStudio\Content\Laravel\Visitor\VisitorRoutes::scrub($path);
            $redact = [];

            foreach ([$path, rawurldecode($path)] as $raw) {
                $redact[$raw] = $scrubbed;
                $redact[ltrim($raw, '/')] = ltrim($scrubbed, '/');
            }

            foreach (\FullStackStudio\Content\Laravel\Visitor\VisitorRoutes::parameters($path) as $value) {
                // Short values are slugs, not secrets; replacing them would garble the message.
                if (strlen($value) >= 8) {
                    $redact[$value] = '…';
                    $redact[rawurlencode($value)] = '…';
                }
            }

            return array_filter($redact, static fn (string $to, string $from): bool => $from !== '' && $from !== '/', ARRAY_FILTER_USE_BOTH);
        } catch (Throwable) {
            return [];
        }
    }

    public function store(): ?Store
    {
        try {
            $name = $this->app['config']->get('content.cache.store');

            return new LaravelStore($this->app['cache']->store($name ?: null));
        } catch (Throwable) {
            return null;
        }
    }

    private function resolver(): ErrorsConfigResolver
    {
        if ($this->resolver !== null) {
            return $this->resolver;
        }

        $config = $this->app['config'];
        $release = ErrorsConfig::release($config->get('content.errors.release'));
        $backend = ErrorsConfig::dsn($config->get('content.errors.dsn'));
        $browser = ErrorsConfig::dsn($config->get('content.errors.browser_dsn'));
        $override = $backend !== null || $browser !== null
            ? new ErrorsConfig($backend, $browser, ErrorsConfig::environment($config->get('content.errors.environment') ?? 'production'), $release)
            : null;
        $app = $this->app;

        return $this->resolver = new ErrorsConfigResolver(
            static function () use ($app): array {
                $reply = $app->make(ContentClient::class)->context()->execute([
                    'path' => '/project/errors',
                    'options' => ['timeoutMs' => 2000, 'cache' => false],
                ]);
                $data = is_array($reply->data) ? ($reply->data['errors'] ?? null) : null;

                return is_array($data) ? $data : [];
            },
            $this->store(),
            $override,
            $release,
            max(60, (int) $config->get('content.errors.config_ttl', 600)),
        );
    }
}

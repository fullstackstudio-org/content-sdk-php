<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Laravel\Auto\Auto;
use FullStackStudio\Content\Laravel\Auto\AutoPlatform;
use FullStackStudio\Content\Laravel\Auto\AutoRoutes;
use FullStackStudio\Content\Laravel\Preview\PreviewRequest;
use FullStackStudio\Content\Laravel\Site\Layout;
use FullStackStudio\Content\Laravel\Visitor\VisitorRoutes;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The checks behind `php artisan fsc:doctor`: the items every site on this package must have (AGENTS.md, "Altijd verplicht"), checked
 * against the running app (config, routes, middleware) and its files (layouts, public/, .gitignore). Read-only: it never writes a file, never
 * calls Content unless `online` is on, and the only request it dispatches is `GET /api/health` through the app's own HTTP kernel.
 *
 * Each result is `['status' => 'ok'|'warn'|'fail'|'skip'|'info', 'label' => Dutch text, 'hint' => Dutch text or null]`.
 */
final class Doctor
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIP = 'skip';
    public const INFO = 'info';

    /** @var list<array{status: string, label: string, hint: ?string}> */
    private array $results = [];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @return list<array{status: string, label: string, hint: ?string}>
     */
    public function run(bool $checkEnv = true, bool $online = false): array
    {
        $this->results = [];

        $this->section(fn () => $this->checkEnvironment($checkEnv));
        $this->section(fn () => $this->checkRuntimeSettings());
        $this->section(fn () => $this->checkAutomatic());
        $this->section(fn () => $this->checkRoutes());
        $this->section(fn () => $this->checkMiddleware());
        $this->section(fn () => $this->checkLayouts());
        $this->section(fn () => $this->checkResponseHeaders());
        $this->section(fn () => $this->checkPreview());
        $this->section(fn () => $this->checkPageBuilderBlocks());
        $this->section(fn () => $this->checkPageBlocksWidth());
        $this->section(fn () => $this->checkPublicFiles());
        $this->section(fn () => $this->checkSecrets());
        $this->section(fn () => $this->checkConfigCacheable());

        if ($online) {
            $this->section(fn () => $this->checkOnline());
        }

        return $this->results;
    }

    /** One failing check must not hide the others. */
    private function section(Closure $check): void
    {
        try {
            $check();
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Controle kon niet draaien: ' . $e::class, $e->getMessage());
        }
    }

    private function add(string $status, string $label, ?string $hint = null): void
    {
        $this->results[] = ['status' => $status, 'label' => $label, 'hint' => $hint];
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app['config']->get($key, $default);
    }

    private function production(): bool
    {
        return $this->app->environment('production');
    }

    // --- Environment ---------------------------------------------------------------------------------------------------------

    private function checkEnvironment(bool $checkEnv): void
    {
        if (! $checkEnv) {
            $this->add(self::SKIP, 'Omgevingsvariabelen (FSC_SERVER_TOKEN, FSC_WEBHOOK_SECRET, APP_KEY) overgeslagen (--skip-env)');

            return;
        }

        $token = (string) $this->config('content.token');
        if ($token === '') {
            $this->add(self::FAIL, 'FSC_SERVER_TOKEN ontbreekt', 'Zet de servertoken (fsc_srv_...) als runtime-env, nooit in de repo of het image.');
        } elseif (! str_starts_with($token, 'fsc_srv_')) {
            $this->add(self::FAIL, 'FSC_SERVER_TOKEN is geen servertoken', 'Deze SDK draait op de server: gebruik een fsc_srv_-token, geen fsc_pub_.');
        } else {
            $this->add(self::OK, 'FSC_SERVER_TOKEN is gezet (servertoken)');
        }

        if ($this->config('content.webhook.enabled')) {
            $secret = (string) $this->config('content.webhook.secret');
            if ($secret === '') {
                $this->add(self::FAIL, 'FSC_WEBHOOK_SECRET ontbreekt', 'Maak in de Hub een webhook naar https://<site>/content/webhook en zet het geheim als runtime-env.');
            } elseif (! str_starts_with($secret, 'fsc_whsec_')) {
                $this->add(self::WARN, 'FSC_WEBHOOK_SECRET begint niet met fsc_whsec_', 'Controleer of dit het geheim van de webhook uit de Hub is.');
            } else {
                $this->add(self::OK, 'FSC_WEBHOOK_SECRET is gezet');
            }
        }

        if ((string) $this->config('app.key') === '') {
            $this->add(self::FAIL, 'APP_KEY ontbreekt', 'Genereer per site een sleutel (php artisan key:generate --show) en zet hem als runtime-env.');
        } else {
            $this->add(self::OK, 'APP_KEY is gezet');
        }
    }

    // --- Runtime settings ----------------------------------------------------------------------------------------------------

    private function checkRuntimeSettings(): void
    {
        $baseUrl = (string) $this->config('content.base_url');
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if (str_starts_with($baseUrl, 'https://') || in_array($host, ['localhost', '127.0.0.1'], true)) {
            $this->add(self::OK, "FSC_API_URL is {$baseUrl}");
        } else {
            $this->add(self::FAIL, "FSC_API_URL is geen https-adres ({$baseUrl})", 'Laat hem op https://hub.fullstackstudio.nl staan; de SDK bouwt er ook de media-URL\'s mee.');
        }

        $driver = (string) $this->config('session.driver');
        if ($driver === 'cookie') {
            $this->add(self::FAIL, 'SESSION_DRIVER is cookie', 'Gebruik file: een cookiesessie groeit voorbij de nginx-buffer en geeft een 502 "upstream sent too big header" bij formulierfouten.');
        } else {
            $this->add(self::OK, "SESSION_DRIVER is {$driver}");
        }

        $appUrl = (string) $this->config('app.url');
        if (str_starts_with($appUrl, 'https://')) {
            $this->add(self::OK, "APP_URL is https ({$appUrl})");
        } elseif ($this->production()) {
            $this->add(self::FAIL, "APP_URL is geen https in productie ({$appUrl})", 'Zet APP_URL op het https-adres van de site.');
        } else {
            $this->add(self::WARN, "APP_URL is geen https ({$appUrl})", 'Prima lokaal; in productie moet dit het https-adres zijn.');
        }

        $forced = $this->forcedScheme();
        if ($forced === 'https://') {
            $this->add(self::OK, 'URL::forceScheme(\'https\') staat aan');
        } elseif ($this->production()) {
            $this->add(self::FAIL, 'https wordt niet afgedwongen', 'Zet URL::forceScheme(\'https\') in AppServiceProvider::boot() voor productie: achter Cloudflare komt het verzoek als http binnen en redirects na een formulier worden http://, wat de CSP form-action blokkeert.');
        } elseif (Auto::on('platform') && AutoPlatform::state()['https'] === AutoPlatform::SKIPPED) {
            $this->add(self::INFO, 'https wordt in productie automatisch afgedwongen (SDK); in deze omgeving niet');
        } else {
            $this->add(self::WARN, 'https wordt (in deze omgeving) niet afgedwongen', 'Controleer dat AppServiceProvider::boot() in productie URL::forceScheme(\'https\') aanroept.');
        }
    }

    private function forcedScheme(): ?string
    {
        try {
            $url = $this->app['url'];
            $property = new ReflectionProperty($url, 'forceScheme');

            return $property->getValue($url);
        } catch (Throwable) {
            return null;
        }
    }

    // --- Automatic (out of the box) -------------------------------------------------------------------------------------------

    /** Feature switch => [label, path, env name] of the three utility routes. */
    private const UTILITY_ROUTES = [
        'sitemap' => ['Sitemap', '/sitemap.xml', 'FSC_AUTO_SITEMAP'],
        'robots' => ['Robots', '/robots.txt', 'FSC_AUTO_ROBOTS'],
        'health' => ['Healthcheck', '/api/health', 'FSC_AUTO_HEALTH'],
    ];

    private ?string $probe = null;

    /** A path no site serves on purpose: only a catch-all (the site's or the SDK's) matches it. Stable within one run. */
    private function probePath(): string
    {
        return $this->probe ??= '/fsc-doctor-' . bin2hex(random_bytes(4)) . '/x';
    }

    /**
     * Whether the SDK's own pages (`content::site.*` views) answer Hub paths: the feature is on and no route of the site catches them first.
     * Only then the layout of those views matters.
     */
    private function autoPagesInUse(): bool
    {
        return Auto::on('pages') && AutoRoutes::owner('GET', $this->probePath())['by'] === 'sdk';
    }

    /** `eigen route wint: <uri> → <action>` for the route of the site that answers a path the SDK would otherwise serve. */
    private function winner(array $owner): string
    {
        return 'eigen route wint: ' . ltrim((string) $owner['uri'], '/') . ' → ' . $owner['action'];
    }

    /**
     * One line per out of the box feature: `OK … automatisch (SDK)`, `INFO … eigen route wint: <uri> → <action>` or `INFO … uit (FSC_AUTO_…)`.
     * The checks of the sections below accept what a feature covers, so a site in automatic mode does not fail on a route or middleware it
     * does not need; a hand-built site keeps its checks and only gets the extra lines.
     */
    private function checkAutomatic(): void
    {
        if (! Auto::on('enabled')) {
            $this->add(self::INFO, 'De automatische laag van de SDK staat uit (FSC_AUTO=false): routes, pagina\'s, foutpagina\'s en cookiebanner komen van de site zelf');

            return;
        }

        // In the console nothing has resolved the HTTP kernel yet, so the platform items are still "pending" until it is built.
        try {
            $this->app->make(HttpKernel::class);
        } catch (Throwable) {
        }

        foreach (self::UTILITY_ROUTES as $feature => [$label, $path, $env]) {
            $owner = AutoRoutes::owner('GET', $path);

            if (! Auto::on($feature)) {
                $this->add(self::INFO, "{$label} staat uit ({$env}=false)" . ($owner['by'] === 'site' ? ', de site heeft een eigen route' : ''));
            } elseif ($owner['by'] === 'sdk') {
                $this->add(self::OK, "{$label} automatisch (SDK): GET {$path}");

                if ($feature === 'sitemap') {
                    $own = Sitemap::ownPaths();
                    $this->add(self::INFO, $own === []
                        ? 'Geen eigen pagina\'s van de site in de sitemap (alleen de pagina\'s uit de Hub)'
                        : 'Eigen pagina\'s van de site in de sitemap: ' . implode(', ', array_slice($own, 0, 15)) . (count($own) > 15 ? ', ...' : '') . ' (uitsluiten: ->noSitemap() of content.auto.sitemap.exclude)');
                }
            } elseif ($owner['by'] === 'site') {
                $this->add(self::INFO, "{$label}: " . $this->winner($owner));
            }
        }

        $this->automaticPages();
        $this->automaticPlatform();

        $state = AutoPlatform::state();

        if (! Auto::on('redirects')) {
            $this->add(self::INFO, 'Redirects uit de Hub staan uit (FSC_AUTO_REDIRECTS=false)');
        } elseif ($state['redirects'] === AutoPlatform::APPLIED) {
            $this->add(self::OK, 'Redirects uit de Hub automatisch (SDK): RedirectMiddleware vooraan in de web-groep');
        } elseif ($state['redirects'] === AutoPlatform::BY_APP) {
            $this->add(self::INFO, 'Redirects: de app heeft RedirectMiddleware zelf in de web-groep gezet');
        }

        $this->add(...Auto::on('errors')
            ? [self::OK, 'Foutpagina\'s (404 en 503) automatisch (SDK)']
            : [self::INFO, 'Foutpagina\'s van de SDK staan uit (FSC_AUTO_ERRORS=false)']);

        if (! Auto::on('consent')) {
            $this->add(self::INFO, 'De banner van de SDK staat uit (FSC_AUTO_CONSENT=false)');
        } elseif ($this->packageBanner()) {
            // With an own banner too the layout section warns about two banners instead.
            if (! $this->ownBanner()) {
                $this->add(self::OK, 'Cookiebanner automatisch (SDK, via <x-content::scripts />)');
            }
        }

        if (! Auto::on('visitor_pages')) {
            $this->add(self::INFO, 'Bezoekerspagina\'s van de Hub via het domein van de site staan uit (FSC_AUTO_VISITOR_PAGES=false)');
        } elseif ($this->app['router']->getRoutes()->hasNamedRoute(VisitorRoutes::NAME_PREFIX . 'appointments.1')) {
            $this->add(self::OK, 'Bezoekerspagina\'s: routes actief (proxy ' . VisitorRoutes::VERSION . ', ' . count(VisitorRoutes::ROUTES) . ' routes)');
        } else {
            $this->add(self::WARN, 'Bezoekerspagina\'s: de routes zijn niet geregistreerd', 'Zet FSC_AUTO_VISITOR_PAGES niet op false en draai php artisan route:clear als de routes gecachet zijn met een oudere SDK.');
        }

        $slash = AutoPlatform::trailingSlash();

        if (! Auto::on('trailing_slash')) {
            $this->add(self::INFO, 'Slash aan het eind van URL\'s staat uit (FSC_AUTO_TRAILING_SLASH, standaard uit)');
        } elseif ($slash === AutoPlatform::BY_APP) {
            $this->add(self::INFO, 'Slash aan het eind van URL\'s: de app heeft de middleware zelf geregistreerd');
        } else {
            $this->add(self::OK, 'Slash aan het eind van URL\'s automatisch (SDK, FSC_AUTO_TRAILING_SLASH)');
        }
    }

    /** Pages, articles and legal texts: the content route (fallback) of the SDK, unless the site has a catch-all of its own. */
    private function automaticPages(): void
    {
        $owner = AutoRoutes::owner('GET', $this->probePath());

        if (! Auto::on('pages')) {
            $this->add(self::INFO, 'Pagina\'s uit de Hub staan uit (FSC_AUTO_PAGES=false)');
        } elseif ($owner['by'] === 'site') {
            $this->add(self::INFO, 'Pagina\'s: ' . $this->winner($owner));
        } elseif ($owner['by'] === 'sdk') {
            $this->add(self::OK, 'Pagina\'s uit de Hub automatisch (SDK): elk pad zonder eigen route');
        }

        // Articles and legal texts are served by the same content route, so they only count where it is the SDK's.
        if ($owner['by'] !== 'sdk') {
            return;
        }

        $this->add(...Auto::on('articles')
            ? [self::OK, 'Artikelen en categorieën automatisch (SDK)']
            : [self::INFO, 'Artikelen en categorieën staan uit (FSC_AUTO_ARTICLES=false)']);

        $this->add(...Auto::on('legal')
            ? [self::OK, 'Juridische teksten automatisch (SDK): /' . implode(', /', array_keys(Auto::legalPaths()))]
            : [self::INFO, 'Juridische teksten staan uit (FSC_AUTO_LEGAL=false)']);
    }

    /** trustProxies and https: what the SDK applied and what the app had set itself. */
    private function automaticPlatform(): void
    {
        if (! Auto::on('platform')) {
            $this->add(self::INFO, 'Platform (trustProxies, https) staat uit (FSC_AUTO_PLATFORM=false)');

            return;
        }

        $state = AutoPlatform::state();
        $parts = [];
        $byApp = false;

        $proxies = [
            AutoPlatform::APPLIED => 'proxy\'s vertrouwd (SDK)',
            AutoPlatform::BY_APP => 'proxy\'s door de app ingesteld',
        ][$state['proxies']] ?? null;
        $https = [
            AutoPlatform::APPLIED => 'https afgedwongen (SDK)',
            AutoPlatform::BY_APP => 'https door de app afgedwongen',
            AutoPlatform::SKIPPED => 'https wordt in productie afgedwongen (SDK)',
        ][$state['https']] ?? null;

        foreach ([[$state['proxies'], $proxies], [$state['https'], $https]] as [$value, $text]) {
            if ($text !== null) {
                $parts[] = $text;
                $byApp = $byApp || $value === AutoPlatform::BY_APP;
            }
        }

        if ($parts === []) {
            $this->add(self::INFO, 'Platform (trustProxies, https): nog niet toegepast');

            return;
        }

        $this->add($byApp ? self::INFO : self::OK, ($byApp ? 'Platform: ' : 'Platform automatisch (SDK): ') . implode(', ', $parts));
    }

    // --- Routes --------------------------------------------------------------------------------------------------------------

    private function checkRoutes(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        foreach (['sitemap.xml' => ['Sitemap::response()', 'sitemap'], 'robots.txt' => ['Robots::response()', 'robots']] as $uri => [$helper, $feature]) {
            // The SDK's own route is reported in the "automatisch" lines above.
            if (Auto::on($feature) && AutoRoutes::owner('GET', '/' . $uri)['by'] === 'sdk') {
                continue;
            }

            $route = $this->match('GET', '/' . $uri);
            if ($route !== null && $route->uri() === $uri) {
                $this->add(self::OK, "GET /{$uri} heeft een eigen route");
            } else {
                $this->add(self::FAIL, "GET /{$uri} ontbreekt", "Route::get('/{$uri}', fn () => {$helper}); vóór de catch-all, of laat de SDK hem maken (FSC_AUTO=true, FSC_AUTO_" . strtoupper($feature) . '=true).');
            }
        }

        $this->checkHealth();

        if ($routes->getByName('content.webhook') !== null) {
            $this->add(self::OK, 'Webhookroute POST /' . trim((string) $this->config('content.webhook.path', 'content/webhook'), '/') . ' is geregistreerd');
        } else {
            $this->add(self::FAIL, 'Webhookroute ontbreekt', 'Zet FSC_WEBHOOK_ENABLED=true (of roep Content::routes() aan), anders zie je wijzigingen pas na de cache-TTL.');
        }

        $usesForms = $this->viewsContain('<x-content::form') || $this->viewsContain('<x-content::page-blocks');
        if ($routes->getByName('content.form') !== null) {
            $this->add(self::OK, 'Formulierroute POST /' . trim((string) $this->config('content.forms.path', 'content/forms'), '/') . '/{key} is geregistreerd');
        } elseif ($usesForms) {
            $this->add(self::FAIL, 'Een formulier wordt getoond (<x-content::form> of een form-blok) maar de formulierroute staat uit: het formulier geeft bij versturen een 404', 'Haal FSC_FORMS_ENABLED=false weg (de route staat standaard aan) of roep Content::formRoutes() aan.');
        } else {
            $this->add(self::WARN, 'Formulierroute staat uit', 'Haal FSC_FORMS_ENABLED=false weg zodra de site een formulier krijgt (<x-content::form> of een form-blok).');
        }

        $owner = AutoRoutes::owner('GET', $this->probePath());
        if ($owner['by'] === 'sdk' && Auto::on('pages')) {
            // The SDK's content route answers Hub paths: reported in the "automatisch" lines above.
        } elseif ($owner['by'] === 'site' && str_contains((string) $owner['uri'], '{')) {
            $this->add(self::OK, 'Catch-all voor pagina\'s uit de Hub is aanwezig (' . $owner['uri'] . ')');
        } elseif (array_diff(Sitemap::ownPaths(), ['/']) !== []) {
            // A hand-built site with only explicit routes: as before, a warning.
            $this->add(self::WARN, 'Geen catch-all route voor pagina\'s uit de Hub', 'Route::get(\'/{path}\', [PageController::class, \'show\'])->where(\'path\', \'.*\'); als allerlaatste route, of laat de SDK de pagina\'s tonen (FSC_AUTO_PAGES=true).');
        } else {
            $this->add(self::FAIL, 'Geen route voor pagina\'s uit de Hub: niets toont de pagina\'s van het project', 'Laat de SDK ze tonen (FSC_AUTO=true, FSC_AUTO_PAGES=true) of bouw een catch-all: Route::get(\'/{path}\', [PageController::class, \'show\'])->where(\'path\', \'.*\'); als allerlaatste route.');
        }

        $this->checkWelcome();
    }

    /**
     * The route of `/` must not be the Laravel welcome page: a route of the site always beats the SDK's, so it would hide the home page of the
     * Hub. Read statically (`Route::view('/', 'welcome')`, a closure or a controller method that names the `welcome` view): no request.
     */
    private function checkWelcome(): void
    {
        if (AutoRoutes::owner('GET', '/')['by'] !== 'site') {
            return;
        }

        $route = $this->match('GET', '/');

        if ($route !== null && $this->rendersWelcome($route)) {
            $this->add(self::FAIL, 'GET / toont de standaard Laravel-welkomstpagina (view welcome)', 'Haal Route::get(\'/\', ...) met de welcome-view uit routes/web.php weg (en resources/views/welcome.blade.php): de SDK toont dan de startpagina uit de Hub. Een eigen route voor / wint altijd van de SDK.');
        }
    }

    private function rendersWelcome(Route $route): bool
    {
        if (($route->defaults['view'] ?? null) === 'welcome') {
            return true;
        }

        try {
            $uses = $route->getAction('uses');

            if ($uses instanceof Closure) {
                $reflection = new ReflectionFunction($uses);
            } elseif (is_string($uses)) {
                $reflection = str_contains($uses, '@') ? new ReflectionMethod(...explode('@', $uses, 2)) : new ReflectionMethod($uses, '__invoke');
            } elseif (is_array($uses) && count($uses) === 2) {
                $reflection = new ReflectionMethod($uses[0], $uses[1]);
            } else {
                return false;
            }

            $file = $reflection->getFileName();
            $lines = is_string($file) && is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;

            if ($lines === false) {
                return false;
            }

            $source = implode("\n", array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

            return preg_match('/[\'"]welcome[\'"]/', $source) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function match(string $method, string $path): ?Route
    {
        try {
            return $this->app['router']->getRoutes()->match(Request::create($path, $method));
        } catch (Throwable) {
            return null;
        }
    }

    private function checkHealth(): void
    {
        $route = $this->match('GET', '/api/health');
        if ($route === null || $route->uri() !== 'api/health') {
            $this->add(self::FAIL, 'GET /api/health ontbreekt', 'Route::get(\'/health\', fn () => response()->json([\'ok\' => true])); in routes/api.php, zonder sessie en zonder call naar de Hub (HEALTHCHECK van het image), of laat de SDK hem maken (FSC_AUTO=true, FSC_AUTO_HEALTH=true).');

            return;
        }

        try {
            $response = $this->app->make(HttpKernel::class)->handle(Request::create('/api/health', 'GET'));
            $body = json_decode((string) $response->getContent(), true);
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'GET /api/health geeft een fout (' . $e::class . ')');

            return;
        }

        if ($response->getStatusCode() === 200 && is_array($body) && ($body['ok'] ?? null) === true) {
            $this->add(self::OK, 'GET /api/health geeft 200 {"ok":true}');
        } else {
            $this->add(self::FAIL, 'GET /api/health geeft ' . $response->getStatusCode() . ' zonder {"ok":true}', 'De healthcheck verwacht 200 met {"ok":true}.');
        }
    }

    // --- Middleware ----------------------------------------------------------------------------------------------------------

    private function checkMiddleware(): void
    {
        $groups = [];
        try {
            $kernel = $this->app->make(HttpKernel::class);
            $groups = method_exists($kernel, 'getMiddlewareGroups') ? $kernel->getMiddlewareGroups() : [];
        } catch (Throwable) {
        }
        $groups = array_merge($groups, $this->app['router']->getMiddlewareGroups());
        $web = array_values($groups['web'] ?? []);

        $position = array_search(RedirectMiddleware::class, $web, true);
        if ($position === false) {
            $this->add(self::FAIL, 'RedirectMiddleware staat niet in de web-groep', '$middleware->web(prepend: [\\FullStackStudio\\Content\\Laravel\\RedirectMiddleware::class]); in bootstrap/app.php.');
        } elseif ($position !== 0) {
            $this->add(self::WARN, 'RedirectMiddleware staat niet vooraan in de web-groep', 'Gebruik prepend, zodat een redirect vóór sessie en CSRF beslist.');
        } else {
            $this->add(self::OK, 'RedirectMiddleware staat vooraan in de web-groep');
        }

        $proxies = null;
        try {
            $proxies = (new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies'))->getValue();
        } catch (Throwable) {
        }
        if ($proxies === '*' || $proxies === '**' || (is_array($proxies) && $proxies !== [])) {
            $this->add(self::OK, 'Proxy\'s worden vertrouwd (trustProxies)');
        } else {
            $this->add(self::WARN, 'trustProxies is niet gezet', '$middleware->trustProxies(at: \'*\'); in bootstrap/app.php: achter Cloudflare en de ingress, samen met URL::forceScheme(\'https\').');
        }
    }

    // --- Views ---------------------------------------------------------------------------------------------------------------

    /** @return list<string> absolute paths of the site's own view directories */
    private function viewPaths(): array
    {
        $paths = array_values(array_filter((array) $this->config('view.paths', []), static fn ($path): bool => is_string($path) && is_dir($path)));

        return array_values(array_filter($paths, static fn (string $path): bool => ! str_contains(str_replace('\\', '/', $path), '/vendor/fullstackstudio/')));
    }

    /** @return iterable<string, string> path => contents of every Blade view */
    private function views(): iterable
    {
        $paths = $this->viewPaths();
        if ($paths === []) {
            return [];
        }

        $finder = Finder::create()->files()->in($paths)->name('*.blade.php')->notPath('vendor');
        $views = [];
        foreach ($finder as $file) {
            $views[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
        }

        return $views;
    }

    /**
     * The 404 view has to tell the native tracker it is a 404 (feature 28): `['fscStatus' => 404]` for the layout, `<x-content::analytics status="404">`,
     * or `data-fsa-404` on `<html>` or `<body>`. Only checked with the native engine possible; a site without the analytics component is
     * already reported by the layout check, so a missing marker is a warning, not a failure.
     */
    private function checkNotFoundHook(): void
    {
        try {
            $file = $this->app['view']->getFinder()->find('errors.404');
        } catch (\Throwable) {
            return;
        }

        $contents = is_string($file) && is_file($file) ? (string) file_get_contents($file) : '';

        if (preg_match('/fscStatus|data-fsa-404|status\s*=\s*[\'"]?404/', $contents) === 1) {
            $this->add(self::OK, 'errors/404 meldt zich bij de tracker (not_found)');
        } else {
            $this->add(self::WARN, 'errors/404 meldt zich niet bij de tracker', 'Zonder dat telt de native analytics geen niet-gevonden-pagina\'s: gebruik @extends(\'layouts.app\', [\'fscStatus\' => 404]) of zet data-fsa-404 op <html> of <body>.');
        }
    }

    private function viewsContain(string $needle): bool
    {
        foreach ($this->views() as $contents) {
            if (str_contains($contents, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $path): string
    {
        $base = rtrim($this->app->basePath(), '/') . '/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * The site's own layouts: views with a `</head>`. Error pages (a 503 must not call the Hub) and mail templates are standalone documents.
     *
     * @return array<string, string> path => contents
     */
    private function layouts(): array
    {
        return array_filter(
            $this->views(),
            static fn (string $contents, string $path): bool => stripos($contents, '</head>') !== false
                && preg_match('~/(errors|mail|emails|vendor)/~', str_replace('\\', '/', $path)) !== 1,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function checkLayouts(): void
    {
        $views = $this->views();
        $layouts = $this->layouts();
        $autoPages = $this->autoPagesInUse();

        if ($layouts === []) {
            // The SDK's own pages come with a small layout (head, content section, scripts): a fresh app needs none of its own.
            if (! ($autoPages && Layout::name() === Layout::PACKAGE)) {
                $this->add(self::FAIL, 'Geen layout met <head> gevonden in ' . implode(', ', array_map($this->relative(...), $this->viewPaths())), 'Maak resources/views/layouts/app.blade.php met <x-content::head /> in <head> en <x-content::scripts /> voor </body> (zie AGENTS.md, stap "Layout"), of laat de SDK de pagina\'s tonen met zijn eigen layout (FSC_AUTO_PAGES=true).');

                return;
            }

            $this->add(self::OK, 'Geen eigen layout: de pagina\'s van de SDK gebruiken zijn standaardlayout (' . Layout::PACKAGE . ')');
        }

        // Two accepted shapes: the pair `head` + `scripts`, or the old five that each did one part.
        $required = [
            '<x-content::meta' => 'head',
            '<x-content::head' => 'head',
            '<x-content::errors' => 'head',
            '<x-content::body-end' => 'body',
            '<x-content::analytics' => 'body',
        ];

        foreach ($layouts as $path => $contents) {
            $headEnd = (int) stripos($contents, '</head>');
            $head = strpos($contents, '<x-content::head');
            $name = $this->relative($path);

            if ($head !== false && $head < $headEnd && str_contains($contents, '<x-content::scripts')) {
                $this->add(self::OK, "Layout {$name} heeft head en scripts");

                continue;
            }

            $missing = [];
            $misplaced = [];
            foreach ($required as $tag => $where) {
                $at = strpos($contents, $tag);
                if ($at === false) {
                    $missing[] = $tag . ' />';
                } elseif ($where === 'head' && $at > $headEnd) {
                    $misplaced[] = $tag . ' /> hoort in <head>';
                }
            }

            if ($missing === [] && $misplaced === []) {
                $this->add(self::OK, "Layout {$name} heeft meta, head, errors, body-end en analytics");

                continue;
            }

            // A layout that uses none of the old-only components is on its way to the new pair: name what that pair still misses.
            $oldOnly = ['<x-content::meta', '<x-content::errors', '<x-content::body-end', '<x-content::analytics'];
            if (array_filter($oldOnly, static fn (string $tag): bool => str_contains($contents, $tag)) === []) {
                $missing = $head === false ? ['<x-content::head />'] : [];
                $misplaced = $head !== false && $head > $headEnd ? ['<x-content::head /> hoort in <head>'] : [];

                if (! str_contains($contents, '<x-content::scripts')) {
                    $missing[] = '<x-content::scripts />';
                }
            }

            $this->add(self::FAIL, "Layout {$name} mist: " . implode(', ', [...$missing, ...$misplaced]), 'Zie AGENTS.md, stap "Layout": <x-content::head /> in <head> en <x-content::scripts /> voor </body>.');
        }

        $this->checkAutoLayout($autoPages);
        $this->checkBanner();

        foreach (['404', '503'] as $code) {
            if ($this->app['view']->exists("errors.{$code}")) {
                $this->add(self::OK, "Eigen foutpagina errors/{$code}.blade.php");

                if ($code === '404') {
                    $this->checkNotFoundHook();
                }
            } elseif ($autoPages && Auto::on('errors')) {
                // The SDK serves the pages too, so its error pages (same layout) fit; a site with pages of its own keeps the warning.
                $this->add(self::OK, "Foutpagina errors/{$code} van de SDK (automatisch)");
            } else {
                $this->add(self::WARN, "Geen eigen foutpagina errors/{$code}.blade.php", $code === '404'
                    ? 'Laravel toont dan zijn kale standaardpagina; maak er een in de huisstijl: @extends(\'layouts.app\', [\'fscStatus\' => 404]) of data-fsa-404 op <html>.'
                    : 'Maak een losse pagina zonder layout en zonder content::-componenten: een 503 betekent meestal dat de Hub onbereikbaar is.');
            }
        }

        foreach ($views as $path => $contents) {
            if (preg_match('/fullUrl\(\)|url\(\)->full\(\)|->fullUrlWithQuery\(/', $contents) === 1) {
                $this->add(self::WARN, 'View ' . $this->relative($path) . ' gebruikt de volledige URL met query', 'Bouw canonical, og:url en deellinks uit het pad: de query kan ?fscn= en utm-waarden bevatten.');
            }
        }
    }

    /**
     * The layout the SDK's own views extend (`content.auto.layout`, else `layouts.app`, else the package layout) has to exist and to print the
     * configured section; without it every automatic page renders empty. Only where the SDK's pages are in use. A layout without the SDK's
     * container class and without `content.auto.wrap` puts the content in the fallback width `.fsc-wrap`.
     */
    private function checkAutoLayout(bool $autoPages): void
    {
        if (! $autoPages) {
            return;
        }

        $name = Layout::name();

        if ($name === Layout::PACKAGE) {
            return;
        }

        $configured = Auto::layout();

        try {
            $file = $this->app['view']->getFinder()->find($name);
        } catch (Throwable) {
            $file = null;
        }

        if (! is_string($file) || ! is_file($file)) {
            $this->add(self::FAIL, "content.auto.layout ({$name}) bestaat niet", $configured !== null
                ? 'De standaardpagina\'s van de SDK extenden deze view. Maak hem, of haal FSC_AUTO_LAYOUT weg (dan gebruikt de SDK layouts.app, of zijn eigen layout).'
                : 'De standaardpagina\'s van de SDK extenden deze view.');

            return;
        }

        $contents = (string) file_get_contents($file);
        $section = Auto::section();

        if (preg_match('/@yield\(\s*[\'"]' . preg_quote($section, '/') . '[\'"]/', $contents) !== 1) {
            $this->add(self::FAIL, "Layout {$name} heeft geen @yield('{$section}'): de standaardpagina's van de SDK blijven leeg", "Zet @yield('{$section}') op de plek van de inhoud, of zet content.auto.section in config/content.php op de naam die de layout wel heeft, of publiceer de views (php artisan vendor:publish --tag=content-views) en pas ze aan.");

            return;
        }

        $this->add(self::OK, "Layout {$name} heeft @yield('{$section}') voor de standaardpagina's van de SDK");

        if (Auto::wrap() === null && ! str_contains($contents, 'fsc-wrap') && ! $this->siteAssetsContain('fsc-wrap')) {
            $this->add(self::WARN, 'De standaardpagina\'s van de SDK staan in de terugvalbreedte (.fsc-wrap) in plaats van in de contentbreedte van de site', 'Zet FSC_AUTO_WRAP op de klassen van de container van de site (bijvoorbeeld "wrap max-w-5xl"), of stijl .fsc-wrap zelf.');
        }
    }

    /** Whether the site's own CSS or JS (resources/css, resources/js) mentions a string. */
    private function siteAssetsContain(string $needle): bool
    {
        $dirs = array_values(array_filter([$this->app->resourcePath('css'), $this->app->resourcePath('js')], 'is_dir'));

        if ($dirs === []) {
            return false;
        }

        foreach (Finder::create()->files()->in($dirs)->size('< 2M')->notPath(['build', 'vendor']) as $file) {
            if (str_contains((string) file_get_contents($file->getRealPath()), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site's own banner: a view of the site that sends `fsc:consent`. The published copy of the SDK banner lives under `vendor/` and is
     * not listed by {@see views()}, so a customised SDK banner counts as the SDK's.
     */
    private function ownBanner(): bool
    {
        return $this->viewsContain('fsc:consent');
    }

    /**
     * The SDK's banner is printed by `<x-content::scripts />`: some layout of the site has it (without `consent="false"`), or the SDK's own
     * pages are in use with the package layout. Needs the `consent` feature on.
     */
    private function packageBanner(): bool
    {
        if (! Auto::on('consent')) {
            return false;
        }

        if ($this->autoPagesInUse() && Layout::name() === Layout::PACKAGE) {
            return true;
        }

        foreach ($this->layouts() as $contents) {
            if (preg_match_all('/<x-content::scripts\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/s', $contents, $tags) < 1) {
                continue;
            }

            foreach ($tags[0] as $tag) {
                if (preg_match('/\s:?consent\s*=\s*["\']?\s*(?:false|0)\b/i', $tag) !== 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function checkBanner(): void
    {
        $own = $this->ownBanner();

        if ($own && $this->packageBanner()) {
            $this->add(self::WARN, 'Twee cookiebanners: een eigen banner (stuurt fsc:consent) en die van de SDK (<x-content::scripts />)', 'Houd er één over. Blijft de eigen banner, zet dan FSC_AUTO_CONSENT=false (of <x-content::scripts :consent="false" />); blijft die van de SDK, haal dan de eigen weg.');
        } elseif ($own) {
            $this->add(self::OK, 'Cookiebanner gevonden (stuurt fsc:consent)');
        } elseif (! $this->packageBanner()) {
            $this->add(self::WARN, 'Geen cookiebanner gevonden (geen view stuurt fsc:consent)', 'Zonder banner draait geen code met toestemming (eigen code, heatmaps). Zet <x-content::scripts /> voor </body> (de SDK toont dan zijn banner), of bouw een eigen volgens AGENTS.md, "Cookiebanner".');
        }
    }

    /**
     * The headers the SDK adds to HTML pages by itself (preconnect to the Hub, `X-FSC-SDK`): informational, nothing to build.
     */
    private function checkResponseHeaders(): void
    {
        $state = static fn (bool $on): string => $on ? 'aan' : 'uit';

        $this->add(
            self::INFO,
            'Antwoordheaders: preconnect naar de Hub ' . $state((bool) $this->config('content.response_headers.preconnect', true))
                . ' (FSC_PRECONNECT), SDK-versie ' . $state((bool) $this->config('content.response_headers.version', true)) . ' (FSC_VERSION_HEADER)'
        );
    }

    // --- Portal preview -------------------------------------------------------------------------------------------------------

    /**
     * The live preview from the portal (`POST /_fsc/p`): on with a server token unless `FSC_PREVIEW_ENABLED=false`. A cookie banner must stay
     * hidden under `html[data-fsc-preview]`, and framing by the portal must not be blocked outside PHP (nginx, Apache): the SDK can only
     * rewrite headers that PHP sends.
     */
    private function checkPreview(): void
    {
        if (! (bool) $this->config('content.preview.enabled', true)) {
            $this->add(self::INFO, 'Voorbeeld vanuit het portaal staat uit (FSC_PREVIEW_ENABLED=false)');

            return;
        }

        if (! PreviewRequest::enabled()) {
            $this->add(self::WARN, 'Voorbeeld vanuit het portaal werkt niet: geen servertoken', 'Het voorbeeld haalt de conceptpagina met FSC_SERVER_TOKEN op.');

            return;
        }

        $this->add(self::OK, 'Voorbeeld vanuit het portaal staat aan (POST ' . PreviewRequest::base() . ')');

        if ($this->ownBanner() && ! $this->bannerKnowsPreview()) {
            $this->add(self::WARN, 'Cookiebanner verbergt zich niet in het voorbeeld', 'Verberg de banner als <html> het attribuut data-fsc-preview heeft, bv. html[data-fsc-preview] #fsc-consent { display: none !important; }. Zie AGENTS.md, "Voorbeeld vanuit het portaal".');
        }

        foreach ($this->pageCachePackages() as $package) {
            $this->add(self::WARN, "Paginacache-pakket {$package} gevonden", 'Het voorbeeld zet alle cachestores op array en zet bekende schakelaars uit, maar een paginacache die buiten Laravels cache schrijft (bestanden in public/, een CDN-regel) kan een conceptpagina onder de live URL bewaren. Sla POST /_fsc/p over in die cache, of gebruik geen paginacache.');
        }

        foreach ($this->serverConfigFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/X-Frame-Options|frame-ancestors/i', $contents) === 1) {
                $this->add(self::WARN, 'X-Frame-Options of frame-ancestors buiten PHP in ' . $this->relative($file), 'De webserver zet die header op elk antwoord, ook op het voorbeeld; dan kan het portaal de site niet tonen. Zet hem in PHP (middleware), de SDK past hem in het voorbeeld aan.');
            }
        }
    }

    // --- Page builder blocks (SDK 0.28) -----------------------------------------------------------------------------------------

    /**
     * The prerequisites of the module blocks and the `html` block: the newsletter route behind `newsletterSignup`, and a CSP that goes through
     * `Content::siteCspMerge()` (or `siteCsp()`): an isolated `html` block needs `frame-src 'self'` (its frame is the site route `/_fsc/h/...`
     * with its own sandbox policy) and an inline one (staff) the hosts of its code; only the merge adds them, per page. A policy set outside PHP
     * (nginx, Apache) cannot be merged and would also replace the frame route's own policy; so would a CSP middleware in the GLOBAL stack
     * (keep it in the `web` group). Consent gated blocks also need the cookie banner (see the layout check: it must send `fsc:consent`).
     */
    private function checkPageBuilderBlocks(): void
    {
        if ($this->app['router']->getRoutes()->hasNamedRoute('content.newsletter')) {
            $this->add(self::OK, 'Nieuwsbriefroute voor het blok Nieuwsbrief aanmelden (POST /' . trim((string) $this->config('content.newsletter.path', 'content/newsletter'), '/') . ')');
        } else {
            $this->add(self::INFO, 'Nieuwsbriefroute staat uit (FSC_NEWSLETTER_ENABLED=false): het blok Nieuwsbrief aanmelden toont niets', 'Zet hem aan, of roep Content::newsletterRoutes() aan in een routesbestand.');
        }

        foreach ($this->serverConfigFiles() as $file) {
            if (preg_match('/Content-Security-Policy/i', (string) file_get_contents($file)) === 1) {
                $this->add(self::WARN, 'Content-Security-Policy buiten PHP in ' . $this->relative($file), 'Die policy kan Content::siteCspMerge() niet aanvullen (HTML-codeblokken hebben minstens frame-src \'self\' nodig) en vervangt ook de eigen sandbox-policy van /_fsc/h. Zet de CSP in een middleware in de web-groep met Content::siteCspMerge().');
            }
        }

        $setsCsp = [];
        $merges = false;

        foreach ($this->sitePhpFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/Content-Security-Policy(?!-Report-Only)/i', $contents) === 1) {
                $setsCsp[] = $file;
            }

            if (preg_match('/siteCspMerge\s*\(|siteCsp\s*\(/', $contents) === 1) {
                $merges = true;
            }
        }

        if ($setsCsp === []) {
            $this->add(self::INFO, 'Geen Content-Security-Policy in de PHP-code gevonden: HTML-codeblokken hebben niets extra nodig');
        } elseif ($merges) {
            $this->add(self::OK, 'Content-Security-Policy gaat door Content::siteCspMerge() (eigen code en HTML-codeblokken)');
        } else {
            $this->add(self::WARN, 'Content-Security-Policy zonder Content::siteCspMerge() in ' . implode(', ', array_map($this->relative(...), $setsCsp)), 'Afgeschermde HTML-codeblokken hebben dan geen frame-src \'self\', inline blokken (staf) en eigen code uit de module site laden geen scripts of iframes van andere hosts. Haal de policy door Content::siteCspMerge($policy) nadat de pagina gerenderd is, en geef scripts de nonce (Vite::useCspNonce()). Zie AGENTS.md, A13.');
        }

        // A CSP middleware in the global stack also runs on /_fsc/h and would replace the frame document's own sandbox policy.
        $bootstrap = $this->app->basePath('bootstrap/app.php');
        $bootstrapCode = is_file($bootstrap) ? (string) file_get_contents($bootstrap) : '';

        foreach ($setsCsp as $file) {
            $class = pathinfo($file, PATHINFO_FILENAME);

            if ($bootstrapCode !== '' && preg_match('/->(?:append|prepend)\(\s*\[?[^)]*?\b' . preg_quote($class, '/') . '::class/', $bootstrapCode) === 1) {
                $this->add(self::WARN, "CSP-middleware {$class} staat in de globale middleware", 'Die draait ook op /_fsc/h (afgeschermde HTML-codeblokken) en vervangt daar de eigen sandbox-policy. Zet hem in de web-groep: $middleware->web(append: [' . $class . '::class]).');
            }
        }
    }

    /**
     * `<x-content::page-blocks>` needs `wrap="<the site's container classes>"`: blocks rendered by a default view of the SDK are wrapped in it. Without
     * it they sit in the fallback `.fsc-wrap` (72rem, 1rem padding), which only matches a site by accident. Checked statically on the site's views:
     * a tag without `wrap=` is only fine when the site has its own view for every block type the SDK has a default view for (the `views` prop,
     * `content.blocks.<type>` or `resources/views/vendor/content/blocks`). A warning, never a failure.
     */
    private function checkPageBlocksWidth(): void
    {
        $withoutWrap = [];
        $covered = [];
        $uses = false;

        foreach ($this->views() as $path => $contents) {
            if (preg_match_all('/<x-content::page-blocks\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/s', $contents, $tags) < 1) {
                continue;
            }

            $uses = true;

            foreach ($tags[0] as $tag) {
                if (preg_match('/\s:?wrap\s*=/', $tag) !== 1) {
                    $withoutWrap[] = $this->relative($path);
                }

                if (preg_match_all('/[\'"]([A-Za-z][A-Za-z0-9]*)[\'"]\s*=>/', $tag, $keys) > 0) {
                    $covered = [...$covered, ...$keys[1]];
                }
            }
        }

        if (!$uses) {
            return;
        }

        $withoutWrap = array_values(array_unique($withoutWrap));

        if ($withoutWrap === []) {
            $this->add(self::OK, '<x-content::page-blocks> krijgt wrap (de standaardblokken staan in de contentbreedte van de site)');

            return;
        }

        foreach ((array) $this->config('content.blocks', []) as $type => $view) {
            $covered[] = (string) $type;
        }

        $defaults = [];
        foreach (glob(dirname(__DIR__, 2) . '/resources/views/blocks/*.blade.php') ?: [] as $file) {
            $defaults[] = basename($file, '.blade.php');
        }

        foreach ($this->viewPaths() as $path) {
            foreach (glob($path . '/vendor/content/blocks/*.blade.php') ?: [] as $file) {
                $covered[] = basename($file, '.blade.php');
            }
        }

        $missing = array_values(array_diff($defaults, $covered));

        if ($missing === []) {
            $this->add(self::OK, '<x-content::page-blocks> zonder wrap, maar de site heeft een eigen view voor elk bloktype');

            return;
        }

        $this->add(
            self::WARN,
            '<x-content::page-blocks> zonder wrap in ' . implode(', ', $withoutWrap),
            'De blokken met een standaardview van de SDK (' . implode(', ', array_slice($missing, 0, 5)) . (count($missing) > 5 ? ', ...' : '') . ') vallen dan in de terugvalbreedte (.fsc-wrap: 72rem, 1rem marge) in plaats van in de contentbreedte van de site. Geef de container van de site mee: <x-content::page-blocks wrap="wrap" ...> (de klassen van je eigen container, bijvoorbeeld "wrap max-w-5xl"). Zie AGENTS.md, A18.',
        );
    }

    /** @return list<string> the site's own PHP files that could set response headers: app/, bootstrap/app.php, routes/ and config/ */
    private function sitePhpFiles(): array
    {
        $files = array_values(array_filter([$this->app->basePath('bootstrap/app.php')], 'is_file'));
        $dirs = array_values(array_filter([$this->app->path(), $this->app->basePath('routes'), $this->app->configPath()], 'is_dir'));

        if ($dirs !== []) {
            foreach (Finder::create()->files()->in($dirs)->name('*.php')->size('< 1M')->notPath('vendor') as $file) {
                $files[] = (string) $file->getRealPath();
            }
        }

        return $files;
    }

    /** @return list<string> installed full-page or response cache packages (from composer.lock, else by class) */
    private function pageCachePackages(): array
    {
        $known = [
            'spatie/laravel-responsecache' => 'Spatie\\ResponseCache\\ResponseCacheServiceProvider',
            'silber/page-cache' => 'Silber\\PageCache\\LaravelServiceProvider',
            'renatomarinho/laravel-page-speed' => 'RenatoMarinho\\LaravelPageSpeed\\ServiceProvider',
        ];
        $lock = $this->app->basePath('composer.lock');
        $data = is_file($lock) ? json_decode((string) file_get_contents($lock), true) : null;
        $locked = [];

        foreach (['packages', 'packages-dev'] as $group) {
            foreach (is_array($data) && is_array($data[$group] ?? null) ? $data[$group] : [] as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null)) {
                    $locked[] = $entry['name'];
                }
            }
        }

        $found = [];

        foreach ($known as $package => $class) {
            if (in_array($package, $locked, true) || class_exists($class)) {
                $found[] = $package;
            }
        }

        return $found;
    }

    /** The banner rule may live in a view or in the site's own CSS or JS. */
    private function bannerKnowsPreview(): bool
    {
        if ($this->viewsContain('data-fsc-preview') || $this->viewsContain('fscPreviewMode')) {
            return true;
        }

        $dirs = array_values(array_filter([$this->app->resourcePath('css'), $this->app->resourcePath('js')], 'is_dir'));

        if ($dirs === []) {
            return false;
        }

        foreach (Finder::create()->files()->in($dirs)->size('< 2M')->notPath(['build', 'vendor']) as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            if (str_contains($contents, 'data-fsc-preview') || str_contains($contents, 'fscPreviewMode')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> web server configs in the repo: `docker/` (nginx, Apache), `nginx.conf` and `public/.htaccess` */
    private function serverConfigFiles(): array
    {
        $files = array_values(array_filter([$this->app->basePath('nginx.conf'), $this->app->publicPath('.htaccess')], 'is_file'));
        $docker = $this->app->basePath('docker');

        if (is_dir($docker)) {
            foreach (Finder::create()->files()->in($docker)->size('< 1M')->ignoreDotFiles(false) as $file) {
                $files[] = (string) $file->getRealPath();
            }
        }

        return $files;
    }

    // --- Files ---------------------------------------------------------------------------------------------------------------

    private function checkPublicFiles(): void
    {
        foreach (['robots.txt', 'sitemap.xml'] as $file) {
            if (is_file($this->app->publicPath($file))) {
                $this->add(self::FAIL, "public/{$file} bestaat", "De webserver serveert dat bestand vóór de route; verwijder het en laat de SDK /{$file} maken.");
            }
        }

        $gitignore = $this->app->basePath('.gitignore');
        if (is_file($gitignore) && preg_match('/^\/?\.env\s*$/m', (string) file_get_contents($gitignore)) === 1) {
            $this->add(self::OK, '.env staat in .gitignore');
        } else {
            $this->add(self::WARN, '.env staat niet in .gitignore', 'Secrets horen alleen in de runtime-env, nooit in de repo.');
        }
    }

    private function checkSecrets(): void
    {
        $found = [];
        $dirs = array_values(array_unique(array_filter([$this->app->resourcePath(), $this->app->publicPath(), ...$this->viewPaths()], 'is_dir')));
        if ($dirs === []) {
            return;
        }

        $finder = Finder::create()->files()->in($dirs)->size('< 2M')->notPath(['build', 'vendor']);
        foreach ($finder as $file) {
            if (preg_match('/fsc_(srv|whsec)_[A-Za-z0-9]{8,}/', (string) file_get_contents($file->getRealPath())) === 1) {
                $found[] = $this->relative((string) $file->getRealPath());
            }
        }

        if ($found === []) {
            $this->add(self::OK, 'Geen servertoken of webhookgeheim in resources/, public/ of de views');
        } else {
            $this->add(self::FAIL, 'Geheim gevonden in: ' . implode(', ', $found), 'Haal het weg, roteer het in de Hub en zet het alleen als runtime-env.');
        }
    }

    private function checkConfigCacheable(): void
    {
        $closures = [];
        $walk = function (mixed $value, string $key) use (&$walk, &$closures): void {
            if ($value instanceof Closure) {
                $closures[] = $key;
            } elseif (is_array($value)) {
                foreach ($value as $child => $item) {
                    $walk($item, $key === '' ? (string) $child : $key . '.' . $child);
                }
            }
        };
        $walk($this->app['config']->all(), '');

        if ($closures !== []) {
            $this->add(self::FAIL, 'config:cache zal falen: closure in ' . implode(', ', array_slice($closures, 0, 5)), 'Het image draait config:cache bij het starten; gebruik geen closures in config/.');
        } else {
            $this->add(self::OK, 'Config is te cachen (config:cache bij het starten van het image)');
        }

        if (method_exists($this->app, 'configurationIsCached') && $this->app->configurationIsCached()) {
            $this->add(self::WARN, 'De config is nu gecachet', 'Wijzigingen in .env tellen pas na php artisan config:clear (of config:cache).');
        }
    }

    // --- Online ---------------------------------------------------------------------------------------------------------------

    private function checkOnline(): void
    {
        $client = $this->app->make(ContentClient::class);

        try {
            $project = $client->getProject(['cache' => false]);
            $name = (string) ($project['project']['name'] ?? $project['name'] ?? '');
            $this->add(self::OK, 'De Hub antwoordt met deze token' . ($name !== '' ? " (project {$name})" : ''));
            $modules = array_values(array_filter((array) ($project['modules'] ?? []), 'is_string'));
            $this->add(self::INFO, 'Modules aan: ' . ($modules === [] ? 'geen' : implode(', ', $modules)));
            if ($modules !== []) {
                $this->add(self::INFO, 'Bouw voor elke module hierboven de sectie "Als de module ... aanstaat" uit AGENTS.md');
            }

            $default = is_string($project['defaultLocale'] ?? null) && $project['defaultLocale'] !== '' ? $project['defaultLocale'] : null;
            $locales = array_values(array_filter((array) ($project['locales'] ?? []), static fn ($locale): bool => is_string($locale) && $locale !== ''));
            if ($default !== null || $locales !== []) {
                $others = array_values(array_diff($locales, [(string) $default]));
                $this->add(self::INFO, 'Talen van het project: ' . ($default ?? 'geen standaardtaal') . ' (standaard)' . ($others === [] ? ', geen prefix in de paden' : ', met pad-prefix: ' . implode(', ', $others)));
            }
        } catch (ContentException $e) {
            $this->add(self::FAIL, 'De Hub weigert of is onbereikbaar (' . $e->errorCode() . ', HTTP ' . $e->status() . ')', 'Controleer FSC_SERVER_TOKEN en FSC_API_URL.');

            return;
        }

        $seo = null;

        try {
            $seo = $client->getSeo(['cache' => false]);
            $origin = (string) ($seo['canonicalOrigin'] ?? '');
            if ($origin === '') {
                $this->add(self::WARN, 'Geen canonicalOrigin in de SEO-instellingen van de Hub', 'Zet het live https-adres, anders gebruikt de sitemap het adres van de app.');
            } else {
                $this->add(self::OK, "canonicalOrigin is {$origin}");
            }
        } catch (ContentException $e) {
            $seo = null;
            $this->add(self::WARN, 'SEO-instellingen niet leesbaar (' . $e->errorCode() . ')', 'Staat de module seo aan voor dit project?');
        }

        $this->checkSitemap($seo);
    }

    /**
     * The sitemap as the app serves it (through its own HTTP kernel, so a hand-built route counts too): counts the `<url>` entries and tells
     * the cases of an empty sitemap apart with the project's SEO settings. Online only: the sitemap asks the Hub.
     *
     * @param array<string, mixed>|null $seo the SEO settings, null when they could not be read
     */
    private function checkSitemap(?array $seo): void
    {
        if ($this->match('GET', '/sitemap.xml') === null) {
            return;
        }

        try {
            $response = $this->app->make(HttpKernel::class)->handle(Request::create('/sitemap.xml', 'GET'));
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'GET /sitemap.xml geeft een fout (' . $e::class . ')');

            return;
        }

        if ($response->getStatusCode() !== 200) {
            $this->add(self::FAIL, 'GET /sitemap.xml geeft ' . $response->getStatusCode(), $response->getStatusCode() === 503
                ? 'De sitemap vraagt de Hub en die antwoordde niet: controleer FSC_SERVER_TOKEN, FSC_API_URL en de module seo.'
                : 'De sitemap hoort 200 met XML te geven.');

            return;
        }

        $count = preg_match_all('/<url[\s>]/', (string) $response->getContent());
        $indexable = $seo === null ? null : ($seo['robotsIndex'] ?? true) !== false;

        if ($count > 0) {
            $this->add(self::OK, "sitemap.xml bevat {$count} " . ($count === 1 ? 'URL' : 'URL\'s'));
        } elseif ($indexable === false) {
            $this->add(self::INFO, 'sitemap is leeg omdat robotsIndex uit staat (testsite)');
        } elseif ($indexable === true) {
            $this->add(self::FAIL, 'sitemap.xml is leeg terwijl robotsIndex aan staat', 'Een site zonder URL\'s in de sitemap wordt niet gevonden: controleer de pagina\'s in de Hub (gepubliceerd?), de module seo en of de eigen routes in de web-groep staan.');
        } else {
            $this->add(self::WARN, 'sitemap.xml is leeg en robotsIndex kon niet gelezen worden', 'Controleer de SEO-instellingen van de Hub (module seo).');
        }

        // A live site that still says noindex: the canonical origin is this very site, and the project is not indexable.
        $origin = is_array($seo) ? (string) ($seo['canonicalOrigin'] ?? '') : '';
        $appHost = (string) parse_url((string) $this->config('app.url'), PHP_URL_HOST);

        if ($indexable === false && $this->production() && $origin !== '' && $appHost !== '' && strcasecmp((string) parse_url($origin, PHP_URL_HOST), $appHost) === 0) {
            $this->add(self::WARN, 'robotsIndex staat uit terwijl canonicalOrigin het adres van deze site is (' . $origin . '): de live site staat nog op noindex', 'Zet robotsIndex aan in de SEO-instellingen van de Hub zodra de site live is.');
        }
    }
}

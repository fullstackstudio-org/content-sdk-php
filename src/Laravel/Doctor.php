<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel;

use Closure;
use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
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
        $this->section(fn () => $this->checkRoutes());
        $this->section(fn () => $this->checkMiddleware());
        $this->section(fn () => $this->checkLayouts());
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
            $this->add(self::FAIL, "FSC_API_URL is geen https-adres ({$baseUrl})", 'Laat hem op https://content.fullstackstudio.nl staan; de SDK bouwt er ook de media-URL\'s mee.');
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

    // --- Routes --------------------------------------------------------------------------------------------------------------

    private function checkRoutes(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        foreach (['sitemap.xml' => 'Sitemap::response()', 'robots.txt' => 'Robots::response()'] as $uri => $helper) {
            $route = $this->match('GET', '/' . $uri);
            if ($route !== null && $route->uri() === $uri) {
                $this->add(self::OK, "GET /{$uri} heeft een eigen route");
            } else {
                $this->add(self::FAIL, "GET /{$uri} ontbreekt", "Route::get('/{$uri}', fn () => {$helper}); vóór de catch-all.");
            }
        }

        $this->checkHealth();

        if ($routes->getByName('content.webhook') !== null) {
            $this->add(self::OK, 'Webhookroute POST /' . trim((string) $this->config('content.webhook.path', 'content/webhook'), '/') . ' is geregistreerd');
        } else {
            $this->add(self::FAIL, 'Webhookroute ontbreekt', 'Zet FSC_WEBHOOK_ENABLED=true (of roep Content::routes() aan), anders zie je wijzigingen pas na de cache-TTL.');
        }

        $usesForms = $this->viewsContain('<x-content::form');
        if ($routes->getByName('content.form') !== null) {
            $this->add(self::OK, 'Formulierroute POST /' . trim((string) $this->config('content.forms.path', 'content/forms'), '/') . '/{key} is geregistreerd');
        } elseif ($usesForms) {
            $this->add(self::FAIL, 'Een view gebruikt <x-content::form> maar de formulierroute ontbreekt', 'Zet FSC_FORMS_ENABLED=true.');
        } else {
            $this->add(self::WARN, 'Formulierroute staat uit', 'Zet FSC_FORMS_ENABLED=true zodra de site een formulier krijgt (<x-content::form>).');
        }

        $catchAll = $this->match('GET', '/fsc-doctor-' . bin2hex(random_bytes(4)) . '/x');
        if ($catchAll !== null && str_contains($catchAll->uri(), '{')) {
            $this->add(self::OK, 'Catch-all voor pagina\'s uit de Hub is aanwezig (' . $catchAll->uri() . ')');
        } else {
            $this->add(self::WARN, 'Geen catch-all route voor pagina\'s uit de Hub', 'Route::get(\'/{path}\', [PageController::class, \'show\'])->where(\'path\', \'.*\'); als allerlaatste route.');
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
            $this->add(self::FAIL, 'GET /api/health ontbreekt', 'Route::get(\'/health\', fn () => response()->json([\'ok\' => true])); in routes/api.php, zonder sessie en zonder call naar de Hub (HEALTHCHECK van het image).');

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

    private function checkLayouts(): void
    {
        $views = $this->views();
        // Error pages (a 503 must not call the Hub) and mail templates are standalone documents, not site layouts.
        $layouts = array_filter(
            $views,
            static fn (string $contents, string $path): bool => stripos($contents, '</head>') !== false
                && preg_match('~/(errors|mail|emails|vendor)/~', str_replace('\\', '/', $path)) !== 1,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($layouts === []) {
            $this->add(self::FAIL, 'Geen layout met <head> gevonden in ' . implode(', ', array_map($this->relative(...), $this->viewPaths())), 'Maak resources/views/layouts/app.blade.php met de componenten uit AGENTS.md.');

            return;
        }

        $required = [
            '<x-content::meta' => 'head',
            '<x-content::head' => 'head',
            '<x-content::errors' => 'head',
            '<x-content::body-end' => 'body',
            '<x-content::analytics' => 'body',
        ];

        foreach ($layouts as $path => $contents) {
            $headEnd = (int) stripos($contents, '</head>');
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

            $name = $this->relative($path);
            if ($missing === [] && $misplaced === []) {
                $this->add(self::OK, "Layout {$name} heeft meta, head, errors, body-end en analytics");
            } else {
                $this->add(self::FAIL, "Layout {$name} mist: " . implode(', ', [...$missing, ...$misplaced]), 'Zie AGENTS.md, stap "Layout".');
            }
        }

        if ($this->viewsContain('fsc:consent')) {
            $this->add(self::OK, 'Cookiebanner gevonden (stuurt fsc:consent)');
        } else {
            $this->add(self::WARN, 'Geen cookiebanner gevonden (geen view stuurt fsc:consent)', 'Zonder banner draait geen code met toestemming (eigen code, heatmaps). Zie AGENTS.md, "Cookiebanner".');
        }

        foreach (['404', '503'] as $code) {
            if ($this->app['view']->exists("errors.{$code}")) {
                $this->add(self::OK, "Eigen foutpagina errors/{$code}.blade.php");

                if ($code === '404') {
                    $this->checkNotFoundHook();
                }
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
        } catch (ContentException $e) {
            $this->add(self::FAIL, 'De Hub weigert of is onbereikbaar (' . $e->errorCode() . ', HTTP ' . $e->status() . ')', 'Controleer FSC_SERVER_TOKEN en FSC_API_URL.');

            return;
        }

        try {
            $seo = $client->getSeo(['cache' => false]);
            $origin = (string) ($seo['canonicalOrigin'] ?? '');
            if ($origin === '') {
                $this->add(self::WARN, 'Geen canonicalOrigin in de SEO-instellingen van de Hub', 'Zet het live https-adres, anders gebruikt de sitemap het adres van de app.');
            } else {
                $this->add(self::OK, "canonicalOrigin is {$origin}");
            }
        } catch (ContentException $e) {
            $this->add(self::WARN, 'SEO-instellingen niet leesbaar (' . $e->errorCode() . ')', 'Staat de module seo aan voor dit project?');
        }
    }
}

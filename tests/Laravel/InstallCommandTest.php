<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\Console\InstallCommand;
use FullStackStudio\Content\Laravel\Install\Skeleton;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

/** `content:install` on temporary apps shaped like a fresh Laravel 11, 12 and 13 skeleton. */
final class InstallCommandTest extends LaravelTestCase
{
    private string $site;
    private string $originalBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = $this->app->basePath();
        $this->site = sys_get_temp_dir() . '/fsc-install-' . bin2hex(random_bytes(6));
        mkdir($this->site, 0755, true);
        $this->app->setBasePath($this->site);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        self::rm($this->site);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function skeletons(): array
    {
        return ['laravel 11' => ['v11'], 'laravel 12' => ['v12'], 'laravel 13' => ['v13']];
    }

    #[DataProvider('skeletons')]
    public function testFreshSkeletonGetsEverything(string $version): void
    {
        $this->skeleton($version);
        file_put_contents($this->path('.env'), "APP_KEY=secret\n");

        $output = $this->install();

        foreach ([
            'resources/views/layouts/app.blade.php: gemaakt',
            'resources/css/app.css: aangevuld',
            '.env.example: aangevuld',
            'config/content.php: gemaakt',
            'AGENTS.md: gemaakt',
            'public/robots.txt: verwijderd',
            'routes/web.php: welcome-route verwijderd',
            'resources/views/welcome.blade.php: verwijderd',
        ] as $line) {
            self::assertStringContainsString($line, $output);
        }

        $layout = $this->read('resources/views/layouts/app.blade.php');
        self::assertStringContainsString('<x-content::head />', $layout);
        self::assertStringContainsString("@yield('content')", $layout);
        self::assertStringContainsString('<x-content::scripts />', $layout);
        self::assertStringContainsString('class="fsc-wrap"', $layout);

        $css = $this->read('resources/css/app.css');
        self::assertStringStartsWith("@import 'tailwindcss';", $css);
        self::assertStringContainsString('/* --fsc-accent: #1d4ed8; */', $css);
        self::assertSame(1, substr_count($css, InstallCommand::CSS_START));

        $env = $this->read('.env.example');
        self::assertStringContainsString("SESSION_DRIVER=file\n", $env);
        self::assertStringContainsString("CACHE_STORE=file\n", $env);
        self::assertStringContainsString("QUEUE_CONNECTION=sync\n", $env);
        self::assertStringContainsString("LOG_CHANNEL=stderr\n", $env);
        self::assertStringNotContainsString('=database', $env);
        self::assertStringContainsString("FSC_API_URL=https://hub.fullstackstudio.nl\n", $env);
        self::assertStringContainsString("FSC_WEBHOOK_SECRET=\n", $env);
        self::assertSame(1, substr_count($env, 'SESSION_DRIVER='));

        $config = $this->read('config/content.php');
        self::assertStringContainsString("'layout' => 'layouts.app'", $config);
        self::assertStringContainsString("'privacyverklaring' => 'privacy_statement'", $config);
        self::assertSame('layouts.app', (require $this->path('config/content.php'))['auto']['layout']);

        self::assertFileDoesNotExist($this->path('public/robots.txt'));
        self::assertFileDoesNotExist($this->path('resources/views/welcome.blade.php'));
        self::assertSame(Skeleton::EMPTY_ROUTES, $this->read('routes/web.php'));
        self::assertStringContainsString('fsc:doctor', $this->read('AGENTS.md'));
        self::assertSame("APP_KEY=secret\n", $this->read('.env'), '.env is never touched');
        self::assertFileDoesNotExist($this->path('Dockerfile'));
        self::assertStringContainsString('fsc:doctor --skip-env', $output);
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->skeleton('v13');
        $this->install(['--docker' => true]);
        $before = $this->snapshot();

        $output = $this->install(['--docker' => true]);

        self::assertSame($before, $this->snapshot());
        self::assertStringNotContainsString(': gemaakt', $output);
        self::assertStringNotContainsString(': aangevuld', $output);
        self::assertStringNotContainsString(': vervangen', $output);
        self::assertStringNotContainsString(': verwijderd', $output);
        self::assertStringContainsString('resources/views/layouts/app.blade.php: bestaat al', $output);
        self::assertStringContainsString('.env.example: bestaat al', $output);
        self::assertStringContainsString('resources/css/app.css: bestaat al', $output);
        self::assertStringContainsString('AGENTS.md: bestaat al', $output);
        self::assertStringContainsString('Dockerfile: bestaat al', $output);
    }

    public function testAnEditedFileIsKeptAndReportedAndForceReplacesIt(): void
    {
        $this->skeleton('v12');
        $this->install(['--docker' => true]);

        file_put_contents($this->path('resources/views/layouts/app.blade.php'), 'my own layout');
        file_put_contents($this->path('config/content.php'), '<?php return ["auto" => ["layout" => "layouts.mine"]];');
        file_put_contents($this->path('Dockerfile'), 'FROM scratch');

        $output = $this->install(['--docker' => true]);

        self::assertStringContainsString('resources/views/layouts/app.blade.php: bestaat al', $output);
        self::assertStringContainsString('config/content.php: bestaat al', $output);
        self::assertStringContainsString('Dockerfile: bestaat al', $output);
        self::assertSame('my own layout', $this->read('resources/views/layouts/app.blade.php'));
        self::assertSame('FROM scratch', $this->read('Dockerfile'));

        $output = $this->install(['--docker' => true, '--force' => true]);

        self::assertStringContainsString('resources/views/layouts/app.blade.php: vervangen', $output);
        self::assertStringContainsString('config/content.php: vervangen', $output);
        self::assertStringContainsString('Dockerfile: vervangen', $output);
        self::assertStringContainsString('<x-content::head />', $this->read('resources/views/layouts/app.blade.php'));
        self::assertStringContainsString('FROM composer:2 AS vendor', $this->read('Dockerfile'));
        // A forced run still keeps one marked block of each kind.
        self::assertSame(1, substr_count($this->read('resources/css/app.css'), InstallCommand::CSS_START));
        self::assertSame(1, substr_count($this->read('.env.example'), InstallCommand::ENV_START));
    }

    public function testForceRefreshesTheThemeBlockWithoutTouchingTheRestOfTheCss(): void
    {
        $this->skeleton('v13');
        $this->install();
        $css = $this->read('resources/css/app.css');
        $edited = str_replace('/* --fsc-accent: #1d4ed8; */', '--fsc-accent: #ff0000;', $css) . "\n.mine { color: red; }\n";
        file_put_contents($this->path('resources/css/app.css'), $edited);

        self::assertStringContainsString('resources/css/app.css: bestaat al', $this->install());
        self::assertSame($edited, $this->read('resources/css/app.css'));

        self::assertStringContainsString('resources/css/app.css: vervangen', $this->install(['--force' => true]));
        $forced = $this->read('resources/css/app.css');
        self::assertStringContainsString('/* --fsc-accent: #1d4ed8; */', $forced);
        self::assertStringContainsString('.mine { color: red; }', $forced);
        self::assertStringStartsWith("@import 'tailwindcss';", $forced);
    }

    public function testACustomisedWelcomeRouteIsLeftAloneWithAWarning(): void
    {
        $this->skeleton('v13');
        $routes = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', function () {\n    return view('welcome', ['x' => 1]);\n});\n";
        file_put_contents($this->path('routes/web.php'), $routes);

        $output = $this->install();

        self::assertSame($routes, $this->read('routes/web.php'));
        self::assertFileExists($this->path('resources/views/welcome.blade.php'));
        self::assertStringContainsString('routes/web.php heeft een eigen welcome-route', $output);
        self::assertStringNotContainsString('welcome-route verwijderd', $output);
        // The skeleton's public/robots.txt is unrelated to the route and still goes.
        self::assertFileDoesNotExist($this->path('public/robots.txt'));
    }

    public function testACustomisedWelcomeViewAndRobotsStayWithWarnings(): void
    {
        $this->skeleton('v11');
        file_put_contents($this->path('resources/views/welcome.blade.php'), '<h1>Mijn welkom</h1>');
        file_put_contents($this->path('public/robots.txt'), "User-agent: *\nDisallow: /geheim\n");

        $output = $this->install();

        self::assertSame('<h1>Mijn welkom</h1>', $this->read('resources/views/welcome.blade.php'));
        self::assertSame("User-agent: *\nDisallow: /geheim\n", $this->read('public/robots.txt'));
        self::assertStringContainsString('resources/views/welcome.blade.php is aangepast', $output);
        self::assertStringContainsString('public/robots.txt is aangepast', $output);
        self::assertSame(Skeleton::EMPTY_ROUTES, $this->read('routes/web.php'));
    }

    public function testEnvExampleKeepsCustomValuesAndAddsOnlyMissingKeys(): void
    {
        mkdir($this->path('resources/css'), 0755, true);
        file_put_contents($this->path('.env.example'), "APP_NAME=Site\nSESSION_DRIVER=redis\nFSC_API_URL=https://content.fullstackstudio.nl\nFSC_SERVER_TOKEN=\n");

        $output = $this->install();
        $env = $this->read('.env.example');

        self::assertStringContainsString('.env.example: SESSION_DRIVER=redis blijft staan', $output);
        self::assertStringContainsString('SESSION_DRIVER=redis', $env);
        self::assertStringContainsString("FSC_API_URL=https://content.fullstackstudio.nl\n", $env);
        self::assertSame(1, substr_count($env, 'FSC_SERVER_TOKEN='));
        self::assertSame(1, substr_count($env, 'SESSION_DRIVER='));
        self::assertStringContainsString("CACHE_STORE=file\n", $env);

        // A missing key is added to the existing marked block, not in a second one.
        file_put_contents($this->path('.env.example'), str_replace("QUEUE_CONNECTION=sync\n", '', $env));
        $this->install();
        $again = $this->read('.env.example');
        self::assertSame(1, substr_count($again, InstallCommand::ENV_START));
        self::assertStringContainsString("QUEUE_CONNECTION=sync\n", $again);
    }

    public function testMissingEnvExampleAndCssAreCreated(): void
    {
        $output = $this->install();

        self::assertStringContainsString('.env.example: gemaakt', $output);
        self::assertStringContainsString('resources/css/app.css: gemaakt', $output);
        self::assertStringContainsString("SESSION_DRIVER=file\n", $this->read('.env.example'));
        self::assertStringContainsString(InstallCommand::CSS_START, $this->read('resources/css/app.css'));
    }

    public function testDockerFlagWritesTheImageFiles(): void
    {
        $this->skeleton('v13');
        $output = $this->install(['--docker' => true]);

        foreach (['Dockerfile', '.dockerignore', 'docker/php.ini', 'docker/php-fpm.conf', 'docker/nginx.conf', 'docker/entrypoint.sh', '.github/workflows/build01-image.yml'] as $file) {
            self::assertStringContainsString($file . ': gemaakt', $output);
            self::assertFileExists($this->path($file));
        }

        self::assertTrue(is_executable($this->path('docker/entrypoint.sh')));

        $dockerfile = $this->read('Dockerfile');
        self::assertStringContainsString('registry.int0.net/base/php:8.5.12-fpm-alpine', $dockerfile);
        self::assertStringContainsString('EXPOSE 3000', $dockerfile);
        self::assertStringContainsString('/api/health', $dockerfile);
        self::assertStringContainsString('SESSION_DRIVER=file', $dockerfile);
        self::assertStringNotContainsString('packages/content-sdk-php', $dockerfile);

        $nginx = $this->read('docker/nginx.conf');
        self::assertStringContainsString('fastcgi_buffer_size 32k', $nginx);
        self::assertStringContainsString('listen 3000', $nginx);
        // Behind Cloudflare and the ingress the request arrives as http: PHP has to know the visitor used https.
        self::assertStringContainsString('fastcgi_param HTTPS $fsc_https;', $nginx);
        self::assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_PROTO $fsc_proto;', $nginx);
        self::assertMatchesRegularExpression('/map \$fsc_proto \$fsc_https \{\s+default on;/', $nginx);
        self::assertStringContainsString('registry.int0.net/rt/', $this->read('.github/workflows/build01-image.yml'));
    }

    public function testDockerStubsStayInSyncWithTheExampleSite(): void
    {
        $example = __DIR__ . '/../../../../examples/laravel';

        if (! is_file($example . '/Dockerfile')) {
            self::markTestSkipped('The examples/laravel directory is not part of this checkout.');
        }

        foreach (['php.ini', 'php-fpm.conf', 'nginx.conf', 'entrypoint.sh'] as $file) {
            self::assertSame(
                file_get_contents($example . '/docker/' . $file),
                file_get_contents(InstallCommand::stubPath('docker/' . $file)),
                "stubs/docker/{$file} drifted from examples/laravel/docker/{$file}: copy it over."
            );
        }

        // The runtime stage of the Dockerfile is the same, apart from the examples/laravel/ prefix of the monorepo build context.
        $runtime = static fn (string $dockerfile): string => substr($dockerfile, (int) strpos($dockerfile, '# --- 3. Runtime'));
        self::assertSame(
            $runtime(str_replace('examples/laravel/', '', (string) file_get_contents($example . '/Dockerfile'))),
            $runtime((string) file_get_contents(InstallCommand::stubPath('Dockerfile'))),
            'The runtime stage of stubs/Dockerfile drifted from examples/laravel/Dockerfile: copy it over.'
        );
    }

    public function testTheSkeletonFixturesAreOnTheHashList(): void
    {
        foreach (['v11', 'v12', 'v13'] as $version) {
            $dir = __DIR__ . '/../Fixtures/skeleton/' . $version;

            self::assertTrue(Skeleton::isWelcomeView((string) file_get_contents($dir . '/resources/views/welcome.blade.php')), $version . ' welcome');
            self::assertTrue(Skeleton::isRoutes((string) file_get_contents($dir . '/routes/web.php')), $version . ' routes');
            self::assertTrue(Skeleton::isRobots((string) file_get_contents($dir . '/public/robots.txt')), $version . ' robots');
        }

        self::assertFalse(Skeleton::isWelcomeView('<h1>mine</h1>'));
    }

    /** A temp app that looks like a fresh `laravel new`: the skeleton files of that version plus the usual `.env.example` and `app.css`. */
    private function skeleton(string $version): void
    {
        $fixtures = __DIR__ . '/../Fixtures/skeleton/' . $version;

        foreach (['resources/views/welcome.blade.php', 'routes/web.php', 'public/robots.txt'] as $file) {
            $this->write($file, (string) file_get_contents($fixtures . '/' . $file));
        }

        $this->write('.env.example', "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\n\nLOG_CHANNEL=stack\nLOG_LEVEL=debug\n\nSESSION_DRIVER=database\nSESSION_LIFETIME=120\n\nQUEUE_CONNECTION=database\n\nCACHE_STORE=database\n\nVITE_APP_NAME=\"\${APP_NAME}\"\n");
        $this->write('resources/css/app.css', "@import 'tailwindcss';\n\n@source '../**/*.blade.php';\n");
    }

    /** @param array<string, mixed> $options */
    private function install(array $options = []): string
    {
        Artisan::call('content:install', $options);

        return Artisan::output();
    }

    /** @return array<string, string> relative path => hash */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->site, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($this->site) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private function path(string $relative): string
    {
        return $this->site . '/' . $relative;
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    private function write(string $relative, string $contents): void
    {
        if (! is_dir(dirname($this->path($relative)))) {
            mkdir(dirname($this->path($relative)), 0755, true);
        }

        file_put_contents($this->path($relative), $contents);
    }

    private static function rm(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Console;

use FullStackStudio\Content\Laravel\Install\Skeleton;
use Illuminate\Console\Command;

/**
 * `php artisan content:install`: turns a fresh Laravel app into a site that passes `fsc:doctor`. Idempotent: a second run changes nothing, and
 * a file that exists is never overwritten without `--force`. One Dutch line per file: `gemaakt`, `bestaat al`, `aangevuld`, `vervangen`,
 * `verwijderd`. Never touches `.env`, `bootstrap/app.php` or `routes/web.php` (except the byte-identical skeleton welcome route).
 *
 * Writes the layout, the marked theme block in `resources/css/app.css`, the marked keys in `.env.example`, a sparse `config/content.php`,
 * the AGENTS.md pointer (`fsc:agents`) and, with `--docker`, the Dockerfile, `docker/*` and the build01 workflow from `stubs/`. The docker
 * stubs are copies of FullStack Hub's `examples/laravel`; a test fails when they drift.
 */
final class InstallCommand extends Command
{
    protected $signature = 'content:install
        {--force : Replace files that already exist (the layout, config, docker files and the marked blocks)}
        {--docker : Also write the Dockerfile, docker/*, .dockerignore and the build01 workflow}';

    protected $description = 'Set up this Laravel app as a FullStack Hub site (layout, theme variables, env keys, config; --docker for the image)';

    public const CSS_START = '/* fsc:install:start';
    public const CSS_END = '/* fsc:install:end */';
    public const ENV_START = '# fsc:install:start';
    public const ENV_END = '# fsc:install:end';

    /** The values a fresh Laravel skeleton ships for the keys the FSS platform wants differently: replaced in place, anything else is kept. */
    private const SKELETON_ENV = [
        'SESSION_DRIVER' => 'database',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'LOG_CHANNEL' => 'stack',
    ];

    private const DOCKER_FILES = [
        'Dockerfile' => 'Dockerfile',
        'dockerignore' => '.dockerignore',
        'docker/php.ini' => 'docker/php.ini',
        'docker/php-fpm.conf' => 'docker/php-fpm.conf',
        'docker/nginx.conf' => 'docker/nginx.conf',
        'docker/entrypoint.sh' => 'docker/entrypoint.sh',
        'build01-image.yml' => '.github/workflows/build01-image.yml',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->line('FullStack Hub: de site inrichten');
        $this->newLine();

        $this->file('resources/views/layouts/app.blade.php', self::stub('layout.blade.php'), $force);
        $this->css($force);
        $this->env($force);
        $this->file('config/content.php', self::stub('config.php'), $force);
        $this->agents();
        $this->skeleton();

        if ($this->option('docker')) {
            foreach (self::DOCKER_FILES as $stub => $target) {
                $this->file($target, self::stub($stub), $force, $stub === 'docker/entrypoint.sh' ? 0755 : null);
            }
        }

        $this->newLine();
        $this->line('Controle van de site (fsc:doctor --skip-env):');
        $this->newLine();

        if ($this->call('fsc:doctor', ['--skip-env' => true]) !== self::SUCCESS) {
            $this->newLine();
            $this->warn('De controle meldt nog FOUT-regels. Los die op voordat je deployt.');
        }

        return self::SUCCESS;
    }

    public static function stubPath(string $name): string
    {
        return __DIR__ . '/../../../stubs/' . $name;
    }

    private static function stub(string $name): string
    {
        return (string) file_get_contents(self::stubPath($name));
    }

    private function path(string $relative): string
    {
        return base_path($relative);
    }

    private function say(string $relative, string $status): void
    {
        $this->line($relative . ': ' . $status);
    }

    /** A whole file from a stub: only when missing, or replaced with `--force`. */
    private function file(string $relative, string $contents, bool $force, ?int $mode = null): void
    {
        $path = $this->path($relative);

        if (is_file($path)) {
            if (! $force || (string) file_get_contents($path) === $contents) {
                $this->say($relative, 'bestaat al');

                return;
            }

            $this->put($path, $contents, $mode);
            $this->say($relative, 'vervangen');

            return;
        }

        $this->put($path, $contents, $mode);
        $this->say($relative, 'gemaakt');
    }

    private function put(string $path, string $contents, ?int $mode = null): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);

        if ($mode !== null) {
            chmod($path, $mode);
        }
    }

    /** `resources/css/app.css`: the marked theme block, appended when the marker is absent. */
    private function css(bool $force): void
    {
        $relative = 'resources/css/app.css';
        $path = $this->path($relative);
        $block = self::stub('css-block.css');

        if (! is_file($path)) {
            $this->put($path, $block);
            $this->say($relative, 'gemaakt');

            return;
        }

        $current = (string) file_get_contents($path);
        $start = strpos($current, self::CSS_START);
        $end = $start === false ? false : strpos($current, self::CSS_END, $start);

        if ($start === false || $end === false) {
            $glue = $current === '' || str_ends_with($current, "\n\n") ? '' : (str_ends_with($current, "\n") ? "\n" : "\n\n");
            $this->put($path, $current . $glue . $block);
            $this->say($relative, 'aangevuld');

            return;
        }

        $next = substr($current, 0, $start) . rtrim($block, "\n") . substr($current, $end + strlen(self::CSS_END));

        if (! $force || $next === $current) {
            $this->say($relative, 'bestaat al');

            return;
        }

        $this->put($path, $next);
        $this->say($relative, 'vervangen');
    }

    /** `.env.example`: the missing keys in a marked block; skeleton defaults of the driver keys are replaced in place. `.env` itself is never touched. */
    private function env(bool $force): void
    {
        $relative = '.env.example';
        $path = $this->path($relative);
        $wanted = [];

        foreach (preg_split('/\R/', trim(self::stub('env-block.env'))) ?: [] as $line) {
            [$key, $value] = explode('=', $line, 2);
            $wanted[$key] = $value;
        }

        if (! is_file($path)) {
            $this->put($path, self::ENV_START . "\n" . self::stub('env-block.env') . self::ENV_END . "\n");
            $this->say($relative, 'gemaakt');

            return;
        }

        $current = (string) file_get_contents($path);
        $next = $current;
        $missing = [];

        foreach ($wanted as $key => $value) {
            if (! preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $next, $m)) {
                $missing[$key] = $value;

                continue;
            }

            $have = trim($m[1], " \t\r\"'");

            if ($have === $value || ! array_key_exists($key, self::SKELETON_ENV)) {
                continue;
            }

            if ($have === self::SKELETON_ENV[$key] || $force) {
                $next = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $key . '=' . $value, $next, 1);

                continue;
            }

            $this->warn(sprintf('.env.example: %s=%s blijft staan; een site op het FSS-platform gebruikt %s=%s.', $key, $have, $key, $value));
        }

        if ($missing !== []) {
            $lines = '';
            foreach ($missing as $key => $value) {
                $lines .= $key . '=' . $value . "\n";
            }

            $end = strpos($next, self::ENV_END);

            if (str_contains($next, self::ENV_START) && $end !== false) {
                $next = substr($next, 0, $end) . $lines . substr($next, $end);
            } else {
                $glue = $next === '' || str_ends_with($next, "\n\n") ? '' : (str_ends_with($next, "\n") ? "\n" : "\n\n");
                $next .= $glue . self::ENV_START . "\n" . $lines . self::ENV_END . "\n";
            }
        }

        if ($next === $current) {
            $this->say($relative, 'bestaat al');

            return;
        }

        $this->put($path, $next);
        $this->say($relative, 'aangevuld');
    }

    /** The AGENTS.md pointer, through `fsc:agents` (which also adds `@AGENTS.md` to an existing CLAUDE.md). */
    private function agents(): void
    {
        $path = $this->path('AGENTS.md');
        $before = is_file($path) ? (string) file_get_contents($path) : null;

        if ($this->callSilent('fsc:agents') !== self::SUCCESS) {
            $this->warn('AGENTS.md: niet bijgewerkt (zie `php artisan fsc:agents`).');

            return;
        }

        $after = is_file($path) ? (string) file_get_contents($path) : null;

        $this->say('AGENTS.md', $before === null ? 'gemaakt' : ($before === $after ? 'bestaat al' : 'aangevuld'));
    }

    /** The Laravel skeleton leftovers, only when byte-identical to what `laravel/laravel` ships; otherwise a warning. */
    private function skeleton(): void
    {
        $robots = $this->path('public/robots.txt');

        if (is_file($robots)) {
            if (Skeleton::isRobots((string) file_get_contents($robots))) {
                unlink($robots);
                $this->say('public/robots.txt', 'verwijderd');
            } else {
                $this->warn('public/robots.txt is aangepast en blijft staan. Een bestand in public/ wint van de robots.txt van de SDK; verwijder het zelf als de SDK hem moet leveren.');
            }
        }

        $routes = $this->path('routes/web.php');
        $welcomeRouteGone = true;

        if (is_file($routes)) {
            $contents = (string) file_get_contents($routes);

            if (Skeleton::isRoutes($contents)) {
                file_put_contents($routes, Skeleton::EMPTY_ROUTES);
                $this->say('routes/web.php', 'welcome-route verwijderd');
            } elseif (preg_match('/view\(\s*[\'"]welcome[\'"]/', $contents)) {
                $welcomeRouteGone = false;
                $this->warn('routes/web.php heeft een eigen welcome-route en blijft staan. Die route wint van de pagina "/" uit de Hub; haal hem weg als "/" uit de Hub moet komen.');
            }
        }

        $view = $this->path('resources/views/welcome.blade.php');

        if (! is_file($view)) {
            return;
        }

        if (! $welcomeRouteGone) {
            return;
        }

        if (Skeleton::isWelcomeView((string) file_get_contents($view))) {
            unlink($view);
            $this->say('resources/views/welcome.blade.php', 'verwijderd');
        } else {
            $this->warn('resources/views/welcome.blade.php is aangepast en blijft staan.');
        }
    }
}

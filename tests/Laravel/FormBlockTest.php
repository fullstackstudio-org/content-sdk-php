<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Laravel\Doctor;
use FullStackStudio\Content\Laravel\FormLocale;
use FullStackStudio\Content\Laravel\Site\PageContext;
use FullStackStudio\Content\Laravel\Site\Resolved;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;

/** The `form` page block on a real site: Dutch texts by default, a working route out of the box, and the base styling. Fake transport only. */
final class FormBlockTest extends LaravelTestCase
{
    private FakeTransport $http;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        // The route and locale defaults are what is tested here: no overrides for `content.forms.*`.
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
    }

    private function queueForm(array $override = []): void
    {
        $this->http->queue(FakeTransport::json(['form' => [...[
            'key' => 'contact', 'name' => 'Contact',
            'fields' => [['name' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true, 'maxLength' => 100]],
            'honeypotField' => 'website', 'successMessage' => 'ok', 'confirmEnabled' => false, 'turnstileSiteKey' => null,
        ], ...$override]]));
    }

    public function testTheFormRouteIsRegisteredByDefault(): void
    {
        self::assertTrue((bool) config('content.forms.enabled'));
        self::assertNotNull($this->app['router']->getRoutes()->getByName('content.form'));
    }

    public function testTheFormBlockIsDutchWhenLaravelsDefaultLocaleWasNeverChanged(): void
    {
        self::assertSame('en', app()->getLocale());
        $this->queueForm();
        $html = Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [['id' => 'f', 'type' => 'form', 'formKey' => 'contact']]]);

        self::assertStringContainsString('>Versturen</button>', $html);
        self::assertStringContainsString('(verplicht)', $html);
        self::assertStringContainsString('Laat dit veld leeg', $html);
        self::assertStringNotContainsString('>Send</button>', $html);
    }

    public function testLocaleOrder(): void
    {
        self::assertSame('nl', FormLocale::resolve(), 'unknown/unset defaults to nl');

        app()->setLocale('de');
        self::assertSame('de', FormLocale::resolve(), 'a real app locale is used');

        $context = new PageContext();
        $context->resolve(new Resolved('page', [], '/test', 'fr'));
        $this->app->instance(PageContext::class, $context);
        self::assertSame('fr', FormLocale::resolve(), 'the page locale beats the app locale');

        $english = new PageContext();
        $english->resolve(new Resolved('page', [], '/about', 'en'));
        $this->app->instance(PageContext::class, $english);
        self::assertSame('en', FormLocale::resolve(), 'the English version of a multilingual site gets English form texts');

        $this->app->instance(PageContext::class, new PageContext());
        app()->setLocale('en');
        self::assertSame('nl', FormLocale::resolve(), 'Laravel\'s default app locale en alone still counts as never set');

        config(['content.forms.locale' => 'en']);
        self::assertSame('en', FormLocale::resolve(), 'an explicit config value wins, so English sites stay English');
    }

    public function testTheHubSubmitLabelWinsOverTheDefaultButNotOverAnExplicitLabel(): void
    {
        $this->queueForm(['submitLabel' => 'Verstuur bericht']);
        self::assertStringContainsString('>Verstuur bericht</button>', Blade::render('<x-content::form form-key="contact" />'));

        $this->queueForm(['submitLabel' => 'Verstuur bericht']);
        self::assertStringContainsString('>Nu sturen</button>', Blade::render('<x-content::form form-key="contact" :labels="[\'submit\' => \'Nu sturen\']" />'));
    }

    public function testTheFormBlockGetsTheBaseStylingAndTheFormMeasure(): void
    {
        $this->app['config']->set('content.blocks.inline_styles', true);
        $this->queueForm();
        $html = Blade::render('<x-content::page-blocks :blocks="$b" />', ['b' => [['id' => 'f', 'type' => 'form', 'formKey' => 'contact']]]);

        self::assertStringContainsString('<style', $html);
        self::assertStringContainsString(':where(.fsc-field__control)', $html);
        self::assertStringContainsString('--fsc-form-width', $html);
        self::assertMatchesRegularExpression('/:where\(\.fsc-block--form\)\{max-width: var\(--fsc-form-width\)/', $html);
        self::assertMatchesRegularExpression('/:where\(\.fsc-submit\),:where\(\.fsc-button\)\{color: var\(--fsc-button-text\); background: var\(--fsc-button-bg\)/', $html);
    }

    public function testDoctorFailsClearlyWhenAFormBlockRendersButTheRouteIsOff(): void
    {
        $views = sys_get_temp_dir() . '/fsc-formblock-views-' . bin2hex(random_bytes(4));
        mkdir($views, 0777, true);
        file_put_contents($views . '/page.blade.php', '<x-content::page-blocks :blocks="$blocks" />');
        $this->app['config']->set('view.paths', [$views]);

        // Provider booted with the route on; simulate "off" by removing it from the collection.
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();
        $off = new \Illuminate\Routing\RouteCollection();
        foreach ($routes->getRoutes() as $route) {
            if ($route->getName() !== 'content.form') {
                $off->add($route);
            }
        }
        $this->app['router']->setRoutes($off);

        $results = (new Doctor($this->app))->run(false);
        $fail = array_values(array_filter($results, fn ($r) => $r['status'] === Doctor::FAIL && str_contains($r['label'], 'formulierroute staat uit')));
        exec('rm -rf ' . escapeshellarg($views));

        self::assertCount(1, $fail);
        self::assertStringContainsString('form-blok', $fail[0]['label']);
        self::assertStringContainsString('FSC_FORMS_ENABLED', (string) $fail[0]['hint']);
    }
}

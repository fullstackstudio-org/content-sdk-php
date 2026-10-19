<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Forms;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

final class FormTest extends LaravelTestCase
{
    private FakeTransport $http;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('content.forms.enabled', true);
        $app['config']->set('content.forms.locale', 'en');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/contact', fn () => Blade::render('<x-content::form form-key="contact" />'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeTransport();
        $this->app->singleton(ContentClient::class, fn () => new ContentClient(['token' => 'fsc_srv_laraveltoken', 'baseUrl' => 'https://content.test', 'transport' => $this->http]));
    }

    /** @return array<string, mixed> */
    private function definition(array $override = []): array
    {
        return [...[
            'key' => 'contact',
            'name' => 'Contact',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Your <name>', 'required' => true, 'maxLength' => 20],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'maxLength' => 100],
                ['name' => 'topic', 'type' => 'select', 'label' => 'Topic', 'required' => false, 'maxLength' => 50, 'options' => ['Sales', 'Support']],
                ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => false, 'maxLength' => 500],
                ['name' => 'agree', 'type' => 'checkbox', 'label' => 'I agree', 'required' => true, 'maxLength' => 0],
            ],
            'honeypotField' => 'website',
            'successMessage' => 'Thanks!',
            'confirmEnabled' => false,
            'turnstileSiteKey' => null,
        ], ...$override];
    }

    private function queueDefinition(array $override = []): void
    {
        $this->http->queue(FakeTransport::json(['form' => $this->definition($override)]));
    }

    private function submit(array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $data += ['name' => 'Jan', 'email' => 'jan@example.nl', 'agree' => '1', 'website' => ''];

        return $this->withHeaders($headers + ['Referer' => 'http://localhost/contact?utm_source=news&x=1'])->post('/content/forms/contact', $data);
    }

    public function testGetRendersAllFieldsWithHoneypotAndNoJavascript(): void
    {
        $this->queueDefinition();
        $html = $this->get('/contact')->assertOk()->getContent();

        self::assertStringContainsString('<form class="fsc-form" method="POST" action="http://localhost/content/forms/contact" novalidate>', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('Your &lt;name&gt;', $html, 'labels are escaped');
        self::assertStringContainsString('<input id="fsc-form-contact-email" name="email" class="fsc-field__control" type="email"', $html);
        self::assertStringContainsString('<textarea id="fsc-form-contact-message"', $html);
        self::assertStringContainsString('<option value="Support" >Support</option>', $html);
        self::assertStringContainsString('type="checkbox" value="1"', $html);
        self::assertStringContainsString('name="website" type="text" tabindex="-1" autocomplete="off"', $html);
        self::assertStringContainsString('class="fsc-submit" type="submit">Send</button>', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testFormTextsFollowTheLocale(): void
    {
        $this->queueDefinition();
        $html = Blade::render('<x-content::form form-key="contact" locale="nl" :labels="[\'submit\' => \'Verstuur nu\']" />');

        self::assertStringContainsString('>Verstuur nu</button>', $html);
        self::assertStringContainsString('Kies…', $html);
    }

    public function testALoadFailureShowsAMessageInsteadOfBreakingThePage(): void
    {
        $this->http->always(FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404));

        self::assertStringContainsString('The form could not be loaded.', $this->get('/contact')->assertOk()->getContent());
    }

    public function testTurnstileWidgetIsRenderedWhenTheFormHasASiteKey(): void
    {
        $this->queueDefinition(['turnstileSiteKey' => '0x4AAAA']);
        $html = $this->get('/contact')->getContent();

        self::assertStringContainsString('class="cf-turnstile" data-sitekey="0x4AAAA"', $html);
        self::assertStringContainsString('https://challenges.cloudflare.com/turnstile/v0/api.js', $html);
    }

    public function testPostSubmitsTheValuesWithIpMetaAndRedirectsBackWithAMessage(): void
    {
        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => 'We got it']));

        $response = $this->submit(['topic' => 'Sales', 'message' => 'Hi', 'extra' => 'ignored'], ['CF-Connecting-IP' => '203.0.113.9']);

        $response->assertRedirect('/contact?utm_source=news&x=1#fsc-form-contact');
        $response->assertSessionHas('content_form.contact', ['status' => 'success', 'message' => 'We got it']);

        $request = $this->http->last();
        self::assertSame('POST', $request->method);
        self::assertSame('/forms/contact/submissions', $this->http->lastPath());
        self::assertSame('203.0.113.9', $request->headers['X-FSC-Client-Ip']);
        $body = $this->http->lastBody();
        self::assertSame(['name' => 'Jan', 'email' => 'jan@example.nl', 'topic' => 'Sales', 'message' => 'Hi', 'agree' => true, 'website' => ''], array_diff_key($body, ['_meta' => 1]));
        self::assertSame('news', $body['_meta']['utm_source']);
        self::assertSame('http://localhost/contact?utm_source=news&x=1', $body['_meta']['page_url']);
        self::assertSame('en', $body['_meta']['locale']);
        self::assertArrayNotHasKey('extra', $body);
    }

    public function testFirstTouchAttributionFromTheAnalyticsHelperIsSentAsMeta(): void
    {
        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => 'ok']));
        $attribution = json_encode(['utm_source' => 'google', 'utm_campaign' => 'herfst', 'gclid' => 'Cj0KCQ', 'fbclid' => "x\u{0007}y", 'landing_url' => '/?gclid=Cj0KCQ', 'referrer' => 'https://www.google.com/', 'evil' => 'dropped', 'gbraid' => ['no']]);

        $this->submit(['_fsc_attribution' => $attribution])->assertRedirect();

        $meta = $this->http->lastBody()['_meta'];
        self::assertSame('google', $meta['utm_source'], 'first touch wins over the query of the form page');
        self::assertSame('herfst', $meta['utm_campaign']);
        self::assertSame('Cj0KCQ', $meta['gclid']);
        self::assertSame('xy', $meta['fbclid']);
        self::assertSame('/?gclid=Cj0KCQ', $meta['landing_url']);
        self::assertSame('https://www.google.com/', $meta['referrer']);
        self::assertArrayNotHasKey('evil', $meta);
        self::assertArrayNotHasKey('gbraid', $meta);
        self::assertArrayNotHasKey('_fsc_attribution', $this->http->lastBody());
    }

    public function testBrokenAttributionIsIgnored(): void
    {
        self::assertSame([], Forms::attributionMeta('{nope'));
        self::assertSame([], Forms::attributionMeta(null));
        self::assertSame([], Forms::attributionMeta(str_repeat('a', 20000)));
        self::assertSame(['msclkid' => 'm1'], Forms::attributionMeta(['msclkid' => ' m1 ', 'utm_source' => '']));
    }

    public function testTheIpHeaderIsConfigurableAndFallsBackToTheRequestIp(): void
    {
        config(['content.forms.ip_header' => 'x-real-ip']);
        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => '']));
        $this->submit([], ['X-Real-IP' => '198.51.100.7', 'CF-Connecting-IP' => '203.0.113.9']);
        self::assertSame('198.51.100.7', $this->http->last()->headers['X-FSC-Client-Ip']);

        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['id' => 's2', 'successMessage' => '']));
        $this->submit([], ['X-Real-IP' => 'not-an-ip']);
        self::assertSame('127.0.0.1', $this->http->last()->headers['X-FSC-Client-Ip']);
    }

    public function testARefererThatPointsToAnotherHostNeverBecomesTheRedirect(): void
    {
        foreach (['http://localhost//evil.example/x', 'http://localhost/\\evil.example', 'http://evil.example/contact'] as $referer) {
            $this->queueDefinition();
            $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => 'ok']));

            $this->submit([], ['Referer' => $referer])->assertRedirect('/#fsc-form-contact');
        }
    }

    public function testASuccessWithoutAMessageFallsBackToTheDefinitionMessage(): void
    {
        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => '']));

        $this->submit()->assertSessionHas('content_form.contact.message', 'Thanks!');
    }

    public function testLocalValidationFailuresNeverReachContentAndKeepTheOldInput(): void
    {
        $this->queueDefinition();

        $response = $this->submit(['name' => '', 'agree' => '', 'email' => 'jan@example.nl', 'message' => 'keep me']);

        $response->assertRedirect('/contact?utm_source=news&x=1#fsc-form-contact');
        $response->assertSessionHasInput('message', 'keep me');
        $response->assertSessionMissing('_old_input.website');
        $errors = $response->baseResponse->getSession()->get('errors')->getBag('content_form_contact');
        self::assertSame('This field is required.', $errors->first('name'));
        self::assertSame('This field is required.', $errors->first('agree'));
        self::assertCount(1, $this->http->requests, 'only the definition was fetched, nothing submitted');
    }

    public function testErrorsAndOldInputComeBackInTheNextRender(): void
    {
        $this->queueDefinition();
        $this->submit(['name' => '', 'message' => 'keep me']);

        $this->queueDefinition();
        $html = $this->get('/contact')->getContent();

        self::assertStringContainsString('aria-invalid="true" aria-describedby="fsc-form-contact-name-error"', $html);
        self::assertStringContainsString('<p class="fsc-field__error" id="fsc-form-contact-name-error" role="alert">This field is required.</p>', $html);
        self::assertStringContainsString('keep me</textarea>', $html);
        self::assertStringContainsString('fsc-field--invalid', $html);
    }

    public function testAValidationErrorFromContentBecomesFieldErrors(): void
    {
        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['error' => ['code' => 'invalid_request', 'message' => 'Ongeldige inzending: email is geen geldig e-mailadres; name is verplicht (en 2 meer)']], 400));

        $response = $this->submit();

        $errors = $response->baseResponse->getSession()->get('errors')->getBag('content_form_contact');
        self::assertSame('Is geen geldig e-mailadres', $errors->first('email'));
        self::assertSame('Is verplicht', $errors->first('name'));
        $response->assertSessionMissing('content_form.contact');
        $response->assertSessionHasInput('name', 'Jan');
    }

    public function testRateLimitAndServerErrorsGiveAGeneralMessage(): void
    {
        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['error' => ['code' => 'rate_limited', 'message' => 'x']], 429));
        $this->submit()->assertSessionHas('content_form.contact', ['status' => 'error', 'message' => 'You have submitted too often. Please try again in a minute.']);

        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['error' => ['code' => 'server_error', 'message' => 'x']], 500));
        $this->submit()->assertSessionHas('content_form.contact.message', 'Sending failed. Please try again later.');
    }

    public function testTheHoneypotValueIsPassedThroughSoContentCanStoreItAsSpam(): void
    {
        $this->queueDefinition();
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => '']));
        $this->submit(['website' => 'http://spam.test']);

        self::assertSame('http://spam.test', $this->http->lastBody()['website']);
    }

    public function testTurnstileTokenIsRequiredAndForwarded(): void
    {
        $this->queueDefinition(['turnstileSiteKey' => '0x4AAAA']);
        $this->submit()->assertSessionHas('content_form.contact', ['status' => 'error', 'message' => 'Please confirm that you are not a robot first.']);
        self::assertCount(1, $this->http->requests);

        $this->queueDefinition(['turnstileSiteKey' => '0x4AAAA']);
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => '']));
        $this->submit(['cf-turnstile-response' => 'tok']);
        self::assertSame('tok', $this->http->lastBody()['_turnstile']);
    }

    public function testAPaymentFormRedirectsToTheCheckoutAndConvertsAChoiceToCents(): void
    {
        $definition = ['fields' => [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'maxLength' => 50],
            ['name' => 'amount', 'type' => 'payment', 'label' => 'Amount', 'required' => true, 'maxLength' => 0, 'payment' => ['mode' => 'choice', 'amountCents' => null, 'minCents' => 500, 'maxCents' => 10000, 'currency' => 'EUR', 'description' => 'Gift']],
        ]];
        $this->queueDefinition($definition);
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => '', 'payment' => ['checkoutUrl' => 'https://pay.test/c/1']]));

        $this->submit(['amount' => '12,50'])->assertRedirect('https://pay.test/c/1');
        self::assertSame(1250, $this->http->lastBody()['amount']);
    }

    public function testAnUnknownFormIs404(): void
    {
        $this->http->always(FakeTransport::json(['error' => ['code' => 'not_found', 'message' => 'x']], 404));

        $this->submit()->assertNotFound();
    }

    public function testFormRouteIsOptIn(): void
    {
        self::assertTrue(Route::has('content.form'));
    }

    public function testFormsHelpers(): void
    {
        self::assertSame(1250, Forms::cents('12.5'));
        self::assertNull(Forms::cents('12,505'));
        self::assertSame('Versturen', Forms::labels('nl-NL')['submit']);
        self::assertSame('Send', Forms::labels('xx')['submit']);
        self::assertSame(['fieldErrors' => [], 'rest' => ['boom']], Forms::parseFieldErrors('boom', []));
    }

    private function fileDefinition(): array
    {
        return ['fields' => [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'maxLength' => 50],
            ['name' => 'cv', 'type' => 'file', 'label' => 'Your CV', 'required' => true, 'maxLength' => 255, 'file' => ['maxFiles' => 1, 'maxSizeMb' => 10, 'accept' => ['pdf', 'jpg']]],
            ['name' => 'extra', 'type' => 'file', 'label' => 'Extras', 'required' => false, 'maxLength' => 255, 'file' => ['maxFiles' => 3, 'maxSizeMb' => 5, 'accept' => ['png']]],
        ]];
    }

    public function testFileFieldsRenderWithAcceptMultipleAndEnctype(): void
    {
        $this->queueDefinition($this->fileDefinition());
        $html = $this->get('/contact')->assertOk()->getContent();

        self::assertStringContainsString('method="POST" action="http://localhost/content/forms/contact" enctype="multipart/form-data" novalidate>', $html);
        self::assertMatchesRegularExpression('/<input id="fsc-form-contact-cv" name="cv" class="fsc-field__control" type="file"\s+accept="\.pdf,\.jpg,\.jpeg"\s+required/', $html);
        self::assertMatchesRegularExpression('/name="extra\[\]" class="fsc-field__control" type="file" multiple\s+accept="\.png"/', $html);

        $this->queueDefinition();
        self::assertStringNotContainsString('enctype=', $this->get('/contact')->getContent());
    }

    public function testUploadedFilesAreForwardedAsMultipart(): void
    {
        $this->queueDefinition($this->fileDefinition());
        $this->http->queue(FakeTransport::json(['id' => 's1', 'successMessage' => 'ok']));

        $cv = \Illuminate\Http\UploadedFile::fake()->createWithContent('cv jan.pdf', '%PDF-1.4 jan');
        $one = \Illuminate\Http\UploadedFile::fake()->createWithContent('a.png', 'PNG1');
        $two = \Illuminate\Http\UploadedFile::fake()->createWithContent('b.png', 'PNG2');
        $stray = \Illuminate\Http\UploadedFile::fake()->createWithContent('evil.php', '<?php');

        $this->withHeaders(['Referer' => 'http://localhost/contact'])
            ->post('/content/forms/contact', ['name' => 'Jan', 'website' => '', 'cv' => $cv, 'extra' => [$one, $two], 'unknown' => $stray])
            ->assertRedirect('/contact#fsc-form-contact')
            ->assertSessionHas('content_form.contact.status', 'success');

        $request = $this->http->last();
        self::assertNull($request->body);
        self::assertSame('Jan', $request->multipart['name']);
        self::assertSame('cv jan.pdf', $request->multipart['cv']->filename);
        self::assertSame('%PDF-1.4 jan', $request->multipart['cv']->contents);
        self::assertCount(2, $request->multipart['extra']);
        self::assertSame('b.png', $request->multipart['extra'][1]->filename);
        self::assertArrayNotHasKey('unknown', $request->multipart);
        self::assertSame('en', json_decode($request->multipart['_meta'], true)['locale']);
    }

    public function testARequiredFileThatIsMissingIsALocalError(): void
    {
        $this->queueDefinition($this->fileDefinition());
        $response = $this->submit(['name' => 'Jan']);

        $errors = $response->baseResponse->getSession()->get('errors')->getBag('content_form_contact');
        self::assertSame('This field is required.', $errors->first('cv'));
        self::assertCount(1, $this->http->requests);
    }

    public function testFileErrorsFromContentGiveAGeneralMessageAndNeverPutFilesInTheSession(): void
    {
        $this->queueDefinition($this->fileDefinition());
        $this->http->queue(FakeTransport::json(['error' => ['code' => 'payload_too_large', 'message' => 'x']], 413));
        $cv = \Illuminate\Http\UploadedFile::fake()->createWithContent('cv.pdf', '%PDF');

        $this->withHeaders(['Referer' => 'http://localhost/contact'])
            ->post('/content/forms/contact', ['name' => 'Jan', 'website' => '', 'cv' => $cv])
            ->assertSessionHas('content_form.contact', ['status' => 'error', 'message' => 'A file is too large.'])
            ->assertSessionHasInput('name', 'Jan')
            ->assertSessionMissing('_old_input.cv');

        $this->queueDefinition($this->fileDefinition());
        $this->http->queue(FakeTransport::json(['error' => ['code' => 'unsupported_media_type', 'message' => 'x']], 415));
        $this->withHeaders(['Referer' => 'http://localhost/contact'])
            ->post('/content/forms/contact', ['name' => 'Jan', 'website' => '', 'cv' => $cv])
            ->assertSessionHas('content_form.contact.message', 'One of the files is not an allowed type.');
    }

    private function failedUpload(int $error): \Illuminate\Http\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fsc');

        return new \Illuminate\Http\UploadedFile($path, 'cv.pdf', 'application/pdf', $error, true);
    }

    public function testPhpUploadErrorsAreNotDroppedSilently(): void
    {
        $post = fn (int $error) => $this->withHeaders(['Referer' => 'http://localhost/contact'])
            ->post('/content/forms/contact', ['name' => 'Jan', 'website' => '', 'cv' => $this->failedUpload($error)]);

        $this->queueDefinition($this->fileDefinition());
        $post(UPLOAD_ERR_INI_SIZE)->assertSessionHas('content_form.contact', ['status' => 'error', 'message' => 'A file is too large.']);

        $this->queueDefinition($this->fileDefinition());
        $post(UPLOAD_ERR_FORM_SIZE)->assertSessionHas('content_form.contact.message', 'A file is too large.');

        $this->queueDefinition($this->fileDefinition());
        $post(UPLOAD_ERR_PARTIAL)->assertSessionHas('content_form.contact', ['status' => 'error', 'message' => 'Sending failed. Please try again later.']);

        // Only the three definition fetches: nothing was submitted.
        self::assertCount(3, $this->http->requests);
    }

    public function testAnEmptyFileInputIsNotAnError(): void
    {
        self::assertSame([], Forms::uploadErrors($this->fileDefinition(), ['cv' => $this->failedUpload(UPLOAD_ERR_NO_FILE)]));
        self::assertSame([UPLOAD_ERR_PARTIAL], Forms::uploadErrors($this->fileDefinition(), ['extra' => [$this->failedUpload(UPLOAD_ERR_PARTIAL)], 'name' => $this->failedUpload(UPLOAD_ERR_PARTIAL)]));
    }

    public function testARequestEmptiedByPostMaxSizeIsNeverSubmitted(): void
    {
        $this->queueDefinition($this->fileDefinition());

        // Laravel's own ValidatePostSize would answer 413 first; this is the safety net for apps that removed it.
        $response = $this->withoutMiddleware(\Illuminate\Http\Middleware\ValidatePostSize::class)
            ->withHeaders(['Referer' => 'http://localhost/contact'])
            ->call('POST', '/content/forms/contact', [], [], [], ['CONTENT_LENGTH' => 20_000_000, 'CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
        $response->assertStatus(302)->assertSessionHas('content_form.contact', ['status' => 'error', 'message' => 'A file is too large.']);

        self::assertCount(1, $this->http->requests);
    }
}

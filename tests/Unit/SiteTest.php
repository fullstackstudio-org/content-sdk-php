<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\Site;
use FullStackStudio\Content\Tags;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/** Site standards without a framework: head/body HTML, consent templates, nonces, JSON-LD escaping and CSP sources. */
final class SiteTest extends TestCase
{
    /** @return array<string, mixed> the `GET /api/v1/site` contract of the plan */
    public static function contract(array $override = []): array
    {
        return [...[
            'version' => 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3',
            'verification' => ['google' => 'g00gle-Token_1', 'bing' => 'B1NG', 'facebook' => null, 'pinterest' => 'pin123'],
            'business' => [
                'type' => 'Plumber',
                'name' => 'Loodgieter Jansen',
                'url' => 'https://jansen.nl',
                'telephone' => '+31 70 123 4567',
                'email' => 'info@jansen.nl',
                'address' => ['streetAddress' => 'Straat 1', 'postalCode' => '2511 AB', 'addressLocality' => 'Den Haag', 'addressRegion' => null, 'addressCountry' => 'NL'],
                'geo' => ['latitude' => 52.07, 'longitude' => 4.3],
                'openingHours' => [['days' => ['Mo', 'Tu', 'We', 'Th', 'Fr'], 'opens' => '08:00', 'closes' => '17:00']],
                'areaServed' => ['Den Haag', 'Rijswijk'],
                'priceRange' => '€€',
                'logo' => 'https://content.test/api/media/logo',
                'image' => null,
                'sameAs' => ['https://www.facebook.com/jansen'],
            ],
            'social' => [
                'ogImage' => ['url' => 'https://content.test/api/media/og', 'width' => 1200, 'height' => 630, 'alt' => 'Jansen'],
                'favicon' => ['url' => 'https://content.test/api/media/fav', 'type' => 'image/png'],
                'appleTouchIcon' => 'https://content.test/api/media/touch',
                'themeColor' => '#0a7f3c',
            ],
            'clickEvents' => true,
            'code' => [
                ['id' => '1', 'name' => 'Consent banner', 'placement' => 'head', 'consent' => 'necessary', 'html' => '<script src="https://cdn.banner.test/b.js"></script><style>.x{}</style>'],
                ['id' => '2', 'name' => 'Google Analytics', 'placement' => 'head', 'consent' => 'analytics', 'html' => '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><script>window.dataLayer=[];</script>'],
                ['id' => '3', 'name' => 'Pixel', 'placement' => 'body', 'consent' => 'marketing', 'html' => '<noscript><img src="https://www.facebook.com/tr?id=1"></noscript><iframe src="https://player.vimeo.com/x"></iframe>'],
            ],
        ], ...$override];
    }

    /** @return array<string, mixed> the empty shape (module off) */
    public static function emptyShape(): array
    {
        return [
            'version' => '',
            'verification' => ['google' => null, 'bing' => null, 'facebook' => null, 'pinterest' => null],
            'business' => null,
            'social' => ['ogImage' => null, 'favicon' => null, 'appleTouchIcon' => null, 'themeColor' => null],
            'clickEvents' => false,
            'code' => [],
        ];
    }

    public function testGetSiteReadsTheEndpointAndIsCachedUnderTheSiteTag(): void
    {
        $http = (new FakeTransport())->always(FakeTransport::json(self::contract()));
        $client = new ContentClient(['token' => 'fsc_pub_x', 'baseUrl' => 'https://content.test', 'transport' => $http]);

        self::assertSame('Loodgieter Jansen', $client->getSite()['business']['name']);
        self::assertSame('https://content.test/api/v1/site', $http->last()->url);
        self::assertSame(['content:site'], Tags::forEvent(['type' => 'site.updated', 'data' => []]));

        $wrapped = (new FakeTransport())->always(FakeTransport::json(['site' => self::contract()]));
        $client = new ContentClient(['token' => 'fsc_srv_x', 'baseUrl' => 'https://content.test', 'transport' => $wrapped]);
        self::assertSame(true, $client->getSite()['clickEvents'], 'a `{site: …}` wrapper is unwrapped too');
    }

    public function testHeadRendersVerificationIconsJsonLdAndTheCodeInOrder(): void
    {
        $html = Site::head(self::contract(), ['nonce' => 'n0nce']);

        self::assertStringContainsString('<meta name="google-site-verification" content="g00gle-Token_1">', $html);
        self::assertStringContainsString('<meta name="msvalidate.01" content="B1NG">', $html);
        self::assertStringContainsString('<meta name="p:domain_verify" content="pin123">', $html);
        self::assertStringNotContainsString('facebook-domain-verification', $html);
        self::assertStringContainsString('<link rel="icon" href="https://content.test/api/media/fav" type="image/png">', $html);
        self::assertStringContainsString('<link rel="apple-touch-icon" href="https://content.test/api/media/touch">', $html);
        self::assertStringContainsString('<meta name="theme-color" content="#0a7f3c">', $html);
        self::assertStringContainsString('<script type="application/ld+json" nonce="n0nce">{"@context":"https://schema.org","@type":"Plumber"', $html);
        // The necessary snippet is live, the analytics one inert, and the body snippet is not in the head.
        self::assertStringContainsString('<script src="https://cdn.banner.test/b.js" nonce="n0nce"></script><style nonce="n0nce">.x{}</style>', $html);
        self::assertStringContainsString('<template data-fsc-code data-consent="analytics" data-name="Google Analytics"><script async src="https://www.googletagmanager.com/gtag/js?id=G-1" nonce="n0nce"></script><script nonce="n0nce">window.dataLayer=[];</script></template>', $html);
        self::assertStringNotContainsString('facebook.com/tr', $html);
        self::assertStringContainsString('w.fscCode', $html, 'the head has a gated snippet, so it carries the loader');
        self::assertStringContainsString('"key":"fsc-consent","event":"fsc:consent"', $html);
    }

    public function testBodyEndRendersBodyCodeTheLoaderAndClickEventsOnlyWithAnalytics(): void
    {
        $html = Site::bodyEnd(self::contract(), ['nonce' => 'n0nce', 'clickEvents' => true, 'consentKey' => 'cookie-choice', 'consentEvent' => 'banner:saved']);

        self::assertStringContainsString('<template data-fsc-code data-consent="marketing" data-name="Pixel"><noscript><img src="https://www.facebook.com/tr?id=1"></noscript><iframe src="https://player.vimeo.com/x"></iframe></template>', $html);
        self::assertStringContainsString('"key":"cookie-choice","event":"banner:saved"', $html);
        self::assertStringContainsString("u.track('contact-click'", $html);
        self::assertStringNotContainsString('googletagmanager', $html);

        self::assertStringNotContainsString('contact-click', Site::bodyEnd(self::contract(), ['clickEvents' => false]), 'no analytics module = no listener');
        self::assertStringNotContainsString('contact-click', Site::bodyEnd(self::contract(['clickEvents' => false]), ['clickEvents' => true]));
    }

    public function testEveryEmittedScriptAndStyleCarriesTheNonce(): void
    {
        $html = Site::head(self::contract(), ['nonce' => 'n0nce']) . Site::bodyEnd(self::contract(), ['nonce' => 'n0nce', 'clickEvents' => true]);

        preg_match_all('/<(script|style)\b[^>]*>/i', $html, $tags);
        self::assertGreaterThanOrEqual(7, count($tags[0]));

        foreach ($tags[0] as $tag) {
            self::assertStringContainsString('nonce="n0nce"', $tag, $tag);
            self::assertSame(1, substr_count($tag, 'nonce='), $tag);
        }
    }

    public function testNonceIsAddedToOpeningTagsOnlyAndReplacesAPastedNonce(): void
    {
        self::assertSame(
            '<script nonce="n"> document.write("<script src=\'x\'></script>")',
            Site::withNonce('<script nonce="old"> document.write("<script src=\'x\'></script>")', 'n'),
            'the text of a script is never touched, so quotes inside JavaScript stay valid',
        );
        self::assertSame('<script type="module" data-x="a>b" nonce="n"></script><p>hi</p>', Site::withNonce('<script type="module" data-x="a>b"></script><p>hi</p>', 'n'));
        self::assertSame('<STYLE nonce="n">p{}</STYLE>', Site::withNonce('<STYLE>p{}</STYLE>', 'n'));
        self::assertSame('<script></script>', Site::withNonce('<script></script>', null));
        self::assertSame('<script nonce="&quot;&gt;"></script>', Site::withNonce('<script></script>', '">'));
    }

    public function testJsonLdEscapesEverythingThatCouldEndTheScriptBlock(): void
    {
        $business = self::contract()['business'];
        $business['name'] = 'Jansen</script><script>alert("x")</script> & \'Zn\'';
        $html = Site::head(['business' => $business], ['nonce' => 'n']);

        self::assertSame(1, substr_count(strtolower($html), '</script>'), 'only the closing tag of the JSON-LD block');
        self::assertStringContainsString(<<<'JSON'
            "name":"Jansen\u003C/script\u003E\u003Cscript\u003Ealert(\u0022x\u0022)\u003C/script\u003E \u0026 \u0027Zn\u0027"
            JSON, $html);

        $data = json_decode((string) Site::jsonLd($business), true);
        self::assertSame($business['name'], $data['name'], 'the escapes decode to the original text');
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => 'Straat 1', 'postalCode' => '2511 AB', 'addressLocality' => 'Den Haag', 'addressCountry' => 'NL'], $data['address']);
        self::assertSame(['@type' => 'GeoCoordinates', 'latitude' => 52.07, 'longitude' => 4.3], $data['geo']);
        self::assertSame([['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '08:00', 'closes' => '17:00']], $data['openingHoursSpecification']);
        self::assertSame([['@type' => 'Place', 'name' => 'Den Haag'], ['@type' => 'Place', 'name' => 'Rijswijk']], $data['areaServed']);
        self::assertSame('€€', $data['priceRange']);
        self::assertSame(['https://www.facebook.com/jansen'], $data['sameAs']);
        self::assertArrayNotHasKey('image', $data, 'nulls are left out');
        self::assertStringContainsString('€€', (string) Site::jsonLd($business), 'unicode stays readable');
    }

    public function testInvalidValuesFromContentAreDropped(): void
    {
        $html = Site::head([
            'verification' => ['google' => '"><script>x</script>'],
            'social' => ['favicon' => ['url' => 'javascript:alert(1)'], 'appleTouchIcon' => 'data:x', 'themeColor' => 'red;x'],
            'business' => ['type' => 'Evil"Type', 'name' => 'Jansen', 'url' => 'javascript:x', 'openingHours' => [['days' => ['Xx'], 'opens' => '8', 'closes' => '17:00']]],
        ]);

        self::assertStringNotContainsString('google-site-verification', $html);
        self::assertStringNotContainsString('rel="icon"', $html);
        self::assertStringNotContainsString('apple-touch-icon', $html);
        self::assertStringNotContainsString('theme-color', $html);
        self::assertStringContainsString('"@type":"LocalBusiness","name":"Jansen"}', $html);
    }

    public function testSnippetsWithAnUnknownConsentOrAnEarlyTemplateEndStayGated(): void
    {
        $site = ['code' => [
            ['placement' => 'head', 'consent' => 'whatever', 'html' => '<script>a()</script>'],
            ['placement' => 'head', 'consent' => 'marketing', 'html' => '<div></template><script>b()</script>'],
            ['placement' => 'head', 'consent' => 'necessary', 'html' => '   '],
        ]];
        $html = Site::head($site);

        self::assertStringContainsString('<template data-fsc-code data-consent="marketing"><script>a()</script></template>', $html, 'unknown consent is never treated as necessary');
        self::assertStringNotContainsString('b()', $html);
    }

    public function testTheEmptyShapeRendersNothing(): void
    {
        self::assertSame('', Site::head(self::emptyShape(), ['nonce' => 'n']));
        self::assertSame('', Site::bodyEnd(self::emptyShape(), ['nonce' => 'n', 'clickEvents' => true]));
        self::assertSame([], Site::csp(self::emptyShape()));
        self::assertTrue(Site::isEmpty(self::emptyShape()));
        self::assertTrue(Site::isEmpty(null));
        self::assertFalse(Site::isEmpty(self::contract()));
    }

    public function testOnlyNecessaryCodeNeedsNoLoader(): void
    {
        $site = ['code' => [['placement' => 'body', 'consent' => 'necessary', 'html' => '<script>a()</script>']]];

        self::assertSame('<script nonce="n">a()</script>', Site::bodyEnd($site, ['nonce' => 'n']));
    }

    public function testCspHostsComeFromTheSnippets(): void
    {
        $site = self::contract();
        $site['code'][] = ['placement' => 'head', 'consent' => 'analytics', 'html' => "<script>(function(w,d){var j=d.createElement('script');j.src='https://www.googletagmanager.com/gtm.js?id='+i;})(window,document);</script>"
            . '<link rel="stylesheet" href="//fonts.example.com/css"><link rel="preconnect" href="https://api.example.com"><script src="/local.js"></script>'];

        self::assertSame([
            'script-src' => ['https://cdn.banner.test', 'https://www.googletagmanager.com'],
            'connect-src' => ['https://cdn.banner.test', 'https://www.googletagmanager.com', 'https://api.example.com'],
            'img-src' => ['https://www.facebook.com'],
            'frame-src' => ['https://player.vimeo.com'],
            'style-src' => ['https://fonts.example.com'],
        ], Site::csp($site));
    }

    public function testMergeCspKeepsTheFallbackSemanticsOfThePolicy(): void
    {
        $sources = ['script-src' => ['https://a.test'], 'img-src' => ['https://b.test'], 'frame-src' => ['https://c.test']];

        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-x' https://a.test; img-src 'self' https://b.test; frame-src 'self' https://c.test",
            Site::mergeCsp("default-src 'self'; script-src 'self' 'nonce-x'", $sources),
            'a missing directive starts from default-src',
        );
        self::assertSame(
            "script-src 'self' https://a.test",
            Site::mergeCsp("script-src 'self'", $sources),
            'without default-src a missing directive stays missing (adding it would forbid everything else)',
        );
        self::assertSame(
            ['default-src' => ["'none'"], 'script-src' => ['https://a.test'], 'img-src' => ['https://b.test'], 'frame-src' => ['https://c.test']],
            Site::mergeCsp(['default-src' => "'none'", 'script-src' => ["'none'"]], $sources),
            "'none' goes once a source is added; arrays stay arrays",
        );
        self::assertSame("default-src 'self'", Site::mergeCsp("default-src 'self'", []));
    }
}

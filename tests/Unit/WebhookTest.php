<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\Webhooks\Events;
use FullStackStudio\Content\Webhooks\VerifyResult;
use FullStackStudio\Content\Webhooks\WebhookVerifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    // Vector generated with `signWebhookPayload` from utils/webhook-signature.ts of the Content server
    // (secret "fsc_whsec_testvector", t = 1760000000), same as packages/content-sdk/test/webhooks.test.ts.
    private const SECRET = 'fsc_whsec_testvector';
    private const T = 1760000000;
    private const BODY = '{"id":"7c0b1c6e-0a63-4d7f-9a6b-3b3f0c1c9a11","type":"article.updated","createdAt":"2026-10-03T10:15:00.000Z","projectId":"b1f0c2d4-0000-4000-8000-000000000001","data":{"id":"11111111-1111-4111-8111-111111111111","slug":"hallo","locale":"nl","changed":["title"],"previousSlug":"hoi"}}';
    private const SERVER_SIGNATURE = 't=1760000000,v1=1c3551755c698b11335042481667cf1d3ed6cc684998b0746596dd6ae7f514e4';
    private const EMPTY_BODY_SIGNATURE = 't=1760000000,v1=fd36afeace2b4a940b6cd19ed37ec980d484084115a19f428de4c93d826b6fa9';

    public function testSignMatchesTheServerVector(): void
    {
        self::assertSame(self::SERVER_SIGNATURE, WebhookVerifier::sign(self::SECRET, self::BODY, self::T));
        self::assertSame(self::EMPTY_BODY_SIGNATURE, WebhookVerifier::sign(self::SECRET, '{}', self::T));
    }

    public function testSignRejectsAnInvalidTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebhookVerifier::sign(self::SECRET, self::BODY, 0);
    }

    public function testAcceptsTheServersSignatureAndReturnsTheEvent(): void
    {
        $result = WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, self::SECRET, 300, self::T + 10);

        self::assertTrue($result->ok);
        self::assertSame('article.updated', $result->event['type']);
        self::assertSame('hallo', $result->event['data']['slug']);
        self::assertSame('hoi', $result->event['data']['previousSlug']);
        self::assertSame(['title'], $result->event['data']['changed']);
    }

    public function testRejectsWrongSecretAndTamperedBodyOrTimestamp(): void
    {
        self::assertSame(VerifyResult::MISMATCH, WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, 'fsc_whsec_other', 300, self::T)->reason);
        self::assertFalse(WebhookVerifier::verify(str_replace('hallo', 'hacked', self::BODY), self::SERVER_SIGNATURE, self::SECRET, 300, self::T)->ok);

        $reserialized = json_encode(json_decode(self::BODY, true), JSON_PRETTY_PRINT);
        self::assertFalse(WebhookVerifier::verify($reserialized, self::SERVER_SIGNATURE, self::SECRET, 300, self::T)->ok);

        $hash = explode('v1=', self::SERVER_SIGNATURE)[1];
        self::assertSame(VerifyResult::MISMATCH, WebhookVerifier::verify(self::BODY, 't=' . (self::T + 1) . ',v1=' . $hash, self::SECRET, 300, self::T)->reason);
    }

    public function testToleranceWindow(): void
    {
        self::assertSame(VerifyResult::EXPIRED, WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, self::SECRET, 300, self::T + 301)->reason);
        self::assertSame(VerifyResult::EXPIRED, WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, self::SECRET, 300, self::T - 301)->reason);
        self::assertTrue(WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, self::SECRET, 300, self::T + 300)->ok);
        self::assertFalse(WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, self::SECRET, 30, self::T + 60)->ok);
        self::assertTrue(WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, self::SECRET, 120, self::T + 60)->ok);
    }

    public function testTriesEveryV1DuringARotation(): void
    {
        $hash = explode('v1=', self::SERVER_SIGNATURE)[1];
        $both = 't=' . self::T . ',v1=' . str_repeat('0', 64) . ',v1=' . $hash;

        self::assertTrue(WebhookVerifier::verify(self::BODY, $both, self::SECRET, 300, self::T)->ok);
    }

    public function testMissingMalformedAndEmptyInput(): void
    {
        self::assertSame(VerifyResult::MISSING_SIGNATURE, WebhookVerifier::verify(self::BODY, null, self::SECRET, 300, self::T)->reason);
        self::assertSame(VerifyResult::MISSING_SECRET, WebhookVerifier::verify(self::BODY, self::SERVER_SIGNATURE, '', 300, self::T)->reason);

        foreach (['', 'garbage', 't=abc,v1=' . str_repeat('a', 64), 't=' . self::T, 't=' . self::T . ',v1=zz', 'v1=' . str_repeat('a', 64), 't=' . self::T . ',v1=' . str_repeat('a', 63), 't=' . self::T . ",v1=" . str_repeat('A', 64)] as $bad) {
            self::assertFalse(WebhookVerifier::verify(self::BODY, $bad, self::SECRET, 300, self::T)->ok, $bad);
        }

        self::assertSame(VerifyResult::MALFORMED, WebhookVerifier::verify(self::BODY, 'garbage', self::SECRET, 300, self::T)->reason);
    }

    public function testCorrectlySignedButNotAnEventIsInvalidPayload(): void
    {
        foreach (['{}', 'not json', '[]', '{"id":"a","type":"b","createdAt":"c","projectId":"d","data":[]}', '{"id":"a","type":"b","createdAt":"c","projectId":"d","data":"x"}'] as $body) {
            $result = WebhookVerifier::verify($body, WebhookVerifier::sign(self::SECRET, $body, self::T), self::SECRET, 300, self::T);
            self::assertFalse($result->ok, $body);
            self::assertSame(VerifyResult::INVALID_PAYLOAD, $result->reason, $body);
        }
    }

    public function testUsesTheCurrentTimeByDefault(): void
    {
        self::assertTrue(WebhookVerifier::verify(self::BODY, WebhookVerifier::sign(self::SECRET, self::BODY), self::SECRET)->ok);
    }

    public function testEventListEqualsTheJsSdk(): void
    {
        self::assertContains('article.updated', Events::ALL);
        self::assertContains('webhook.test', Events::ALL);
        self::assertSame(count(Events::ALL), count(array_unique(Events::ALL)));

        $js = dirname(__DIR__, 3) . '/content-sdk/src/webhooks.ts';

        if (!is_file($js)) {
            self::markTestSkipped('The JS SDK is not next to this package (subtree split).');
        }

        preg_match_all('/^    "([a-z_.]+)",$/m', (string) file_get_contents($js), $found);
        self::assertSame($found[1], Events::ALL);
    }
}

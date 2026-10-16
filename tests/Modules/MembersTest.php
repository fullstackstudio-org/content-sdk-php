<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Modules;

use FullStackStudio\Content\ContentClient;
use FullStackStudio\Content\ContentException;
use FullStackStudio\Content\Tests\Support\FakeTransport;
use FullStackStudio\Content\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MembersTest extends ModuleTestCase
{
    /** @return iterable<string, array{0: callable(ContentClient): mixed, 1: string, 2: string, 3: array<string, string>, 4: array<string, mixed>|null, 5: string|null}> */
    public static function calls(): iterable
    {
        // The last element is the member session that must travel in X-FSC-Member-Session (null = none).
        yield 'login' => [fn (ContentClient $c) => $c->members->login(['email' => 'jan@example.nl', 'password' => 'geheim', 'ignored' => 'x']), 'POST', '/members/login', [], ['email' => 'jan@example.nl', 'password' => 'geheim'], null];
        yield 'loginTotp code' => [fn (ContentClient $c) => $c->members->loginTotp(['challengeToken' => 'ch', 'code' => '123456']), 'POST', '/members/login/totp', [], ['challengeToken' => 'ch', 'code' => '123456'], null];
        yield 'loginTotp recovery' => [fn (ContentClient $c) => $c->members->loginTotp(['challengeToken' => 'ch', 'recoveryCode' => 'abcd-efgh']), 'POST', '/members/login/totp', [], ['challengeToken' => 'ch', 'recoveryCode' => 'abcd-efgh'], null];
        yield 'getTotpStatus' => [fn (ContentClient $c) => $c->members->getTotpStatus(['session' => 'S']), 'GET', '/members/me/totp', [], null, 'S'];
        yield 'setupTotp session' => [fn (ContentClient $c) => $c->members->setupTotp(['session' => 'S', 'password' => 'pw']), 'POST', '/members/me/totp/setup', [], ['password' => 'pw'], 'S'];
        yield 'setupTotp token' => [fn (ContentClient $c) => $c->members->setupTotp(['setupToken' => 'st']), 'POST', '/members/totp/setup', [], ['setupToken' => 'st'], null];
        yield 'confirmTotp session' => [fn (ContentClient $c) => $c->members->confirmTotp(['session' => 'S', 'code' => '654321']), 'POST', '/members/me/totp/confirm', [], ['code' => '654321'], 'S'];
        yield 'confirmTotp token' => [fn (ContentClient $c) => $c->members->confirmTotp(['setupToken' => 'st', 'code' => '654321']), 'POST', '/members/totp/confirm', [], ['setupToken' => 'st', 'code' => '654321'], null];
        yield 'disableTotp' => [fn (ContentClient $c) => $c->members->disableTotp(['session' => 'S', 'code' => '111111']), 'POST', '/members/me/totp/disable', [], ['code' => '111111'], 'S'];
        yield 'disableTotp recovery' => [fn (ContentClient $c) => $c->members->disableTotp(['session' => 'S', 'recoveryCode' => 'rc']), 'POST', '/members/me/totp/disable', [], ['recoveryCode' => 'rc'], 'S'];
        yield 'regenerateRecoveryCodes' => [fn (ContentClient $c) => $c->members->regenerateRecoveryCodes(['session' => 'S', 'code' => '222222']), 'POST', '/members/me/totp/recovery-codes', [], ['code' => '222222'], 'S'];

        yield 'register' => [fn (ContentClient $c) => $c->members->register(['email' => 'a@b.nl', 'password' => 'pw', 'name' => 'Jan']), 'POST', '/members/register', [], ['email' => 'a@b.nl', 'password' => 'pw', 'name' => 'Jan'], null];
        yield 'register (no name)' => [fn (ContentClient $c) => $c->members->register(['email' => 'a@b.nl', 'password' => 'pw']), 'POST', '/members/register', [], ['email' => 'a@b.nl', 'password' => 'pw'], null];
        yield 'verifyEmail' => [fn (ContentClient $c) => $c->members->verifyEmail(['token' => 't', 'password' => 'pw']), 'POST', '/members/verify-email', [], ['token' => 't', 'password' => 'pw'], null];
        yield 'requestVerification' => [fn (ContentClient $c) => $c->members->requestVerification(['email' => 'a@b.nl']), 'POST', '/members/verify-email/request', [], ['email' => 'a@b.nl'], null];
        yield 'me' => [fn (ContentClient $c) => $c->members->me(['session' => 'S']), 'GET', '/members/me', [], null, 'S'];
        yield 'updateMe' => [fn (ContentClient $c) => $c->members->updateMe(['session' => 'S', 'name' => 'Piet']), 'PATCH', '/members/me', [], ['name' => 'Piet'], 'S'];
        yield 'changePassword' => [fn (ContentClient $c) => $c->members->changePassword(['session' => 'S', 'currentPassword' => 'old', 'newPassword' => 'new']), 'POST', '/members/me/password', [], ['currentPassword' => 'old', 'newPassword' => 'new'], 'S'];
        yield 'refreshSession' => [fn (ContentClient $c) => $c->members->refreshSession(['session' => 'S']), 'POST', '/members/session/refresh', [], null, 'S'];
        yield 'logout' => [fn (ContentClient $c) => $c->members->logout(['session' => 'S']), 'POST', '/members/logout', [], null, 'S'];
        yield 'logoutAll' => [fn (ContentClient $c) => $c->members->logoutAll(['session' => 'S']), 'POST', '/members/logout-all', [], null, 'S'];
        yield 'requestPasswordReset' => [fn (ContentClient $c) => $c->members->requestPasswordReset(['email' => 'a@b.nl']), 'POST', '/members/password-reset/request', [], ['email' => 'a@b.nl'], null];
        yield 'confirmPasswordReset' => [fn (ContentClient $c) => $c->members->confirmPasswordReset(['token' => 't', 'password' => 'pw']), 'POST', '/members/password-reset/confirm', [], ['token' => 't', 'password' => 'pw'], null];

        yield 'list' => [fn (ContentClient $c) => $c->members->list(['q' => 'jan', 'status' => 'blocked', 'page' => 2, 'limit' => 5]), 'GET', '/members', ['q' => 'jan', 'status' => 'blocked', 'page' => '2', 'limit' => '5'], null, null];
        yield 'get' => [fn (ContentClient $c) => $c->members->get('m1'), 'GET', '/members/m1', [], null, null];
        yield 'updateName' => [fn (ContentClient $c) => $c->members->updateName('m1', ['name' => null]), 'PATCH', '/members/m1', [], ['name' => null], null];
        yield 'delete' => [fn (ContentClient $c) => $c->members->delete('m1'), 'DELETE', '/members/m1', [], null, null];
        yield 'block' => [fn (ContentClient $c) => $c->members->block('m1'), 'POST', '/members/m1/block', [], null, null];
        yield 'unblock' => [fn (ContentClient $c) => $c->members->unblock('m1'), 'POST', '/members/m1/unblock', [], null, null];
        yield 'revokeSessions' => [fn (ContentClient $c) => $c->members->revokeSessions('m1'), 'POST', '/members/m1/revoke-sessions', [], null, null];
        yield 'sendReset' => [fn (ContentClient $c) => $c->members->sendReset('m1'), 'POST', '/members/m1/send-reset', [], null, null];
        yield 'resetTotp' => [fn (ContentClient $c) => $c->members->resetTotp('m1'), 'POST', '/members/m1/reset-totp', [], null, null];
    }

    /** @param callable(ContentClient): mixed $call @param array<string, string> $query @param array<string, mixed>|null $body */
    #[DataProvider('calls')]
    public function testRequestShape(callable $call, string $method, string $path, array $query, ?array $body, ?string $session): void
    {
        $this->runCall($call, $method, $path, $query, $body);

        $headers = $this->http->last()->headers;

        if ($session === null) {
            self::assertArrayNotHasKey('X-FSC-Member-Session', $headers);
        } else {
            self::assertSame($session, $headers['X-FSC-Member-Session']);
            self::assertStringNotContainsString($session, $this->http->last()->url);
            self::assertStringNotContainsString('session', strtolower($this->http->last()->body ?? ''), 'the session is a header, never part of the body');
        }
    }

    public function testTwoFactorLoginFlow(): void
    {
        $this->http->queue(
            FakeTransport::json(['status' => 'totp_required', 'challengeToken' => 'ch-1']),
            FakeTransport::json(['member' => ['id' => 'm1'], 'session' => ['token' => 'SESSION', 'expiresAt' => '2030-01-01T00:00:00Z']]),
        );
        $members = $this->client()->members;

        $first = $members->login(['email' => 'jan@example.nl', 'password' => 'geheim'], ['clientIp' => '203.0.113.5']);
        self::assertSame('ch-1', $first['challengeToken']);
        self::assertSame('203.0.113.5', $this->http->last()->headers['X-FSC-Client-Ip']);

        $second = $members->loginTotp(['challengeToken' => $first['challengeToken'], 'code' => '123456']);
        self::assertSame('SESSION', $second['session']['token']);
        $this->assertRequest('POST', '/members/login/totp', [], ['challengeToken' => 'ch-1', 'code' => '123456']);
    }

    public function testRecoveryCodeWinsOverCodeAndSessionNeverLeaksIntoTheBody(): void
    {
        $this->http->always(FakeTransport::json(['recoveryCodes' => []]));
        $this->client()->members->regenerateRecoveryCodes(['session' => 'S', 'code' => '111111', 'recoveryCode' => 'rc']);

        self::assertSame(['recoveryCode' => 'rc'], $this->http->lastBody());
    }

    public function testClientIpIsForwardedOnlyWithAServerToken(): void
    {
        $this->http->always(FakeTransport::json([]));

        $this->client(self::PUB)->members->login(['email' => 'a@b.nl', 'password' => 'x'], ['clientIp' => '203.0.113.5']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);

        $this->client()->members->login(['email' => 'a@b.nl', 'password' => 'x'], ['clientIp' => 'not an ip']);
        self::assertArrayNotHasKey('X-FSC-Client-Ip', $this->http->last()->headers);

        $this->client(self::SERVER, ['clientIp' => '192.0.2.1'])->members->login(['email' => 'a@b.nl', 'password' => 'x']);
        self::assertSame('192.0.2.1', $this->http->last()->headers['X-FSC-Client-Ip']);
    }

    public function testAMemberGetterUnwrapsAndErrorsBecomeExceptions(): void
    {
        $this->http->always(FakeTransport::json(['member' => ['id' => 'm1']]));
        self::assertSame(['id' => 'm1'], $this->client()->members->get('m1'));

        $this->http->always(FakeTransport::json(['error' => ['code' => 'invalid_credentials', 'message' => 'Wrong.']], 401));

        try {
            $this->client()->members->login(['email' => 'a@b.nl', 'password' => 'x']);
            self::fail('expected exception');
        } catch (ContentException $error) {
            self::assertSame(401, $error->status());
            self::assertSame('invalid_credentials', $error->errorCode());
        }
    }
}

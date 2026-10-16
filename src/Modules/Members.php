<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\Arr;
use FullStackStudio\Content\Internal\ModuleContext;

/**
 * Member accounts (`$client->members`; module `members`): login with two-factor authentication, the account flows and the member admin.
 * SERVER TOKEN ONLY: call these from your site's server, never from a browser. `session` is the member's session token
 * (`X-FSC-Member-Session`, kept by your site in an httpOnly cookie, never in a URL); `clientIp` the visitor's IP for the per-visitor limits.
 *
 * Errors: `invalid_credentials` (401), `invalid_member_session` (401), `member_token_invalid` (400), `account_locked` / `rate_limited`
 * (429, `retryAfter`), `conflict` (409).
 */
final class Members
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $options
     */
    private function call(string $method, string $path, ?array $body, ?string $session, array $options): mixed
    {
        return $this->ctx->execute([
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'headers' => $this->ctx->visitorHeaders($session, $options['clientIp'] ?? null),
            'options' => $options,
        ])->data;
    }

    /** Only the second-factor fields (never `session`): `code` or `recoveryCode`. @param array<string, mixed> $input @return array<string, mixed> */
    private static function factor(array $input): array
    {
        return array_key_exists('recoveryCode', $input) ? ['recoveryCode' => $input['recoveryCode']] : ['code' => $input['code'] ?? null];
    }

    // --- Login and two-factor authentication (`members:auth`) -----------------------------------------------------------------

    /**
     * `POST /members/login`. With two-factor authentication there is no session yet: the result then holds a `challengeToken` (or a `setupToken`).
     *
     * @param array{email: string, password: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function login(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/login', ['email' => $input['email'] ?? null, 'password' => $input['password'] ?? null], null, $options);
    }

    /**
     * The second step: `challengeToken` from `login` plus `code` or `recoveryCode`.
     *
     * @param array{challengeToken: string, code?: string, recoveryCode?: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function loginTotp(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/login/totp', ['challengeToken' => $input['challengeToken'] ?? null, ...self::factor($input)], null, $options);
    }

    /** The two-factor state of the logged-in member. @param array{session: string} $input @param array<string, mixed> $options @return array<string, mixed> */
    public function getTotpStatus(array $input, array $options = []): array
    {
        return $this->call('GET', '/members/me/totp', null, $input['session'] ?? null, $options);
    }

    /**
     * Starts linking an authenticator app: with a `session` (the current `password` is required) or with the `setupToken` from `login`
     * (`totp_setup_required`). Nothing is active until `confirmTotp`.
     *
     * @param array{session?: string, password?: string, setupToken?: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function setupTotp(array $input, array $options = []): array
    {
        if (array_key_exists('setupToken', $input)) {
            return $this->call('POST', '/members/totp/setup', ['setupToken' => $input['setupToken']], null, $options);
        }

        return $this->call('POST', '/members/me/totp/setup', ['password' => $input['password'] ?? null], $input['session'] ?? null, $options);
    }

    /**
     * Activates the link with a `code` from the app. Returns 10 recovery codes (only in this response). With a `session`: the member's OTHER
     * sessions end. With a `setupToken`: also logs the member in.
     *
     * @param array{session?: string, setupToken?: string, code: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function confirmTotp(array $input, array $options = []): array
    {
        if (array_key_exists('setupToken', $input)) {
            return $this->call('POST', '/members/totp/confirm', ['setupToken' => $input['setupToken'], 'code' => $input['code'] ?? null], null, $options);
        }

        return $this->call('POST', '/members/me/totp/confirm', ['code' => $input['code'] ?? null], $input['session'] ?? null, $options);
    }

    /**
     * Turns two-factor authentication off (`code` or `recoveryCode`). ALL sessions end, including this one.
     *
     * @param array{session: string, code?: string, recoveryCode?: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array{disabled: true, revokedSessions: int}
     */
    public function disableTotp(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/me/totp/disable', self::factor($input), $input['session'] ?? null, $options);
    }

    /**
     * New recovery codes (`code` or `recoveryCode`); the old ones stop working.
     *
     * @param array{session: string, code?: string, recoveryCode?: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array{recoveryCodes: list<string>}
     */
    public function regenerateRecoveryCodes(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/me/totp/recovery-codes', self::factor($input), $input['session'] ?? null, $options);
    }

    // --- Account flows (`members:auth`) ---------------------------------------------------------------------------------------

    /**
     * Always `['accepted' => true]`, also for an existing address; never a session. With `verificationRequired` the member must confirm the mail first.
     *
     * @param array{email: string, password: string, name?: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function register(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/register', Arr::pick($input, ['email', 'password', 'name']), null, $options);
    }

    /**
     * Confirm an address with the token from the mail (a POST after a click, never on page load). The password of the registration is required.
     *
     * @param array{token: string, password?: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array<string, mixed>
     */
    public function verifyEmail(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/verify-email', Arr::pick($input, ['token', 'password']), null, $options);
    }

    /** Send the verification mail again (always `['accepted' => true]`). @param array{email: string} $input @param array<string, mixed> $options @return array{accepted: true} */
    public function requestVerification(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/verify-email/request', ['email' => $input['email'] ?? null], null, $options);
    }

    /** The logged-in member, their company (or `null`) and the session's expiry. @param array{session: string} $input @param array<string, mixed> $options @return array<string, mixed> */
    public function me(array $input, array $options = []): array
    {
        return $this->call('GET', '/members/me', null, $input['session'] ?? null, $options);
    }

    /** `name` may be `null` to clear it. @param array{session: string, name: string|null} $input @param array<string, mixed> $options @return array{member: array<string, mixed>, changed: bool} */
    public function updateMe(array $input, array $options = []): array
    {
        return $this->call('PATCH', '/members/me', ['name' => $input['name'] ?? null], $input['session'] ?? null, $options);
    }

    /**
     * Changes the password; the member's other sessions end.
     *
     * @param array{session: string, currentPassword: string, newPassword: string} $input
     * @param array{clientIp?: string, timeoutMs?: int} $options
     * @return array{changed: true, revokedSessions: int}
     */
    public function changePassword(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/me/password', ['currentPassword' => $input['currentPassword'] ?? null, 'newPassword' => $input['newPassword'] ?? null], $input['session'] ?? null, $options);
    }

    /** Extends the session (never past its absolute end). @param array{session: string} $input @param array<string, mixed> $options @return array{session: array{expiresAt: string, idleExpiresAt: string}} */
    public function refreshSession(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/session/refresh', null, $input['session'] ?? null, $options);
    }

    /** @param array{session: string} $input @param array<string, mixed> $options @return array{loggedOut: true} */
    public function logout(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/logout', null, $input['session'] ?? null, $options);
    }

    /** @param array{session: string} $input @param array<string, mixed> $options @return array{revokedSessions: int} */
    public function logoutAll(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/logout-all', null, $input['session'] ?? null, $options);
    }

    /** Always `['accepted' => true]`. @param array{email: string} $input @param array{clientIp?: string, timeoutMs?: int} $options @return array{accepted: true} */
    public function requestPasswordReset(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/password-reset/request', ['email' => $input['email'] ?? null], null, $options);
    }

    /** The link from the mail is valid for 30 minutes and works once; all sessions end. @param array{token: string, password: string} $input @param array{clientIp?: string, timeoutMs?: int} $options @return array{reset: true, memberId: string} */
    public function confirmPasswordReset(array $input, array $options = []): array
    {
        return $this->call('POST', '/members/password-reset/confirm', ['token' => $input['token'] ?? null, 'password' => $input['password'] ?? null], null, $options);
    }

    // --- Admin (`members:write`) ----------------------------------------------------------------------------------------------

    /**
     * @param array{q?: string, status?: string, page?: int, limit?: int} $params
     * @param array<string, mixed> $options
     * @return array<string, mixed> `members`, `page`, `limit`, `total`
     */
    public function list(array $params = [], array $options = []): array
    {
        return $this->ctx->withTotal($this->ctx->execute(['path' => '/members', 'query' => Arr::pick($params, ['q', 'status', 'page', 'limit']), 'options' => $options]));
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function get(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/members/' . ModuleContext::seg($id), 'options' => $options])->data['member'] ?? [];
    }

    /** @param array{name: string|null} $input @param array<string, mixed> $options @return array{member: array<string, mixed>, changed: bool} */
    public function updateName(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/members/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function delete(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/members/' . ModuleContext::seg($id), null, $options);
    }

    /** Blocking ends all of the member's sessions. @param array<string, mixed> $options @return array<string, mixed> */
    public function block(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/members/' . ModuleContext::seg($id) . '/block', null, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function unblock(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/members/' . ModuleContext::seg($id) . '/unblock', null, $options);
    }

    /** @param array<string, mixed> $options @return array{revokedSessions: int} */
    public function revokeSessions(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/members/' . ModuleContext::seg($id) . '/revoke-sessions', null, $options);
    }

    /** Sends the member a password reset mail. @param array<string, mixed> $options @return array{sent: true} */
    public function sendReset(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/members/' . ModuleContext::seg($id) . '/send-reset', null, $options);
    }

    /** Resets two-factor authentication (all sessions end). @param array<string, mixed> $options @return array<string, mixed> */
    public function resetTotp(string $id, array $options = []): array
    {
        return $this->ctx->write('POST', '/members/' . ModuleContext::seg($id) . '/reset-totp', null, $options);
    }
}

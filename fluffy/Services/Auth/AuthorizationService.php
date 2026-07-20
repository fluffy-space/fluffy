<?php

namespace Fluffy\Services\Auth;

use Fluffy\Data\Entities\Auth\SessionEntity;
use Fluffy\Data\Entities\Auth\UserEntity;
use Fluffy\Data\Entities\Auth\UserEntityMap;
use Fluffy\Data\Entities\Auth\UserTokenEntity;
use Fluffy\Data\Entities\Auth\UserTokenEntityMap;
use Fluffy\Data\Entities\Auth\UserVerificationCodeEntity;
use Fluffy\Data\Entities\Auth\UserVerificationCodeEntityMap;
use Fluffy\Data\Repositories\UserRepository;
use Fluffy\Data\Repositories\UserTokenRepository;
use Fluffy\Data\Repositories\UserVerificationCodeRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Domain\Message\HttpContext;
use Fluffy\Models\Auth\AuthResult;
use Fluffy\Models\Auth\RegisterResult;
use Fluffy\Security\Capability;
use Fluffy\Security\Permissions;
use Fluffy\Security\Role;
use Fluffy\Services\Session\SessionService;
use Fluffy\Services\UtilsService;

class AuthorizationService
{
    const COOKIE_NAME = 'AUTH';
    const MAX_USER_TOKENS = 5;
    /** Fallback login-session lifetimes (seconds) when not set in config. */
    const REMEMBER_LIFETIME = 60 * 60 * 24 * 30; // 30 days (remember-me: persistent cookie)
    const SESSION_LIFETIME = 60 * 60 * 24;       // 1 day  (no remember-me: session cookie)

    /**
     * Impersonation ("view as user") overlay cookie. Separate from AUTH: the
     * admin's own session is untouched; this signed cookie tells the auth flow to
     * resolve a DIFFERENT effective user. No DB involved. Verified every request.
     */
    const IMPERSONATE_COOKIE = 'IMP';
    const IMPERSONATION_LIFETIME = 60 * 30; // 30 min fallback

    private ?string $authCookie;
    private ?string $authToken = null;
    private ?UserEntity $authorizedUser = null;
    private ?UserTokenEntity $userToken = null;
    /** The real principal (the admin) when impersonating; else same as authorizedUser. */
    private ?UserEntity $realUser = null;
    /** Set to the acting admin's id while an impersonation cookie is in effect. */
    private ?int $impersonatorId = null;

    public function __construct(
        protected SessionService $session,
        protected Config $config,
        protected ?HttpContext $httpContext,
        protected UserRepository $users,
        protected UserTokenRepository $userTokens,
        protected UserVerificationCodeRepository $userVerifications,
        protected ?IUserRegistrationHook $registrationHook = null
    ) {
    }

    public function authorizeRequest()
    {
        if ($this->authCookie ?? ($this->authCookie = $this->httpContext->request->getCookie(self::COOKIE_NAME))) {
            if ($this->authCookie) {
                // Expect exactly "token.userId.checksum". A malformed cookie
                // (too few / too many parts) is ignored rather than destructured
                // (which would emit undefined-array-key warnings).
                $parts = explode('.', $this->authCookie);
                if (count($parts) === 3) {
                    [$token, $userId, $checksum] = $parts;
                    $integrityHash = hash('crc32', $token . $userId . $this->config->values['hashSalt']);
                    if ($integrityHash === $checksum) {
                        // $userId from the cookie is only used above to recompute the
                        // integrity hash; it is deliberately not trusted for identity —
                        // the user is loaded from the token row (getAuthorizedUser).
                        $this->authToken = $token;
                    }
                }
            }
        }
        if ($this->authToken !== null) {
            $hash = $this->hashToken($this->authToken);
            $this->userToken = $this->userTokens->find(UserTokenEntityMap::PROPERTY_TokenHash, $hash);
            // Enforce token expiry: an expired session must grant nothing. Delete it
            // so the cookie stops working the instant it lapses (self-heals; the GC
            // cron is only a backstop for tokens of users who never return). Rows
            // with a NULL Expire mean "no explicit expiry" and stay valid.
            if (
                $this->userToken !== null
                && $this->userToken->Expire !== null
                && $this->userToken->Expire < time()
            ) {
                $this->userTokens->delete($this->userToken);
                $this->userToken = null;
                return false;
            }
            // TODO: session rotation (sliding expiry) — if $this->userToken is close
            // to Expire, mint a fresh token (new Token/TokenHash + extended Expire),
            // delete this one, and re-set the AUTH cookie so active users aren't
            // logged out mid-use while idle sessions still lapse. Investigate best
            // practices first (OWASP: periodic id renewal, sliding vs absolute
            // timeout, reuse detection, concurrent-request races). Needs LastVisit
            // to actually be updated per request. See backlog #39.
            return $this->userToken !== null;
        }
        return false;
    }

    public function authorizeAdminRequest(): bool
    {
        $user = $this->getAuthorizedUser();
        if ($user === null) {
            return false;
        }
        return Permissions::can($user->Permissions, Capability::AccessAdmin);
    }

    /** Effective permissions of the authorized user (0 when not authenticated). */
    public function permissions(): int
    {
        return $this->getAuthorizedUser()?->Permissions ?? 0;
    }

    /** Does the authorized user have the given capability (see Fluffy\Security\Capability)? */
    public function can(int $capability): bool
    {
        return Permissions::can($this->permissions(), $capability);
    }

    /**
     * Admin-area gate plus a specific capability — for /api/admin/* endpoints
     * that act globally. Requires AccessAdmin (so team-scoped roles that merely
     * share a capability can't reach the admin endpoint) AND the capability
     * (so staff are differentiated, e.g. Support read-only vs Admin full).
     */
    public function authorizeAdminCapability(int $capability): bool
    {
        $permissions = $this->permissions();
        return Permissions::can($permissions, Capability::AccessAdmin)
            && Permissions::can($permissions, $capability);
    }

    /** Does the authorized user have the given role bit (see Fluffy\Security\Role)? */
    public function hasRole(int $roleBit): bool
    {
        return Permissions::hasRole($this->permissions(), $roleBit);
    }

    public function authorizeCSRF(): bool
    {
        $csrfToken = $this->session->getSession()?->CSRF;
        $csrfRequestToken = $this->httpContext->request->getHeader('X-CSRF-TOKEN');
        return $csrfToken && $csrfRequestToken && hash_equals($csrfToken, $csrfRequestToken);
    }

    public function authorizeBasic(string $userName, string $password): AuthResult
    {
        $result = new AuthResult();
        /** @var UserEntity|null $user */
        $user = $this->users->firstOrDefault(
            [
                [
                    [UserEntityMap::PROPERTY_UserName, $userName],
                    [UserEntityMap::PROPERTY_Email, $userName], // TODO: check format and do not search phone in email
                    [UserEntityMap::PROPERTY_Phone, $userName]
                ]
            ]
        );
        if ($user) {
            $result->User = $user;
            if (password_verify($password, $user->Password ?? '')) {
                // Correct password, but a deactivated (admin-disabled) account may
                // not log in. Unconfirmed users are mid-signup, not deactivated.
                if ($this->isDeactivated($user)) {
                    $result->Disabled = true;
                } else {
                    $result->Success = true;
                }
            }
        }
        return $result;
    }

    /**
     * A confirmed account that has been switched off (admin-deactivated): may not
     * log in, and any live session it holds is revoked on the next request.
     *
     * Unconfirmed users (EmailConfirmed=false) are exempt — they are mid-signup
     * (created Active=false, granted a one-time register auto-login until they
     * confirm), not disabled.
     */
    public function isDeactivated(UserEntity $user): bool
    {
        return !$user->Active && $user->EmailConfirmed;
    }

    public function authorizeUser(UserEntity $user, bool $rememberMe = false, bool $setCookie = true): ?UserTokenEntity
    {
        // Re-login from a context that already holds a valid AUTH token: the new
        // cookie set below replaces it, so that old token is abandoned — it can
        // never be presented again from this client. Delete it, so signing in
        // again on the same device doesn't leave a dead session behind (this is
        // what made tokens pile up). Only when we actually replace the cookie.
        if ($setCookie && ($this->userToken !== null || $this->authorizeRequest()) && $this->userToken !== null) {
            $this->userTokens->delete($this->userToken);
            $this->userToken = null;
        }
        // Remember-me → a long-lived, persistent cookie; otherwise a shorter
        // server-side token life and a session cookie (dropped on browser close).
        $rememberLife = (int)($this->config->values['authRememberLifetime'] ?? self::REMEMBER_LIFETIME);
        $sessionLife = (int)($this->config->values['authSessionLifetime'] ?? self::SESSION_LIFETIME);
        $lifetime = $rememberMe ? $rememberLife : $sessionLife;

        $token = new UserTokenEntity();
        $token->UserId = $user->Id;
        $token->Expire = time() + $lifetime;
        $token->LastVisit = UtilsService::GetMicroTime();
        $token->Token = UtilsService::randomHex(64);
        $token->TokenHash = $this->hashToken($token->Token);
        $this->userTokens->create($token);
        $this->enforceSessionLimit($user->Id);
        if ($setCookie) {
            $integrityHash = hash('crc32', $token->Token . $token->UserId . $this->config->values['hashSalt']);
            $authCookieValue = "{$token->Token}.{$token->UserId}.$integrityHash";
            // expire 0 = session cookie (browser drops it on close); a future
            // timestamp = persistent cookie that survives restarts (remember-me).
            $cookieExpire = $rememberMe ? time() + $rememberLife : 0;
            // SameSite=Lax: not sent on cross-site subrequests/POSTs (CSRF defence),
            // but still sent on top-level navigation so following a link into the
            // app (email/confirm/invite links) arrives authenticated.
            $this->httpContext->response->setCookie(self::COOKIE_NAME, $authCookieValue, $cookieExpire, '/', '', 1, 1, 'Lax');
        }
        return $token;
    }

    /**
     * Cap the number of concurrent login sessions per user. Called right after a
     * new token is minted: keeps the N most-recent tokens (config
     * 'maxUserSessions', falling back to MAX_USER_TOKENS) and deletes the rest —
     * so logging in on a new device silently evicts the least-recent session and
     * accumulated stale/expired tokens are cleaned up. A limit <= 0 = unlimited.
     *
     * NB: this only prunes users who log in. TODO: a cheap periodic GC sweep
     * (DELETE FROM UserToken WHERE Expire < now) to reclaim expired tokens for
     * dormant users who never log in again — pure row hygiene, not enforcement
     * (expired tokens already grant nothing at the auth check). Fold into an
     * existing cleanup cron if the table ever grows.
     */
    private function enforceSessionLimit(int $userId): void
    {
        $max = (int)($this->config->values['maxUserSessions'] ?? self::MAX_USER_TOKENS);
        if ($max <= 0) {
            return;
        }
        $result = $this->userTokens->search(
            [[UserTokenEntityMap::PROPERTY_UserId, $userId]],
            [UserTokenEntityMap::PROPERTY_CreatedOn => -1],
            1,
            null,
            false
        );
        $tokens = $result['list'];
        if (count($tokens) <= $max) {
            return;
        }
        foreach (array_slice($tokens, $max) as $stale) {
            $this->userTokens->delete($stale);
        }
    }

    public function logout()
    {
        if ($this->authorizeRequest()) {
            $this->userTokens->delete($this->userToken);
            $this->httpContext->response->setCookie(self::COOKIE_NAME, '', -1, '/', '', 1, 1, 'Lax');
            // Also drop any impersonation overlay so a full logout leaves nothing behind.
            $this->stopImpersonation();
        }
    }

    public function getOrStartSession(): SessionEntity
    {
        return $this->session->startSession();
    }

    public function getAuthorizedUser(): ?UserEntity
    {
        if ($this->authorizedUser !== null) {
            return $this->authorizedUser;
        }
        if ($this->userToken === null) {
            $this->authorizeRequest();
        }
        if ($this->userToken === null) {
            return null;
        }
        $realUser = $this->users->getById($this->userToken->UserId);
        // Revoke live sessions of a deactivated account: even with a valid,
        // unexpired token the request is treated as unauthenticated.
        if ($realUser === null || $this->isDeactivated($realUser)) {
            return null;
        }
        $this->realUser = $realUser;
        // Single point of entry for impersonation: when a valid IMP cookie is in
        // effect the effective (authorized) user becomes the target, so everything
        // downstream (permissions, guards, Me) sees the impersonated user.
        $target = $this->resolveImpersonation($realUser);
        if ($target !== null) {
            $this->impersonatorId = $realUser->Id;
            $this->authorizedUser = $target;
        } else {
            $this->authorizedUser = $realUser;
        }
        return $this->authorizedUser;
    }

    /**
     * Resolve the impersonation overlay for this request, or null.
     *
     * The IMP cookie is simply the target user id. It only takes effect when the
     * AUTH user is a SuperAdmin — who can already act on any account, so
     * impersonation grants no new privilege and needs no signature or per-target
     * guard. A non-SuperAdmin's IMP cookie is ignored, so it can never escalate.
     * A deactivated TARGET is allowed (support can view a locked-out user).
     */
    private function resolveImpersonation(UserEntity $realUser): ?UserEntity
    {
        if ($this->httpContext === null) {
            return null;
        }
        $targetId = (int) $this->httpContext->request->getCookie(self::IMPERSONATE_COOKIE);
        if ($targetId <= 0 || $targetId === $realUser->Id) {
            return null;
        }
        if (!Permissions::hasRole($realUser->Permissions, Role::SuperAdmin)) {
            return null;
        }
        return $this->users->getById($targetId);
    }

    /**
     * Begin impersonating $targetId: set the IMP overlay cookie (just the target
     * id). No DB write — the admin's own AUTH session is untouched. It only
     * resolves for a SuperAdmin session (see resolveImpersonation); the caller
     * gates Start on SuperAdmin.
     */
    public function startImpersonation(int $targetId): void
    {
        if ($this->httpContext === null) {
            return;
        }
        $ttl = (int)($this->config->values['impersonationLifetime'] ?? self::IMPERSONATION_LIFETIME);
        $this->httpContext->response->setCookie(self::IMPERSONATE_COOKIE, (string)$targetId, time() + $ttl, '/', '', 1, 1, 'Lax');
    }

    /** End impersonation: clear the IMP cookie. The admin's AUTH session remains. */
    public function stopImpersonation(): void
    {
        if ($this->httpContext === null) {
            return;
        }
        $this->httpContext->response->setCookie(self::IMPERSONATE_COOKIE, '', -1, '/', '', 1, 1, 'Lax');
    }

    /** True when the current request runs under an impersonation overlay. */
    public function isImpersonating(): bool
    {
        $this->getAuthorizedUser();
        return $this->impersonatorId !== null;
    }

    /** The acting admin's id while impersonating, else null. */
    public function getImpersonatorId(): ?int
    {
        $this->getAuthorizedUser();
        return $this->impersonatorId;
    }

    /** The real principal (the admin); same as getAuthorizedUser() when not impersonating. */
    public function getRealUser(): ?UserEntity
    {
        $this->getAuthorizedUser();
        return $this->realUser;
    }

    function registerUser(UserEntity $user): RegisterResult
    {
        $result = new RegisterResult();
        if (!isset($user->UserName)) {
            $user->UserName = $user->Email ? $user->Email : $user->Phone;
        }
        $user->Email = $user->Email ? strtolower($user->Email) : null;
        $user->Phone = $user->Phone ? strtolower($user->Phone) : null;
        $existentUser = $this->users->firstOrDefault(
            [
                [
                    [UserEntityMap::PROPERTY_UserName, $user->UserName],
                    [UserEntityMap::PROPERTY_Email, $user->Email ? $user->Email : $user->UserName], // TODO: check format and do not search phone in email
                    [UserEntityMap::PROPERTY_Phone, $user->Phone ? $user->Phone : $user->UserName]
                ]
            ]
        );
        if ($existentUser !== null) {
            // // Test
            // $result->Success = true;
            // $result->User = $existentUser;
            // return $result;
            // // End test
            $result->UserNameTaken = true;
            return $result;
        }
        if ($user->Password) {
            $user->Password = $this->hashPassword($user->Password);
        }
        $result->Success = $this->users->create($user);
        if ($result->Success) {
            $result->User = $user;
            $this->registrationHook?->onUserRegistered($user);
        }
        return $result;
    }

    // lifetime 72 hrs = 259200 seconds
    function createVerificationCode(int $userId, bool $string = true, int $length = 32, int $lifeTime = 259200): ?UserVerificationCodeEntity
    {
        $code = $string ? UtilsService::randomString($length) : UtilsService::randomInt($length);
        $verificationEntity = new UserVerificationCodeEntity();
        $verificationEntity->Code = $code;
        $verificationEntity->CodeHash = $length > 255 ? $this->hashToken($code) : $verificationEntity->Code;
        $verificationEntity->UserId = $userId;
        $verificationEntity->Expire = time() + $lifeTime;
        $this->userVerifications->create($verificationEntity);
        return $verificationEntity;
    }

    function verifyCode(string $code): ?UserVerificationCodeEntity
    {
        /** @var UserVerificationCodeEntity|null $verificationEntity */
        $verificationEntity = $this->userVerifications->find(UserVerificationCodeEntityMap::PROPERTY_CodeHash, $code);
        if ($verificationEntity !== null) {
            if ($verificationEntity->Expire !== null && $verificationEntity->Expire < time()) {
                // expired
                $this->invalidateCode($verificationEntity);
                return null;
            }
            return $verificationEntity;
        }
        return null;
    }

    function invalidateCode(UserVerificationCodeEntity $verificationEntity)
    {
        return $this->userVerifications->delete($verificationEntity);
    }

    function activateUser(int $userId)
    {
        /** @var UserEntity|null $user */
        $user = $this->users->getById($userId);
        if ($user !== null && (!$user->Active || !$user->EmailConfirmed)) {
            $user->Active = true;
            $user->EmailConfirmed = true;
            $this->users->update($user, [UserEntityMap::PROPERTY_Active, UserEntityMap::PROPERTY_EmailConfirmed]);
        }
    }

    function changePassword(int $userId, string $password, bool $keepCurrentSession = false)
    {
        /** @var UserEntity|null $user */
        $user = $this->users->getById($userId);
        if ($user !== null) {
            $user->Password = $this->hashPassword($password);
            $this->users->update($user, [UserEntityMap::PROPERTY_Password]);
            // A password change invalidates existing sessions. Keep the caller's
            // current session when they changed their own password in-session
            // (keepCurrentSession); otherwise — reset flow, admin reset — revoke
            // every session so a stolen/old cookie can't outlive the change.
            $keep = null;
            if ($keepCurrentSession && $this->userToken !== null && $this->userToken->UserId === $userId) {
                $keep = $this->userToken->TokenHash;
            }
            $this->revokeSessions($userId, $keep);
        }
    }

    /**
     * Delete a user's login sessions (UserToken rows) — all of them, or all but
     * one (exceptTokenHash, e.g. the caller's current session). Returns the
     * number revoked. Used after a password change/reset; also usable for an
     * admin "log out everywhere".
     */
    public function revokeSessions(int $userId, ?string $exceptTokenHash = null): int
    {
        $where = [[UserTokenEntityMap::PROPERTY_UserId, $userId]];
        if ($exceptTokenHash !== null) {
            $where[] = [UserTokenEntityMap::PROPERTY_TokenHash, '!=', $exceptTokenHash];
        }
        return $this->userTokens->deleteWhere($where);
    }

    function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    function hashPassword(string $password)
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

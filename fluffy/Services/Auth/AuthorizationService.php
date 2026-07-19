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
use Fluffy\Services\Session\SessionService;
use Fluffy\Services\UtilsService;

class AuthorizationService
{
    const COOKIE_NAME = 'AUTH';
    const MAX_USER_TOKENS = 5;

    private ?string $authCookie;
    private ?string $authToken = null;
    private ?int $userId = null;
    private ?UserEntity $authorizedUser = null;
    private ?UserTokenEntity $userToken = null;

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
                [$token, $userId, $checksum] = explode('.', $this->authCookie);
                $integrityHash = hash('crc32', $token . $userId . $this->config->values['hashSalt']);
                if ($integrityHash === $checksum) {
                    $this->userId = $userId;
                    $this->authToken = $token;
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
        $token = new UserTokenEntity();
        $token->UserId = $user->Id;
        $token->Expire = time() + 60 * 60 * 24 * 30;
        $token->LastVisit = UtilsService::GetMicroTime();
        $token->Token = UtilsService::randomHex(64);
        $token->TokenHash = $this->hashToken($token->Token);
        $this->userTokens->create($token);
        $this->enforceSessionLimit($user->Id);
        if ($setCookie) {
            $integrityHash = hash('crc32', $token->Token . $token->UserId . $this->config->values['hashSalt']);
            $authCookieValue = "{$token->Token}.{$token->UserId}.$integrityHash";
            $this->httpContext->response->setCookie(self::COOKIE_NAME, $authCookieValue, time() + 60 * 60 * 24 * 30, '/', '', 1, 1);
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
            $this->httpContext->response->setCookie(self::COOKIE_NAME, '', -1, '/', '', 1, 1);
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
        if ($this->userToken !== null) {
            $user = $this->users->GetById($this->userToken->UserId);
            // Revoke live sessions of a deactivated account: even with a valid,
            // unexpired token the request is treated as unauthenticated.
            if ($user !== null && $this->isDeactivated($user)) {
                return null;
            }
            $this->authorizedUser = $user;
        }
        return $this->authorizedUser;
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

    function changePassword(int $userId, string $password)
    {
        /** @var UserEntity|null $user */
        $user = $this->users->getById($userId);
        if ($user !== null) {
            $user->Password = $this->hashPassword($password);
            $this->users->update($user, [UserEntityMap::PROPERTY_Password]);
        }
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

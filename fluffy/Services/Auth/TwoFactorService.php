<?php

namespace Fluffy\Services\Auth;

use Fluffy\Data\Entities\Auth\UserEntity;
use Fluffy\Data\Entities\Auth\UserRecoveryCodeEntityMap;
use Fluffy\Data\Entities\Auth\UserTwoFactorEntity;
use Fluffy\Data\Entities\Auth\UserTwoFactorEntityMap;
use Fluffy\Data\Repositories\UserRecoveryCodeRepository;
use Fluffy\Data\Repositories\UserRepository;
use Fluffy\Data\Repositories\UserTwoFactorRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Domain\Message\HttpContext;
use Fluffy\Services\Security\SecretCipher;
use Fluffy\Swoole\RateLimit\IRateLimitService;

/**
 * The second step of sign-in. Every sign-in path (password, provider, invitation) checks
 * needsChallenge() after the first factor passes; when it says yes, the path calls
 * startChallenge() INSTEAD of AuthorizationService::authorizeUser(), and complete() signs the
 * user in once the code checks out.
 *
 * Between the two steps the browser holds a signed cookie (user id, remember-me, expiry), not a
 * session: it grants nothing by itself. Only the second-step endpoint ever receives it.
 */
class TwoFactorService
{
    public const COOKIE_NAME = 'TFA';
    public const COOKIE_PATH = '/api/authorization/two-factor';
    /** Time to open the authenticator app and type the code. */
    private const CHALLENGE_LIFETIME = 300;
    /** Wrong codes allowed per account per window. The password is already known to whoever is here. */
    private const USER_ATTEMPTS = 5;
    private const USER_WINDOW = 900;

    public const RESULT_OK = 'ok';
    /** No challenge, or it lapsed: sign in again. */
    public const RESULT_EXPIRED = 'expired';
    public const RESULT_WRONG = 'wrong';
    public const RESULT_TOO_MANY = 'too-many';

    public function __construct(
        private Config $config,
        private ?HttpContext $httpContext,
        private UserTwoFactorRepository $twoFactors,
        private UserRecoveryCodeRepository $recoveryCodes,
        private UserRepository $users,
        private AuthorizationService $auth,
        private TotpService $totp,
        private SecretCipher $cipher,
        private IRateLimitService $rateLimit
    ) {}

    /** True when the user finished setting up two-factor sign-in, so sign-in must ask for a code. */
    public function needsChallenge(UserEntity $user): bool
    {
        return $this->enabledRecord($user->Id) !== null;
    }

    /** First factor passed: hold the sign-in until the code arrives. */
    public function startChallenge(UserEntity $user, bool $rememberMe): void
    {
        if ($this->httpContext === null) {
            return;
        }
        $payload = rtrim(strtr(base64_encode(json_encode([
            'u' => $user->Id,
            'm' => $rememberMe ? 1 : 0,
            'e' => time() + self::CHALLENGE_LIFETIME,
            'p' => $this->passwordMark($user),
        ])), '+/', '-_'), '=');
        $this->httpContext->response->setCookie(self::COOKIE_NAME, $payload . '.' . $this->sign($payload), time() + self::CHALLENGE_LIFETIME, self::COOKIE_PATH, '', 1, 1, 'Lax');
    }

    /**
     * Check a code from the authenticator app, or a recovery code, against the challenge this
     * browser holds; sign the user in on success. Returns one of the RESULT_ constants.
     */
    public function complete(string $code): string
    {
        $challenge = $this->readChallenge();
        $user = $challenge === null ? null : $this->users->getById((int) $challenge['u']);
        if (
            $user === null
            || $this->auth->isDeactivated($user)
            || !hash_equals($this->passwordMark($user), (string) ($challenge['p'] ?? ''))
        ) {
            // Gone, switched off, or the password changed since the first step.
            $this->clearChallenge();
            return self::RESULT_EXPIRED;
        }
        $record = $this->enabledRecord($user->Id);
        if ($record === null) {
            // 2FA was turned off in between: the first factor was all this account needs.
            $this->clearChallenge();
            $this->auth->authorizeUser($user, (bool) $challenge['m']);
            return self::RESULT_OK;
        }
        if (!$this->rateLimit->limit('tfa:user:' . $user->Id, self::USER_ATTEMPTS, self::USER_WINDOW)) {
            return self::RESULT_TOO_MANY;
        }
        if (!$this->checkTotp($record, $code) && !$this->useRecoveryCode($user->Id, $code)) {
            return self::RESULT_WRONG;
        }
        $this->clearChallenge();
        $this->auth->authorizeUser($user, (bool) $challenge['m']);
        return self::RESULT_OK;
    }

    /** sha256 hex of a recovery code as people type it: case, spaces and dashes do not matter. */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(str_replace([' ', '-'], '', $code)));
    }

    private function enabledRecord(int $userId): ?UserTwoFactorEntity
    {
        /** @var UserTwoFactorEntity|null $record */
        $record = $this->twoFactors->find(UserTwoFactorEntityMap::PROPERTY_UserId, $userId);
        return $record !== null && $record->EnabledOn !== null ? $record : null;
    }

    private function checkTotp(UserTwoFactorEntity $record, string $code): bool
    {
        $secret = $this->cipher->decrypt($record->SecretEncrypted, SecretCipher::PURPOSE_TOTP_SECRET);
        if ($secret === null) {
            return false;
        }
        $step = $this->totp->verify($secret, $code, $record->LastUsedStep);
        if ($step === null) {
            return false;
        }
        // Claim the step atomically: of two requests racing with the same code, one wins.
        return $this->twoFactors->updateWhere(
            [UserTwoFactorEntityMap::PROPERTY_LastUsedStep => $step],
            [
                [UserTwoFactorEntityMap::PROPERTY_Id, $record->Id],
                [UserTwoFactorEntityMap::PROPERTY_LastUsedStep, '<', $step],
            ]
        ) === 1;
    }

    private function useRecoveryCode(int $userId, string $code): bool
    {
        if (strlen(str_replace([' ', '-'], '', $code)) <= TotpService::DIGITS) {
            return false;
        }
        // Deleting is the check: the row exists once, so the code works once.
        return $this->recoveryCodes->deleteWhere([
            [UserRecoveryCodeEntityMap::PROPERTY_UserId, $userId],
            [UserRecoveryCodeEntityMap::PROPERTY_CodeHash, self::hashRecoveryCode($code)],
        ]) === 1;
    }

    private function readChallenge(): ?array
    {
        $cookie = $this->httpContext?->request->getCookie(self::COOKIE_NAME);
        if (!is_string($cookie) || substr_count($cookie, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = explode('.', $cookie);
        if (!hash_equals($this->sign($payload), $signature)) {
            return null;
        }
        $challenge = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($challenge) || (int) ($challenge['e'] ?? 0) < time() || (int) ($challenge['u'] ?? 0) <= 0) {
            return null;
        }
        return $challenge;
    }

    private function clearChallenge(): void
    {
        // Same path as it was set with, or the browser keeps it.
        $this->httpContext?->response->setCookie(self::COOKIE_NAME, '', time() - 3600, self::COOKIE_PATH, '', 1, 1, 'Lax');
    }

    /** Ties the challenge to the password it was earned with, so a reset in between voids it. */
    private function passwordMark(UserEntity $user): string
    {
        return substr(hash('sha256', (string) $user->Password), 0, 16);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'two-factor:' . $payload, (string) $this->config->values['hashSalt']);
    }
}

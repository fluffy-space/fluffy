<?php

namespace Fluffy\Services\Auth;

use Fluffy\Models\Auth\SessionOptions;
use Fluffy\Data\Entities\Auth\UserEntity;
use Fluffy\Data\Entities\Auth\UserRecoveryCodeEntity;
use Fluffy\Data\Entities\Auth\UserRecoveryCodeEntityMap;
use Fluffy\Data\Entities\Auth\UserTwoFactorEntity;
use Fluffy\Data\Entities\Auth\UserTwoFactorEntityMap;
use Fluffy\Data\Repositories\BasePostgresqlRepository;
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
    public const RECOVERY_CODES = 10;

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
        private IRateLimitService $rateLimit,
        private ?ISessionRenewer $sessionRenewer = null
    ) {}

    /** True when the user finished setting up two-factor sign-in, so sign-in must ask for a code. */
    public function needsChallenge(UserEntity $user): bool
    {
        return $this->enabledRecord($user->Id) !== null;
    }

    /**
     * First factor passed: hold the sign-in until the code arrives.
     *
     * $session: the session an organisation's sign-in (SSO) asked for, made instead of the
     * remember-me one once the code is right. $reference: what the app parked for that session (its
     * refresh grant), handed to ISessionRenewer::adopt() with the new token. Both travel in the
     * signed challenge cookie; the reference is an opaque id, never the credential itself.
     */
    public function startChallenge(UserEntity $user, bool $rememberMe, ?SessionOptions $session = null, ?string $reference = null): void
    {
        if ($this->httpContext === null) {
            return;
        }
        $challenge = [
            'u' => $user->Id,
            'm' => $rememberMe ? 1 : 0,
            'e' => time() + self::CHALLENGE_LIFETIME,
            'p' => $this->passwordMark($user),
        ];
        if ($session !== null) {
            $challenge['s'] = [$session->lifetime, $session->persistent ? 1 : 0, $session->ssoConnectionId, $session->ssoCheckDue];
            $challenge['x'] = $reference;
        }
        $payload = rtrim(strtr(base64_encode(json_encode($challenge)), '+/', '-_'), '=');
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
            $this->signIn($user, $challenge);
            return self::RESULT_OK;
        }
        $result = $this->limited($user->Id, fn() => $this->checkTotp($record, $code) || $this->useRecoveryCode($user->Id, $code));
        if ($result !== self::RESULT_OK) {
            return $result;
        }
        $this->clearChallenge();
        $this->signIn($user, $challenge);
        return self::RESULT_OK;
    }

    /** The session the first step asked for: the SSO one it carried, or the remember-me one. */
    private function signIn(UserEntity $user, array $challenge): void
    {
        $s = $challenge['s'] ?? null;
        if (!is_array($s) || count($s) !== 4) {
            $this->auth->authorizeUser($user, (bool) $challenge['m']);
            return;
        }
        // The end is counted from the first step, so waiting at the code does not extend it.
        $token = $this->auth->authorizeUserWith($user, new SessionOptions((int) $s[0], (bool) $s[1], $s[2] === null ? null : (int) $s[2], $s[3] === null ? null : (int) $s[3]));
        $reference = $challenge['x'] ?? null;
        if ($token !== null && is_string($reference) && $reference !== '') {
            $this->sessionRenewer?->adopt($token, $reference);
        }
    }

    /** sha256 hex of a recovery code as people type it: case, spaces and dashes do not matter. */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(str_replace([' ', '-'], '', $code)));
    }

    // --- setup and management, for the signed-in user -----------------------

    public function isEnabled(int $userId): bool
    {
        return $this->enabledRecord($userId) !== null;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        return array_sum($this->recoveryCodes->countBy(UserRecoveryCodeEntityMap::PROPERTY_UserId, [
            [UserRecoveryCodeEntityMap::PROPERTY_UserId, $userId],
        ]));
    }

    /**
     * Start setup: a new secret, stored but not yet in force (EnabledOn null) until a code from
     * the app confirms it. Starting again replaces an unfinished setup. Null when already on.
     */
    public function beginSetup(int $userId): ?string
    {
        /** @var UserTwoFactorEntity|null $record */
        $record = $this->twoFactors->find(UserTwoFactorEntityMap::PROPERTY_UserId, $userId);
        if ($record !== null && $record->EnabledOn !== null) {
            return null;
        }
        $secret = $this->totp->generateSecret();
        $encrypted = $this->cipher->encrypt($secret, SecretCipher::PURPOSE_TOTP_SECRET);
        if ($record === null) {
            $record = new UserTwoFactorEntity();
            $record->UserId = $userId;
            $record->SecretEncrypted = $encrypted;
            $this->twoFactors->create($record);
        } else {
            $record->SecretEncrypted = $encrypted;
            $record->LastUsedStep = 0;
            $this->twoFactors->update($record, [UserTwoFactorEntityMap::PROPERTY_SecretEncrypted, UserTwoFactorEntityMap::PROPERTY_LastUsedStep]);
        }
        return $secret;
    }

    /**
     * Finish setup with the first code from the app: 2FA is on from here. Returns the new
     * recovery codes (shown once, never stored in the clear), or RESULT_WRONG / RESULT_TOO_MANY.
     */
    public function confirmSetup(int $userId, string $code): array|string
    {
        /** @var UserTwoFactorEntity|null $record */
        $record = $this->twoFactors->find(UserTwoFactorEntityMap::PROPERTY_UserId, $userId);
        if ($record === null || $record->EnabledOn !== null) {
            return self::RESULT_WRONG;
        }
        $result = $this->limited($userId, fn() => $this->checkTotp($record, $code));
        if ($result !== self::RESULT_OK) {
            return $result;
        }
        $this->twoFactors->updateWhere(
            [UserTwoFactorEntityMap::PROPERTY_EnabledOn => BasePostgresqlRepository::getTime()],
            [[UserTwoFactorEntityMap::PROPERTY_Id, $record->Id]]
        );
        return $this->replaceRecoveryCodes($userId);
    }

    /**
     * A code from the app or a recovery code, for confirming a change while signed in (turning
     * 2FA off, new recovery codes). Shares the sign-in attempt limit. A recovery code is used up.
     * Returns one of RESULT_OK, RESULT_WRONG, RESULT_TOO_MANY.
     */
    public function verifyCode(int $userId, string $code): string
    {
        $record = $this->enabledRecord($userId);
        if ($record === null) {
            return self::RESULT_WRONG;
        }
        return $this->limited($userId, fn() => $this->checkTotp($record, $code) || $this->useRecoveryCode($userId, $code));
    }

    /** Throw away the old recovery codes and make RECOVERY_CODES new ones; returns them for showing once. */
    public function replaceRecoveryCodes(int $userId): array
    {
        $this->recoveryCodes->deleteWhere([[UserRecoveryCodeEntityMap::PROPERTY_UserId, $userId]]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = self::randomRecoveryCode();
            $row = new UserRecoveryCodeEntity();
            $row->UserId = $userId;
            $row->CodeHash = self::hashRecoveryCode($code);
            $this->recoveryCodes->create($row);
            $codes[] = $code;
        }
        return $codes;
    }

    /** Turn 2FA off: the secret and every recovery code go. */
    public function disable(int $userId): void
    {
        $this->recoveryCodes->deleteWhere([[UserRecoveryCodeEntityMap::PROPERTY_UserId, $userId]]);
        $this->twoFactors->deleteWhere([[UserTwoFactorEntityMap::PROPERTY_UserId, $userId]]);
    }

    /** "k7m2p-x9qr4": 10 characters from 31 (no 0/o, 1/l/i), about 50 bits. */
    private static function randomRecoveryCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /**
     * Run one code check under the per-account attempt limit. Every attempt counts BEFORE the
     * check (atomic, so parallel guesses cannot slip past), and a right code clears the count:
     * only wrong guesses add up, so someone who uses their codes normally never meets the limit.
     */
    private function limited(int $userId, callable $check): string
    {
        $key = 'tfa:user:' . $userId;
        if (!$this->rateLimit->limit($key, self::USER_ATTEMPTS, self::USER_WINDOW)) {
            return self::RESULT_TOO_MANY;
        }
        if (!$check()) {
            return self::RESULT_WRONG;
        }
        $this->rateLimit->reset($key);
        return self::RESULT_OK;
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

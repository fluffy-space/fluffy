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
        protected UserVerificationCodeRepository $userVerifications
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
            return $this->userToken !== null;
        }
        return false;
    }

    public function authorizeAdminRequest(): bool
    {
        $user = $this->getAuthorizedUser();
        return $user?->IsAdmin ?? false;
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
                $result->Success = true;
            }
        }
        return $result;
    }

    public function authorizeUser(UserEntity $user, bool $rememberMe = false, bool $setCookie = true): ?UserTokenEntity
    {
        $token = new UserTokenEntity();
        $token->UserId = $user->Id;
        $token->Expire = time() + 60 * 60 * 24 * 30;
        $token->LastVisit = UtilsService::GetMicroTime();
        $token->Token = UtilsService::randomHex(64);
        $token->TokenHash = $this->hashToken($token->Token);
        $this->userTokens->create($token);
        if ($setCookie) {
            $integrityHash = hash('crc32', $token->Token . $token->UserId . $this->config->values['hashSalt']);
            $authCookieValue = "{$token->Token}.{$token->UserId}.$integrityHash";
            $this->httpContext->response->setCookie(self::COOKIE_NAME, $authCookieValue, time() + 60 * 60 * 24 * 30, '/', '', 1, 1);
        }
        return $token;
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
            $this->authorizedUser = $this->users->GetById($this->userToken->UserId);
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

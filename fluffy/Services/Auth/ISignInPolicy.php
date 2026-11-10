<?php

namespace Fluffy\Services\Auth;

use Fluffy\Data\Entities\Auth\UserEntity;

/**
 * Lets the app refuse a way of signing in for an account, e.g. an organisation that requires its
 * own sign-in (SSO) for every address on its domain. Register an implementation in the app's DI
 * (`addScoped(ISignInPolicy::class, ...)`).
 *
 * Asked only at sign-in time (password, password reset, provider sign-in, registration), never on
 * an ordinary request.
 */
interface ISignInPolicy
{
    public const METHOD_PASSWORD = 'password';
    public const METHOD_RESET = 'reset';
    public const METHOD_PROVIDER = 'provider';
    public const METHOD_REGISTER = 'register';

    /** Null = allowed; otherwise a short reason code (e.g. 'sso') the caller turns into a message. */
    public function refusal(UserEntity $user, string $method): ?string;
}

<?php

namespace Fluffy\Services\Auth;

use Fluffy\Data\Entities\Auth\UserEntity;

/**
 * Optional post-registration hook. Register an implementation in the app's DI
 * (e.g. `addScoped(IUserRegistrationHook::class, MyHook::class)`) and
 * AuthorizationService::registerUser invokes it after the user row is created —
 * for app-side side effects like provisioning a default workspace. Runs on
 * every registration path that goes through registerUser (public signup,
 * invitation signup, ...). Implementations must not throw: a failed side
 * effect must not fail the registration.
 */
interface IUserRegistrationHook
{
    public function onUserRegistered(UserEntity $user): void;
}

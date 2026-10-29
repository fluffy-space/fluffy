<?php

namespace Fluffy\Services\Auth;

use Fluffy\Data\Entities\Auth\UserEntity;

/**
 * Optional second way in, for requests that carry no AUTH cookie (e.g. a bearer key for internal
 * tooling). Register an implementation in the app's DI (`addScoped(IRequestPrincipalResolver::class,
 * MyResolver::class)`) and AuthorizationService::getAuthorizedUser asks it once per request when
 * the cookie path found nobody. Whatever it returns IS the signed-in user for that request, so
 * permissions, guards and Me all follow it: return a detached copy with only the Permissions the
 * credential grants, never the stored row with its full roles. Null = not signed in.
 */
interface IRequestPrincipalResolver
{
    public function resolve(): ?UserEntity;
}

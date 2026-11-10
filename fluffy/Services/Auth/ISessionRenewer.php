<?php

namespace Fluffy\Services\Auth;

use Fluffy\Data\Entities\Auth\UserTokenEntity;

/**
 * Renews sessions that an outside provider vouches for (an organisation's sign-in with a refresh
 * token). Register an implementation in the app's DI (`addScoped(ISessionRenewer::class, ...)`).
 *
 * AuthorizationService asks it only for a token with SsoConnectionId and SsoCheckDue set, and only
 * from `authSsoRenewLead` seconds (default 600) before SsoCheckDue. Every other session never
 * reaches it, so a request costs nothing extra unless a check is near.
 */
interface ISessionRenewer
{
    /**
     * $due = false: the check is near but not yet due. Start a renewal that does not hold up this
     * request and return true.
     *
     * $due = true: past SsoCheckDue (the tab was idle, the laptop was closed). Decide now, in line:
     * true keeps the session, false ends it (the token is deleted and the request is signed out).
     * A provider that cannot be reached is not a reason to answer false at once; that is the
     * implementation's policy.
     *
     * The implementation moves SsoCheckDue (and may shorten Expire) on both the row and $token.
     */
    public function renew(UserTokenEntity $token, bool $due): bool;

    /** Rotation replaced $old with $new: move whatever is keyed by the old token's id. */
    public function rotated(UserTokenEntity $old, UserTokenEntity $new): void;
}

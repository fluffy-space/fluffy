<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntity;

class UserTokenEntity extends BaseEntity
{
    public int $UserId;
    /**
     * Raw session token — held only transiently in memory to build the AUTH
     * cookie at creation; never persisted (see UserTokenEntityMap: not a column).
     * Null on any token loaded from the DB.
     */
    public ?string $Token = null;
    public string $TokenHash;
    public ?int $Expire = null;
    public ?int $LastVisit = null;
    /**
     * Set when the session came from an organisation's sign-in (SSO): the app's connection id.
     * Rotation copies it. Null for every other session.
     */
    public ?int $SsoConnectionId = null;
    /**
     * Unix seconds when the app must next confirm the session with the provider (refresh token).
     * Being past it does not end the session: the app's ISessionRenewer is asked first. Null when
     * there is nothing to renew with.
     */
    public ?int $SsoCheckDue = null;
}

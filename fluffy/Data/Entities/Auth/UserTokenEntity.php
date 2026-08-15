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
}

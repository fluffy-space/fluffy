<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntity;

/**
 * One single-use recovery code for two-factor sign-in. The row is deleted when the code is
 * used, so the count of rows is the count of codes left.
 */
class UserRecoveryCodeEntity extends BaseEntity
{
    public int $UserId;
    /**
     * sha256 hex of the normalised code. A plain fast hash is enough: the codes are random
     * and long, not chosen by people, and password_hash would cost ~50 ms per row checked.
     */
    public string $CodeHash;
}

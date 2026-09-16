<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntity;

class UserVerificationCodeEntity extends BaseEntity
{
    public int $UserId;
    /**
     * The raw code, in memory only — it is NOT a mapped column (see the map's Columns()), so it is
     * never written and comes back empty when a row is read. Populated at creation so the caller
     * can put it in the email; the row keeps only its sha256 in CodeHash.
     */
    public string $Code = '';
    public string $CodeHash;
    public ?int $Expire = null;
}

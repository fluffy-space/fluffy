<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntity;

class UserEntity extends BaseEntity
{
    public string $UserName;
    public ?string $FirstName = null;
    public ?string $LastName = null;
    public ?string $Email = null;
    public ?string $Phone = null;
    public ?string $Password = null;
    public bool $Active = false;
    public bool $EmailConfirmed = false;
    /**
     * Bitmask of assigned roles and granular capabilities.
     * Low bits (0-15) are role bits; high bits (16-62) are capability bits.
     * See Application\Security\Permissions for the bit layout and role->capability map.
     */
    public int $Permissions = 0;
}

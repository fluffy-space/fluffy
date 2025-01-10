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
    public bool $IsAdmin = false;
}

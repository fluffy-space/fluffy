<?php

namespace Fluffy\Models\Auth;

use Fluffy\Data\Entities\Auth\UserEntity;

class AuthResult
{
    public ?UserEntity $User = null;
    public bool $Success = false;
    public bool $Require2FA = false;
    /** Password was correct but the account is deactivated (Active=false, confirmed). */
    public bool $Disabled = false;
}
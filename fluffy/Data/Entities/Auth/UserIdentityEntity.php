<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntity;

/**
 * A sign-in provider account (Google, Microsoft, any OIDC issuer) linked to a user.
 * Holds the provider's own id for the person and nothing else: no email, name or token.
 */
class UserIdentityEntity extends BaseEntity
{
    public int $UserId;
    /** Provider key, e.g. 'google'. */
    public string $Provider;
    /** The provider's stable id for the account (the OIDC `sub` claim). */
    public string $Subject;
}

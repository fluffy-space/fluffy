<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntity;

/**
 * A user's authenticator-app (TOTP) secret. At most one per user. Kept off the User row on
 * purpose: the user row is read on every request and mapped into many responses, the secret
 * is needed only at sign-in.
 */
class UserTwoFactorEntity extends BaseEntity
{
    public int $UserId;
    /** The base32 secret, encrypted with SecretCipher::PURPOSE_TOTP_SECRET. */
    public string $SecretEncrypted;
    /** When the first code was confirmed (microseconds); null while setup is unfinished, and then sign-in does not ask. */
    public ?int $EnabledOn = null;
    /** The last 30-second step accepted, so each code works once. */
    public int $LastUsedStep = 0;
}

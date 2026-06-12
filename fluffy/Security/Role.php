<?php

namespace Fluffy\Security;

/**
 * Framework (core) role bits — bits 0..15 of UserEntity::$Permissions.
 *
 * Core owns role bits 0..7. Applications MUST define their own roles in bits
 * 8..15 (see the app's Role) to avoid collisions.
 *
 * A role is just a bit; what it *grants* is registered in PermissionRegistry
 * (core registers its defaults via CorePermissions; the app extends them).
 * Bit 0 = SuperAdmin.
 */
final class Role
{
    public const SuperAdmin = 1 << 0; // break-glass; granted every capability (see Permissions::effective)
    public const Admin      = 1 << 1; // staff admin; core grants ManageUsers/AccessAdmin/ManageRoles, app extends
    public const User       = 1 << 2; // baseline authenticated account

    /** First role bit reserved for applications. Core must stay below this. */
    public const APP_BIT_START = 8;

    /** Human-readable labels for the core roles. */
    public const LABELS = [
        self::SuperAdmin => 'Super Admin',
        self::Admin      => 'Admin',
        self::User       => 'User',
    ];
}

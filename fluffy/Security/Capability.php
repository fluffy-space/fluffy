<?php

namespace Fluffy\Security;

/**
 * Framework (core) capability bits.
 *
 * A capability is the atomic "can do X" unit. Roles bundle capabilities via the
 * PermissionRegistry; capability bits may also be granted directly on a user.
 *
 * BIT PARTITION (a bigint = 64 bits):
 *   bits 0..15  -> role bits        (core: 0..7,  app: 8..15)   — see Role
 *   bits 16..62 -> capability bits  (core: 16..31, app: 32..62)
 *   bit  63     -> unused (sign bit)
 *
 * Core owns capability bits 16..31. Applications MUST define their own
 * capabilities in bits 32..62 to avoid collisions (see the app's Capability).
 */
final class Capability
{
    public const ManageUsers = 1 << 16; // create/edit users, assign roles
    public const AccessAdmin = 1 << 17; // may reach the admin area (/api/admin)
    public const ManageRoles = 1 << 18; // change which roles/permissions a user has

    /** First capability bit reserved for applications. Core must stay below this. */
    public const APP_BIT_START = 32;
}

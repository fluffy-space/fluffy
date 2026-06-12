<?php

namespace Fluffy\Security;

/**
 * Framework (core) capability bits.
 *
 * A capability is the atomic "can do X" unit. Roles bundle capabilities via the
 * PermissionRegistry; capability bits may also be granted directly on a user.
 *
 * Core is intentionally minimal — it owns only the admin-area gate. Everything
 * else (user/role management, CMS, application features) is defined by the
 * downstream packages that build on core.
 *
 * BIT PARTITION (a bigint = 64 bits):
 *   bits 0..15  -> role bits       (core: 0..7,  downstream: 8..15)   — see Role
 *   bits 16..62 -> capability bits (core: 16..17, downstream: 18..62)
 *   bit  63     -> unused (sign bit)
 *
 * Core owns capability bits 16..17. Bits 18..62 belong to downstream packages,
 * which partition that space among themselves; core does not know about them.
 */
final class Capability
{
    public const AccessAdmin = 1 << 16; // may reach the admin area (/api/admin)

    /** First capability bit reserved for downstream packages. Core must stay below this. */
    public const DOWNSTREAM_BIT_START = 18;
}

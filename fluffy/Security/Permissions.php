<?php

namespace Fluffy\Security;

/**
 * Resolver helpers for the UserEntity::$Permissions bitmask.
 *
 * Stored value = role bits (Role::* / app roles) plus any directly-granted
 * capability bits. Effective capabilities = union of every assigned role's
 * registered capabilities (PermissionRegistry) OR any capability bits set
 * directly on the user. SuperAdmin is granted every capability.
 */
final class Permissions
{
    /** Mask covering the role region (bits 0..15). */
    public const ROLE_MASK = 0xFFFF;

    /** Every capability bit (the whole capability region, bits 16..62). */
    public static function allCapabilities(): int
    {
        return PHP_INT_MAX & ~self::ROLE_MASK;
    }

    /**
     * Expand a stored Permissions value into its full set of capability bits.
     */
    public static function effective(int $permissions): int
    {
        // SuperAdmin gets everything — including app capabilities core never heard of.
        if (self::hasRole($permissions, Role::SuperAdmin)) {
            return self::allCapabilities();
        }
        // Capability bits set directly on the user (above the role region).
        $capabilities = $permissions & ~self::ROLE_MASK;
        // Add the capabilities granted by each assigned, registered role.
        foreach (PermissionRegistry::map() as $roleBit => $roleCaps) {
            if (($permissions & $roleBit) === $roleBit) {
                $capabilities |= $roleCaps;
            }
        }
        return $capabilities;
    }

    /** Does this Permissions value grant the given capability? */
    public static function can(int $permissions, int $capability): bool
    {
        return (self::effective($permissions) & $capability) === $capability;
    }

    /** Is the given role bit assigned? */
    public static function hasRole(int $permissions, int $roleBit): bool
    {
        return ($permissions & $roleBit) === $roleBit;
    }

    /** Is any role in the given mask assigned? */
    public static function hasAnyRole(int $permissions, int $roleMask): bool
    {
        return ($permissions & $roleMask) !== 0;
    }

    /** Combine role/capability bits into a single value to store. */
    public static function of(int ...$bits): int
    {
        $value = 0;
        foreach ($bits as $bit) {
            $value |= $bit;
        }
        return $value;
    }

    /** List the registered role bits assigned in this value. */
    public static function roles(int $permissions): array
    {
        $roles = [];
        foreach (PermissionRegistry::map() as $roleBit => $_) {
            if (($permissions & $roleBit) === $roleBit) {
                $roles[] = $roleBit;
            }
        }
        return $roles;
    }

    /**
     * Stable system names of the registered roles assigned in this value,
     * e.g. ['Admin', 'TeamOwner']. For the client (no 64-bit bitmask math in JS).
     * @return string[]
     */
    public static function roleNames(int $permissions): array
    {
        $names = [];
        foreach (PermissionRegistry::roleNames() as $roleBit => $name) {
            if (($permissions & $roleBit) === $roleBit) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Stable names of the effective capabilities this value grants,
     * e.g. ['AccessAdmin', 'CreateShortUrl']. Lets the client gate UI without
     * doing 64-bit bitmask math in JS. Only registered (named) capabilities
     * appear — SuperAdmin therefore lists every registered capability.
     * @return string[]
     */
    public static function capabilityNames(int $permissions): array
    {
        $effective = self::effective($permissions);
        $names = [];
        foreach (PermissionRegistry::capabilityNames() as $capabilityBit => $name) {
            if (($effective & $capabilityBit) === $capabilityBit) {
                $names[] = $name;
            }
        }
        return $names;
    }
}

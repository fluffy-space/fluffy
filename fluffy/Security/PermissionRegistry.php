<?php

namespace Fluffy\Security;

/**
 * Runtime registry mapping role bits to the capability bits they grant.
 *
 * This is the extension seam between packages: core registers its base roles
 * (CorePermissions), and each application contributes its own roles and
 * extends core roles with app capabilities (e.g. the app's PermissionsSetup),
 * all at boot. Permissions::effective() reads this map.
 *
 * Populated once per worker during StartUp::configureServices (which runs
 * before any request); define/extend are idempotent so a double-boot is safe.
 */
final class PermissionRegistry
{
    /** @var array<int,int> roleBit => capabilities mask */
    private static array $roleCapabilities = [];

    /** Set (replace) the capabilities a role grants. */
    public static function define(int $roleBit, int $capabilities): void
    {
        self::$roleCapabilities[$roleBit] = $capabilities;
    }

    /** Add capabilities to a role, keeping whatever was registered before. */
    public static function extend(int $roleBit, int $capabilities): void
    {
        self::$roleCapabilities[$roleBit] = (self::$roleCapabilities[$roleBit] ?? 0) | $capabilities;
    }

    /** @return array<int,int> roleBit => capabilities mask */
    public static function map(): array
    {
        return self::$roleCapabilities;
    }

    /** Drop all registrations (test helper). */
    public static function reset(): void
    {
        self::$roleCapabilities = [];
    }
}

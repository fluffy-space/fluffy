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

    /** @var array<int,string> roleBit => human-readable label */
    private static array $roleLabels = [];

    /** Set (replace) the capabilities a role grants, optionally registering its label. */
    public static function define(int $roleBit, int $capabilities, ?string $label = null): void
    {
        self::$roleCapabilities[$roleBit] = $capabilities;
        if ($label !== null) {
            self::$roleLabels[$roleBit] = $label;
        }
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

    /**
     * Catalog of registered roles in registration order (core first, then app).
     * @return array<int,string> roleBit => label
     */
    public static function roleLabels(): array
    {
        return self::$roleLabels;
    }

    /** Drop all registrations (test helper). */
    public static function reset(): void
    {
        self::$roleCapabilities = [];
        self::$roleLabels = [];
    }
}

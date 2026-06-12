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

    /** @var array<int,string> roleBit => stable system name (e.g. 'TeamOwner') */
    private static array $roleNames = [];

    /** @var array<int,string> capabilityBit => stable name (e.g. 'CreateShortUrl') */
    private static array $capabilityNames = [];

    /**
     * Set (replace) the capabilities a role grants, optionally registering its
     * human-readable label and stable system name (the latter is what gets
     * shipped to the client, which cannot do 64-bit bitmask checks in JS).
     */
    public static function define(int $roleBit, int $capabilities, ?string $label = null, ?string $name = null): void
    {
        self::$roleCapabilities[$roleBit] = $capabilities;
        if ($label !== null) {
            self::$roleLabels[$roleBit] = $label;
        }
        if ($name !== null) {
            self::$roleNames[$roleBit] = $name;
        }
    }

    /**
     * Register a stable name for a capability bit so it can be resolved to a
     * name for the client (which cannot do 64-bit bitmask checks in JS).
     * Core registers its capabilities (bits 16..31); the app registers its own
     * (bits 32..62). Idempotent — safe to call on a double-boot.
     */
    public static function defineCapability(int $capabilityBit, string $name): void
    {
        self::$capabilityNames[$capabilityBit] = $name;
    }

    /** @return array<int,string> capabilityBit => name */
    public static function capabilityNames(): array
    {
        return self::$capabilityNames;
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

    /** @return array<int,string> roleBit => stable system name */
    public static function roleNames(): array
    {
        return self::$roleNames;
    }

    /** Drop all registrations (test helper). */
    public static function reset(): void
    {
        self::$roleCapabilities = [];
        self::$roleLabels = [];
        self::$roleNames = [];
        self::$capabilityNames = [];
    }
}

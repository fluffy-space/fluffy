<?php

namespace Fluffy\Services\Settings;

/**
 * Process-wide (per-worker) registry of code-declared settings, populated at
 * boot by modules calling `define()` — same static-registry pattern as
 * CorePermissions / PermissionRegistry. Holds structure + default only; no DB.
 * Settings NOT registered here can still exist purely in the DB (admin-created,
 * "dynamic") — the registry is a declaration of code-owned settings, not a gate.
 */
class SettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private static array $definitions = [];

    public static function define(SettingDefinition $definition): void
    {
        self::$definitions[$definition->key] = $definition;
    }

    public static function isDefined(string $key): bool
    {
        return isset(self::$definitions[$key]);
    }

    public static function definition(string $key): ?SettingDefinition
    {
        return self::$definitions[$key] ?? null;
    }

    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        return self::$definitions;
    }
}

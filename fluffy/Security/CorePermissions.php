<?php

namespace Fluffy\Security;

/**
 * Registers the framework's default role -> capability grants.
 *
 * Called once at boot from BaseStartUp::configureServices (before the app's
 * StartUp runs, so applications can extend these roles afterwards).
 */
final class CorePermissions
{
    public static function register(): void
    {
        // SuperAdmin is also short-circuited in Permissions::effective; registering
        // it here keeps Permissions::roles()/labels aware of the role.
        PermissionRegistry::define(Role::SuperAdmin, Permissions::allCapabilities(), Role::LABELS[Role::SuperAdmin]);
        PermissionRegistry::define(Role::Admin, Capability::ManageUsers | Capability::AccessAdmin | Capability::ManageRoles, Role::LABELS[Role::Admin]);
        PermissionRegistry::define(Role::User, 0, Role::LABELS[Role::User]);
    }
}

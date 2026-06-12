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
        PermissionRegistry::define(Role::SuperAdmin, Permissions::allCapabilities(), Role::LABELS[Role::SuperAdmin], 'SuperAdmin');
        // Core grants Admin only the admin-area gate; downstream packages extend
        // Admin with their own capabilities (user/role management, CMS, app features).
        PermissionRegistry::define(Role::Admin, Capability::AccessAdmin, Role::LABELS[Role::Admin], 'Admin');
        PermissionRegistry::define(Role::User, 0, Role::LABELS[Role::User], 'User');

        // Name for the core capability bit, so it can be shipped to the client.
        PermissionRegistry::defineCapability(Capability::AccessAdmin, 'AccessAdmin');
    }
}

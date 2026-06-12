<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\Auth\UserEntity;
use Fluffy\Data\Entities\Auth\UserEntityMap;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Migrations\BaseMigration;
use Fluffy\Security\Role;

/**
 * Upgrades databases created before User.Permissions replaced IsAdmin.
 *
 * Fresh installs already get Permissions (and no IsAdmin) from UsersMigration,
 * so every step here is idempotent and a no-op on a fresh schema:
 *   - add Permissions IF NOT EXISTS
 *   - grant the configured admins (config.values['admins']) SuperAdmin
 *   - drop the old IsAdmin column IF EXISTS
 */
class UserPermissionsMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private UserRepository $userRepository, private Config $config)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->userRepository->addColumns([
            UserEntityMap::PROPERTY_Permissions => CommonMap::$BigIntDefault0,
        ], ifNotExists: true);

        // Grant the configured admins the SuperAdmin role (idempotent).
        foreach ($this->config->values['admins'] as $admin) {
            /** @var UserEntity|null $user */
            $user = $this->userRepository->find(UserEntityMap::PROPERTY_Email, $admin['Email']);
            if ($user !== null) {
                $user->Permissions = Role::SuperAdmin;
                $this->userRepository->update($user, [UserEntityMap::PROPERTY_Permissions]);
            }
        }

        // Retire the legacy IsAdmin column.
        $this->userRepository->dropColumns(['IsAdmin']);
    }

    public function down()
    {
        // Restore the legacy column and drop Permissions.
        $this->userRepository->addColumns(['IsAdmin' => CommonMap::$Boolean], ifNotExists: true);
        $this->userRepository->dropColumns([UserEntityMap::PROPERTY_Permissions]);
    }
}

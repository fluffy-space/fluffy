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
 * Adds User.Permissions (bigint bitmask) and grants the configured admins
 * (config.values['admins']) the SuperAdmin role.
 *
 * SuperAdmin is the faithful grant because today IsAdmin gates every /api/admin
 * route (short urls, teams, seed points, system info). IsAdmin is kept for now
 * and dropped in a later migration once authorization is fully on Permissions.
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
        ]);

        // Grant the configured admins the SuperAdmin role.
        foreach ($this->config->values['admins'] as $admin) {
            /** @var UserEntity|null $user */
            $user = $this->userRepository->find(UserEntityMap::PROPERTY_Email, $admin['Email']);
            if ($user !== null) {
                $user->Permissions = Role::SuperAdmin;
                $this->userRepository->update($user, [UserEntityMap::PROPERTY_Permissions]);
            }
        }
    }

    public function down()
    {
        $this->userRepository->dropColumns([UserEntityMap::PROPERTY_Permissions]);
    }
}

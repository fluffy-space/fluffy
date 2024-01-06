<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\Auth\UserEntity;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Migrations\BaseMigration;
use Fluffy\Services\Auth\AuthorizationService;

class UsersMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private UserRepository $userRepository, private Config $config, private AuthorizationService $auth)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->userRepository->createTable(
            [
                'Id' => CommonMap::$Id,
                'UserName' => CommonMap::$VarChar255,
                'FirstName' => CommonMap::$VarChar255Null,
                'LastName' => CommonMap::$VarChar255Null,
                'Email' => CommonMap::$VarChar255Null,
                'Password' => CommonMap::$VarChar255Null,
                'Active' => CommonMap::$Boolean,
                'EmailConfirmed' => CommonMap::$Boolean,
                'CreatedOn' => CommonMap::$MicroDateTime,
                'CreatedBy' => CommonMap::$VarChar255Null,
                'UpdatedOn' => CommonMap::$MicroDateTime,
                'UpdatedBy' => CommonMap::$VarChar255Null,
            ],
            ['Id'],
            [
                'UX_UserName' => [
                    'Columns' => ['UserName'],
                    'Unique' => true
                ]
            ]
        );
        foreach ($this->config->values['admins'] as $user) {
            $admin = new UserEntity();
            $admin->Active = true;
            $admin->Email = $user['Email'];
            $admin->EmailConfirmed = true;
            $admin->FirstName = $user['FirstName'];
            $admin->LastName = $user['LastName'];
            $admin->UserName = $admin->Email;
            $admin->Password = $this->auth->hashPassword($user['Password']);
            $this->userRepository->create($admin);
        }
    }

    public function down()
    {
        $this->userRepository->dropTable(true, true);
    }
}

<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\Auth\UserEntityMap;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserIdentityRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * Sign-in provider accounts linked to a user (provider sign-in, off unless an app turns it on).
 *
 * The FK to `User` CASCADEs, so deleting an account removes its identities with no extra code.
 */
class UserIdentityMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private UserIdentityRepository $userIdentityRepository)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->userIdentityRepository->createTable(
            [
                'Id' => CommonMap::$Id,

                'UserId' => CommonMap::$BigInt,
                'Provider' => CommonMap::$VarChar255,
                'Subject' => CommonMap::$VarChar255,

                'CreatedOn' => CommonMap::$MicroDateTime,
                'CreatedBy' => CommonMap::$VarChar255Null,
                'UpdatedOn' => CommonMap::$MicroDateTime,
                'UpdatedBy' => CommonMap::$VarChar255Null,
            ],
            ['Id'],
            [
                'UX_Provider_Subject' => [
                    'Columns' => ['Provider', 'Subject'],
                    'Unique' => true
                ],
                'IX_UserId' => [
                    'Columns' => ['UserId'],
                    'Unique' => false,
                ]
            ],
            [
                [
                    'Table' => UserEntityMap::class,
                    'Columns' => ['UserId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteCascade
                ]
            ]
        );
    }

    public function down()
    {
        $this->userIdentityRepository->dropTable(true, true);
    }
}

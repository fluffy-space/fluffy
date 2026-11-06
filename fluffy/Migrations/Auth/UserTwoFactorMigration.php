<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\Auth\UserEntityMap;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserRecoveryCodeRepository;
use Fluffy\Data\Repositories\UserTwoFactorRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * Two-factor sign-in: the authenticator secret (one row per user) and the single-use recovery
 * codes. Both FKs to `User` CASCADE, so deleting an account removes its 2FA with no extra code.
 */
class UserTwoFactorMigration extends BaseMigration
{
    function __construct(
        MigrationRepository $MigrationHistoryRepository,
        private UserTwoFactorRepository $twoFactors,
        private UserRecoveryCodeRepository $recoveryCodes
    ) {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $userFk = [
            [
                'Table' => UserEntityMap::class,
                'Columns' => ['UserId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteCascade
            ]
        ];
        $this->twoFactors->createTable(
            [
                'Id' => CommonMap::$Id,

                'UserId' => CommonMap::$BigInt,
                'SecretEncrypted' => CommonMap::$VarChar255,
                'EnabledOn' => CommonMap::$MicroDateTimeNull,
                'LastUsedStep' => CommonMap::$BigIntDefault0,

                'CreatedOn' => CommonMap::$MicroDateTime,
                'CreatedBy' => CommonMap::$VarChar255Null,
                'UpdatedOn' => CommonMap::$MicroDateTime,
                'UpdatedBy' => CommonMap::$VarChar255Null,
            ],
            ['Id'],
            [
                'UX_UserId' => [
                    'Columns' => ['UserId'],
                    'Unique' => true
                ]
            ],
            $userFk
        );
        $this->recoveryCodes->createTable(
            [
                'Id' => CommonMap::$Id,

                'UserId' => CommonMap::$BigInt,
                'CodeHash' => CommonMap::$VarChar255,

                'CreatedOn' => CommonMap::$MicroDateTime,
                'CreatedBy' => CommonMap::$VarChar255Null,
                'UpdatedOn' => CommonMap::$MicroDateTime,
                'UpdatedBy' => CommonMap::$VarChar255Null,
            ],
            ['Id'],
            [
                'UX_UserId_CodeHash' => [
                    'Columns' => ['UserId', 'CodeHash'],
                    'Unique' => true
                ]
            ],
            $userFk
        );
    }

    public function down()
    {
        $this->recoveryCodes->dropTable(true, true);
        $this->twoFactors->dropTable(true, true);
    }
}

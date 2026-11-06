<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class UserRecoveryCodeEntityMap extends BaseEntityMap
{
    public const PROPERTY_UserId = 'UserId';
    public const PROPERTY_CodeHash = 'CodeHash';

    public static string $Table = 'UserRecoveryCode';
    public static array $Indexes = [
        'UX_UserId_CodeHash' => [
            'Columns' => ['UserId', 'CodeHash'],
            'Unique' => true
        ]
    ];
    // The FK to User (ON DELETE CASCADE) is created by UserTwoFactorMigration.
    public static function Columns(): array
    {
        return  [
            'Id' => CommonMap::$Id,

            'UserId' => CommonMap::$BigInt,
            'CodeHash' => CommonMap::$VarChar255,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}

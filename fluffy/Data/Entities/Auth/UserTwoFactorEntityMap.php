<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class UserTwoFactorEntityMap extends BaseEntityMap
{
    public const PROPERTY_UserId = 'UserId';
    public const PROPERTY_SecretEncrypted = 'SecretEncrypted';
    public const PROPERTY_EnabledOn = 'EnabledOn';
    public const PROPERTY_LastUsedStep = 'LastUsedStep';

    public static string $Table = 'UserTwoFactor';
    public static array $Indexes = [
        'UX_UserId' => [
            'Columns' => ['UserId'],
            'Unique' => true
        ]
    ];
    // The FK to User (ON DELETE CASCADE) is created by UserTwoFactorMigration.
    public static function Columns(): array
    {
        return  [
            'Id' => CommonMap::$Id,

            'UserId' => CommonMap::$BigInt,
            'SecretEncrypted' => CommonMap::$VarChar255,
            'EnabledOn' => CommonMap::$MicroDateTimeNull,
            'LastUsedStep' => CommonMap::$BigIntDefault0,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}

<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class UserVerificationCodeEntityMap extends BaseEntityMap
{
    public const PROPERTY_CodeHash = 'CodeHash';

    public static string $Table = 'UserVerificationCode';

    public static array $Indexes = [
        'IX_UserId' => [
            'Columns' => ['UserId'],
            'Unique' => false,
        ],
        'UX_CodeHash' => [
            'Columns' => ['CodeHash'],
            'Unique' => true
        ]
    ];

    public static function Columns(): array
    {
        return  [
            'Id' => CommonMap::$Id,

            'UserId' => CommonMap::$BigInt,
            'CodeHash' => CommonMap::$VarChar255,
            'Expire' => CommonMap::$IntNull,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}

<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class UserTokenEntityMap extends BaseEntityMap
{
    public const PROPERTY_UserId = 'UserId';
    public const PROPERTY_TokenHash = 'TokenHash';

    public static string $Table = 'UserToken';
    public static array $Indexes = [
        'IX_UserId' => [
            'Columns' => ['UserId'],
            'Unique' => false,
        ],
        'UX_TokenHash' => [
            'Columns' => ['TokenHash'],
            'Unique' => true
        ]
    ];
    public static function Columns(): array
    {
        return  [
            'Id' => CommonMap::$Id,

            'UserId' => CommonMap::$BigInt,
            'Token' => CommonMap::$VarChar255,
            'TokenHash' => CommonMap::$VarChar255,
            'Expire' => CommonMap::$IntNull,
            'LastVisit' => CommonMap::$BigIntNull,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}

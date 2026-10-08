<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class UserIdentityEntityMap extends BaseEntityMap
{
    public const PROPERTY_UserId = 'UserId';
    public const PROPERTY_Provider = 'Provider';
    public const PROPERTY_Subject = 'Subject';

    public static string $Table = 'UserIdentity';
    public static array $Indexes = [
        // The sign-in lookup, and what stops one provider account attaching to two users.
        'UX_Provider_Subject' => [
            'Columns' => ['Provider', 'Subject'],
            'Unique' => true
        ],
        'IX_UserId' => [
            'Columns' => ['UserId'],
            'Unique' => false,
        ]
    ];
    // The FK to User (ON DELETE CASCADE) is created by UserIdentityMigration. It is not declared
    // here: nothing include()s a user through an identity, so the map needs no navigation.
    public static function Columns(): array
    {
        return  [
            'Id' => CommonMap::$Id,

            'UserId' => CommonMap::$BigInt,
            'Provider' => CommonMap::$VarChar255,
            // varchar, not citext: a `sub` is case-sensitive.
            'Subject' => CommonMap::$VarChar255,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}

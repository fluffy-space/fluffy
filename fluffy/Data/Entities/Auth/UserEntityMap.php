<?php

namespace Fluffy\Data\Entities\Auth;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class UserEntityMap extends BaseEntityMap
{
    public const PROPERTY_UserName = 'UserName';
    public const PROPERTY_Email = 'Email';
    public const PROPERTY_Phone = 'Phone';
    public const PROPERTY_FirstName = 'FirstName';
    public const PROPERTY_LastName = 'LastName';
    public const PROPERTY_Active = 'Active';
    public const PROPERTY_EmailConfirmed = 'EmailConfirmed';
    public const PROPERTY_Password = 'Password';

    public static string $Table = 'User';

    public static array $Indexes = [
        'UX_UserName' => [
            'Columns' => ['UserName'],
            'Unique' => true
        ],
        'UX_Email' => [
            'Columns' => ['Email'],
            'Unique' => true
        ],
        'UX_Phone' => [
            'Columns' => ['Phone'],
            'Unique' => true
        ]
    ];

    public static function Columns(): array
    {
        return  [
            'Id' => CommonMap::$Id,
            'UserName' => CommonMap::$VarChar255,
            'FirstName' => CommonMap::$TextCaseInsensitiveNull,
            'LastName' => CommonMap::$TextCaseInsensitiveNull,
            'Email' => CommonMap::$VarChar255Null,
            'Phone' => CommonMap::$VarChar255Null,
            'Password' => CommonMap::$VarChar255Null,
            'Active' => CommonMap::$Boolean,
            'EmailConfirmed' => CommonMap::$Boolean,
            'IsAdmin' => CommonMap::$Boolean,
            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}

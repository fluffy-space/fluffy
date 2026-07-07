<?php

namespace Fluffy\Data\Entities\Settings;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class SettingEntityMap extends BaseEntityMap
{
    /** Single cache key the SettingsService reads the whole table under. */
    public const CACHE_KEY = 'Fluffy:Settings';

    public const PROPERTY_Key = 'Key';
    public const PROPERTY_Value = 'Value';
    public const PROPERTY_Type = 'Type';
    public const PROPERTY_Options = 'Options';
    public const PROPERTY_Group = 'Group';
    public const PROPERTY_Label = 'Label';
    public const PROPERTY_Description = 'Description';

    public static string $Table = 'Setting';

    public static array $Indexes = [
        // One row per key.
        'UX_Setting_Key' => [
            'Columns' => ['Key'],
            'Unique' => true,
        ],
    ];

    public static function Columns(): array
    {
        return [
            'Id' => CommonMap::$Id,

            'Key' => CommonMap::$VarChar255,
            'Value' => CommonMap::$TextNull,
            'Type' => CommonMap::$VarChar255,
            'Options' => CommonMap::$TextNull,
            'Group' => CommonMap::$VarChar255Null,
            'Label' => CommonMap::$VarChar255Null,
            'Description' => CommonMap::$TextNull,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}

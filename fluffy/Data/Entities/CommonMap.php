<?php

namespace Fluffy\Data\Entities;

class CommonMap
{
    public static string $OnDeleteNoAction = 'NoAction';
    public static string $OnDeleteCascade = 'Cascade';
    public static string $OnDeleteRestrict = 'Restrict';
    public static string $OnDeleteSetNull = 'SetNull';
    public static string $OnDeleteSetDefault = 'SetDefault';

    public static array $Id =  [
        'type' => 'bigint',
        'null' => false,
        'autoIncrement' => true
    ];

    public static array $IdAllowInsert =  [
        'type' => 'bigint',
        'null' => false,
        'autoIncrement' => true,
        'allowInsert' => true
    ];

    public static array $Int =  [
        'type' => 'integer',
        'null' => false
    ];
    public static array $IntNull =  [
        'type' => 'integer',
        'null' => true
    ];
    public static array $BigInt =  [
        'type' => 'bigint',
        'null' => false
    ];
    public static array $BigIntNull =  [
        'type' => 'bigint',
        'null' => true
    ];
    public static array $MicroDateTime = [
        'type' => 'bigint',
        'null' => false,
    ];
    public static array $MicroDateTimeNull = [
        'type' => 'bigint',
        'null' => true,
    ];
    public static array $TextCaseInsensitive = [
        'type' => 'citext',
        'null' => false,
    ];
    public static array $TextCaseInsensitiveNull = [
        'type' => 'citext',
        'null' => true,
    ];
    public static array $Text = [
        'type' => 'text',
        'null' => false,
    ];
    public static array $TextNull = [
        'type' => 'text',
        'null' => true,
    ];
    // n must be greater than zero and cannot exceed 10,485,760
    public static array $VarChar255Null = [
        'type' => 'character varying',
        'length' => 255,
        'null' => true,
    ];
    public static array $VarChar400Null = [
        'type' => 'character varying',
        'length' => 400,
        'null' => true,
    ];
    // n must be greater than zero and cannot exceed 10,485,760
    public static array $VarChar255 = [
        'type' => 'character varying',
        'length' => 255,
        'null' => false,
    ];
    
    public static array $VarChar400 = [
        'type' => 'character varying',
        'length' => 400,
        'null' => false,
    ];

    public static array $Boolean = [
        'type' => 'boolean',
        'default' => 'false',
        'null' => false,
    ];

    public static array $Byte = [
        'type' => 'bytea',
        'null' => false,
    ];

    public static function VarChar(int $length, bool $null = false)
    {
        return [
            'type' => 'character varying',
            'length' => $length,
            'null' => $null,
        ];
    }
}

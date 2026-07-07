<?php

namespace Fluffy\Migrations\Settings;

use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\SettingRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * Creates the `Setting` table (runtime DB-backed settings store).
 *
 * Columns/PK/indexes are spelled out **inline** — a migration is an immutable
 * snapshot of the schema at this point in time, NOT derived from the current
 * SettingEntityMap. If it read the live map, a later column addition would make
 * a fresh install create the table already containing that column, and the
 * follow-up ALTER migration would then fail (column already exists). Future
 * schema changes get their own ALTER migration.
 */
class SettingMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private SettingRepository $settings)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->settings->createTable(
            [
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
            ],
            ['Id'],
            [
                'UX_Setting_Key' => ['Columns' => ['Key'], 'Unique' => true],
            ]
        );
    }

    public function down()
    {
        $this->settings->dropTable(true, true);
    }
}

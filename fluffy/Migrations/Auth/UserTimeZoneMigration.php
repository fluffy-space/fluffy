<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * `User.TimeZone`: the IANA zone a person reads dates in. Nullable with no default, so adding it
 * is a catalog change with no table rewrite, and every existing user stays NULL (read as UTC)
 * until they or their browser set one.
 */
class UserTimeZoneMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private UserRepository $users)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->users->addColumns([
            'TimeZone' => CommonMap::VarChar(64, true),
        ], ifNotExists: true);
    }

    public function down()
    {
        $this->users->dropColumns(['TimeZone']);
    }
}

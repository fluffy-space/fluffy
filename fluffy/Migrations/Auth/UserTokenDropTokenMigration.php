<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserTokenRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * Drops the plaintext UserToken.Token column.
 *
 * The raw session token is a bearer credential that was stored in the clear
 * beside its sha256 (TokenHash) — the only value auth ever reads. The raw token
 * is now kept only transiently in memory to build the AUTH cookie at creation
 * and is never persisted, so the column was a needless secret at rest (and also
 * purges the existing plaintext tokens). Idempotent (DROP COLUMN IF EXISTS).
 */
class UserTokenDropTokenMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private UserTokenRepository $userTokenRepository)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->userTokenRepository->dropColumns(['Token']);
    }

    public function down()
    {
        // Restore as NULLABLE only — the raw tokens are gone and can't be
        // repopulated, and the original NOT NULL would reject existing rows.
        $this->userTokenRepository->addColumns(['Token' => CommonMap::$VarChar255Null], ifNotExists: true);
    }
}

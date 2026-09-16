<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserVerificationCodeRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * Drops the plaintext UserVerificationCode.Code column, the same treatment
 * {@see UserTokenDropTokenMigration} gave the session token.
 *
 * A verification code resets the password of the account it belongs to, and it was stored twice:
 * once in Code and once in CodeHash, which held the SAME plaintext (createVerificationCode only
 * hashed above 255 characters, and every caller asks for 32). So the table held live
 * account-takeover credentials in the clear for their three-day lifetime.
 *
 * Existing rows are hashed in place BEFORE the column goes, so codes already emailed keep working:
 * PostgreSQL's sha256() over the UTF-8 bytes is byte-identical to PHP's hash('sha256', ...), which
 * is what verifyCode() now looks up. Only rows still holding their plaintext are touched, so
 * re-running this can never hash an already-hashed value.
 */
class UserVerificationCodeHashMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private UserVerificationCodeRepository $codes)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->codes->executeSQL(
            'UPDATE public."UserVerificationCode"'
                . ' SET "CodeHash" = encode(sha256(convert_to("Code", \'UTF8\')), \'hex\')'
                . ' WHERE "CodeHash" = "Code";'
        );
        $this->codes->dropColumns(['Code']);
    }

    public function down()
    {
        // Restore as NULLABLE only: the plaintext codes are gone and cannot be recovered from
        // their hashes, so the original NOT NULL would reject every existing row.
        $this->codes->addColumns(['Code' => CommonMap::$VarChar255Null], ifNotExists: true);
    }
}

<?php

namespace Fluffy\Migrations\Auth;

use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Data\Repositories\UserTokenRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * Two nullable columns on `UserToken` for sessions that came from an organisation's sign-in (SSO):
 * the connection id and when the provider must next confirm the session.
 *
 * Nullable with no default: adding them is a catalog change (no table rewrite, measured 2-10 ms on
 * 200k rows), and every other session leaves them NULL. Measured before building (urlicer
 * docs/sso-plan.md section 4): no difference on the per-request token lookup or on sign-in.
 */
class UserTokenSsoMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private UserTokenRepository $userTokens)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->userTokens->addColumns([
            'SsoConnectionId' => CommonMap::$BigIntNull,
            'SsoCheckDue' => CommonMap::$IntNull,
        ], ifNotExists: true);
    }

    public function down()
    {
        $this->userTokens->dropColumns(['SsoConnectionId', 'SsoCheckDue']);
    }
}

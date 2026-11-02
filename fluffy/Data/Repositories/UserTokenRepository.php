<?php

namespace Fluffy\Data\Repositories;

use Fluffy\Data\Entities\Auth\UserTokenEntity;
use Fluffy\Data\Entities\Auth\UserTokenEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<UserTokenEntity> */
#[Inject(['entityType' => UserTokenEntity::class, 'entityMap' => UserTokenEntityMap::class])]
class UserTokenRepository extends BasePostgresqlRepository
{
    /** Cut a token's life to $graceExpire. Only the first caller matches, so it alone rotates. */
    public function claimRotation(int $id, int $graceExpire): bool
    {
        $table = "{$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\"";
        $this->connector->query("UPDATE $table SET \"Expire\" = $graceExpire WHERE \"Id\" = $id AND \"Expire\" > $graceExpire;");
        return (int) $this->connector->affectedRows() === 1;
    }
}

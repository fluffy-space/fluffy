<?php

namespace Fluffy\Data\Repositories;

use Fluffy\Data\Entities\Auth\UserRecoveryCodeEntity;
use Fluffy\Data\Entities\Auth\UserRecoveryCodeEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<UserRecoveryCodeEntity> */
#[Inject(['entityType' => UserRecoveryCodeEntity::class, 'entityMap' => UserRecoveryCodeEntityMap::class])]
class UserRecoveryCodeRepository extends BasePostgresqlRepository
{
}

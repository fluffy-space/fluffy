<?php

namespace Fluffy\Data\Repositories;

use Fluffy\Data\Entities\Auth\UserTwoFactorEntity;
use Fluffy\Data\Entities\Auth\UserTwoFactorEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<UserTwoFactorEntity> */
#[Inject(['entityType' => UserTwoFactorEntity::class, 'entityMap' => UserTwoFactorEntityMap::class])]
class UserTwoFactorRepository extends BasePostgresqlRepository
{
}

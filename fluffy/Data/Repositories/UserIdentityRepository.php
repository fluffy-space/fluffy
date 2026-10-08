<?php

namespace Fluffy\Data\Repositories;

use Fluffy\Data\Entities\Auth\UserIdentityEntity;
use Fluffy\Data\Entities\Auth\UserIdentityEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<UserIdentityEntity> */
#[Inject(['entityType' => UserIdentityEntity::class, 'entityMap' => UserIdentityEntityMap::class])]
class UserIdentityRepository extends BasePostgresqlRepository
{
}

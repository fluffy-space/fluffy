<?php

namespace Fluffy\Data\Repositories;

use Fluffy\Data\Entities\Settings\SettingEntity;
use Fluffy\Data\Entities\Settings\SettingEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

#[Inject(['entityType' => SettingEntity::class, 'entityMap' => SettingEntityMap::class])]
class SettingRepository extends BasePostgresqlRepository
{
}

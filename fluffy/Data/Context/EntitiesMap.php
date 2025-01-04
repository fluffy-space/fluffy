<?php

namespace Fluffy\Data\Context;

use Fluffy\Data\Entities\Auth\UserEntity;
use Fluffy\Data\Entities\Auth\UserEntityMap;
use Fluffy\Data\Entities\BaseEntityMap;

class EntitiesMap
{
    /**
     * 
     * @var array<BaseEntity|string,BaseEntityMap|string>
     */
    public static array $map = [
        UserEntity::class => UserEntityMap::class
    ];
}

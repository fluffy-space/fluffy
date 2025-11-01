<?php

namespace Fluffy\Data\Repositories;

class MergeOptions
{
    public bool $insertIds = false;

    public function __construct(public array $onCondition, public bool $update = true)
    {        
    }

    public function insertIds($insertIds = true)
    {
        $this->insertIds = $insertIds;
        return $this;
    }
}
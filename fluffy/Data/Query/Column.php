<?php

namespace Fluffy\Data\Query;

class Column
{
    public function __construct(public string $name, public ?string $alias = null) {}
}

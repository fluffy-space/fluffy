<?php

namespace Fluffy\Data\Query;

/** A NULL test in a repository condition list; made by Where::isNull() / Where::isNotNull(). */
final class NullCondition
{
    public function __construct(public readonly string $column, public readonly bool $not)
    {
    }
}

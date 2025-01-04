<?php

namespace Fluffy\Data\Query;

class X
{
    public static function x($condition): Expression
    {
        return new Expression($condition);
    }
}

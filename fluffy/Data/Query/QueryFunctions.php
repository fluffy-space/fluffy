<?php

namespace Fluffy\Data\Query;

class QueryFunctions
{
    static function touch()
    {
        // include file
    }
}


function column(string $name, ?string $alias = null)
{
    return new Column($name, $alias);
}

// short version
function c(string $name, ?string $alias = null)
{
    return new Column($name, $alias);
}


/**
 * 
 * @param string|BaseEntity $entityType 
 * @return Query 
 */
function from($entityType, ?string $alias = null)
{
    return new Query($entityType, null, $alias);
}

/**
 * 
 * @param Column|string|int|float|bool|Expression $left 
 * @param null|string $operatorRaw 
 * @param Column|string|int|float|bool|Expression $right 
 * @return Expression 
 */
function x(
    $left,
    ?string $operatorRaw = null,
    $right = null
) {
    return new Expression($left, $operatorRaw, $right);
}



// $user = from(User::class)
// ->where(x(c('Name'),'=','Admin')->and(c('Id', '>', 0)))
// ->first();
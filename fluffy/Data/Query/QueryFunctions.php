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
 * @param Column|ExpressionGroup|string|int|float|bool|Expression $left 
 * @param null|string $operatorRaw 
 * @param Column|ExpressionGroup|string|int|float|bool|Expression $right 
 * @return Expression 
 */
function x(
    $left,
    ?string $operatorRaw = null,
    $right = null
) {
    return new Expression($left, $operatorRaw, $right);
}

function group()
{
    return new ExpressionGroup();
}

/** `x(c('Col'), 'IS NULL')` by name: `->where(isNull(c('DeletedOn')))`. Repository arrays use Where::isNull(). */
function isNull(Column $column): Expression
{
    return new Expression($column, 'IS NULL');
}

/** `x(c('Col'), 'IS NOT NULL')` by name. Repository arrays use Where::isNotNull(). */
function isNotNull(Column $column): Expression
{
    return new Expression($column, 'IS NOT NULL');
}



// $user = from(User::class)
// ->where(x(c('Name'),'=','Admin')->and(c('Id'), '>', 0))
// ->first();
<?php

namespace Fluffy\Data\Query;

/**
 * A column in a query. Its comparison methods build the same Expression as `x(c('Col'), '=', $v)`, so the
 * two read the same SQL; they only read better, and chain the usual way:
 *
 *     from(UserTeamEntity::class)->where(
 *         c(UserTeamEntityMap::PROPERTY_UserId)->eq($userId)
 *             ->and(c(UserTeamEntityMap::PROPERTY_TeamId)->in($teamIds))
 *     )
 *
 * `in([])` and `notIn([])` stand for FALSE and TRUE: written out, `"Id" IN ()` is a Postgres syntax error.
 */
class Column
{
    public function __construct(public string $name, public ?string $alias = null) {}

    public function eq($value): Expression
    {
        return new Expression($this, '=', $value);
    }

    public function ne($value): Expression
    {
        return new Expression($this, '<>', $value);
    }

    public function gt($value): Expression
    {
        return new Expression($this, '>', $value);
    }

    public function gte($value): Expression
    {
        return new Expression($this, '>=', $value);
    }

    public function lt($value): Expression
    {
        return new Expression($this, '<', $value);
    }

    public function lte($value): Expression
    {
        return new Expression($this, '<=', $value);
    }

    public function in(array $values): Expression
    {
        return $values === [] ? new Expression(false) : new Expression($this, 'IN', array_values($values));
    }

    public function notIn(array $values): Expression
    {
        return $values === [] ? new Expression(true) : new Expression($this, 'NOT IN', array_values($values));
    }

    /** The pattern as given: escape a person's text with Search::contains() first. */
    public function like(string $pattern): Expression
    {
        return new Expression($this, 'LIKE', $pattern);
    }

    /** Case-insensitive LIKE; same escaping rule. */
    public function ilike(string $pattern): Expression
    {
        return new Expression($this, 'ILIKE', $pattern);
    }

    public function isNull(): Expression
    {
        return new Expression($this, 'IS NULL');
    }

    public function isNotNull(): Expression
    {
        return new Expression($this, 'IS NOT NULL');
    }
}

<?php

namespace Fluffy\Data\Query;

class ExpressionTuple
{
    public function __construct(
        public Expression $expression,
        public $glueOperator = 'and'
    ) {}
}

class Expression
{

    /**
     * 
     * @var ExpressionTuple[]
     */
    public $children = [];

    /**
     * 
     * @param Column|string|int|float|bool|Expression $left 
     * @param null|string $operatorRaw 
     * @param Column|string|int|float|bool|Expression $right 
     * @return Expression 
     */
    public function __construct(
        public $left,
        public ?string $operatorRaw = null,
        public $right = null
    ) {}

    /**
     * 
     * @param Column|string|int|float|bool|Expression $left 
     * @param null|string $operatorRaw 
     * @param Column|string|int|float|bool|Expression $right 
     * @return Expression 
     */
    public function and(
        $left,
        ?string $operatorRaw = null,
        $right = null
    ) {
        $this->children[] = new ExpressionTuple(
            $left instanceof Expression ? $left
                : new Expression(
                    $left,
                    $operatorRaw,
                    $right
                ),
            'and'
        );
        return $this;
    }

    /**
     * 
     * @param Column|string|int|float|bool|Expression $left 
     * @param null|string $operatorRaw 
     * @param Column|string|int|float|bool|Expression $right 
     * @return Expression 
     */
    public function or(
        $left,
        ?string $operatorRaw = null,
        $right = null
    ) {
        $this->children[] = new ExpressionTuple(
            $left instanceof Expression ? $left
                : new Expression(
                    $left,
                    $operatorRaw,
                    $right
                ),
            'or'
        );
        return $this;
    }
}

class Column
{
    function __construct(public $names) {}
}

function c($any)
{
    return new Column($any);
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

function compileExpression(Expression $expression)
{
    $raw = '';
    if ($expression->left instanceof Column) {
        $raw .= "\"{$expression->left->names}\"";
    } elseif ($expression->left instanceof Expression) {
        $raw .= compileExpression($expression->left);
    } else {
        $raw .= $expression->left;
    }

    if ($expression->operatorRaw) {
        $raw .= ' ' . $expression->operatorRaw . ' ';
    }

    if ($expression->right) {
        if ($expression->right instanceof Column) {
            $raw .= "\"{$expression->right->names}\"";
        } elseif ($expression->right instanceof Expression) {
            $raw .= compileExpression($expression->right);
        } else {
            $raw .= $expression->right;
        }
    }

    foreach ($expression->children as $child) {
        $raw .= ' ' . $child->glueOperator . ' ';
        $enclose = count($child->expression->children) > 0;
        if ($enclose) {
            $raw .= '(';
        }
        $raw .= compileExpression($child->expression);
        if ($enclose) {
            $raw .= ')';
        }
    }

    return $raw;
}

// $user = from(User::class)
// ->where(x(c('Name'),'=','Admin')->and(c('Id', '>', 0)))
// ->first();
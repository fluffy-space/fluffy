<?php

namespace Fluffy\Data\Query;

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
            'AND'
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
            'OR'
        );
        return $this;
    }

    function printExpression()
    {
        $raw = '';
        if ($this->left instanceof Column) {
            $raw .= "\"{$this->left->name}\"";
        } elseif ($this->left instanceof Expression) {
            $raw .= $this->printExpression($this->left);
        } else {
            $raw .= $this->left;
        }

        if ($this->operatorRaw) {
            $raw .= ' ' . $this->operatorRaw . ' ';
        }

        if ($this->right) {
            if ($this->right instanceof Column) {
                $raw .= "\"{$this->right->name}\"";
            } elseif ($this->right instanceof Expression) {
                $raw .= $this->printExpression($this->right);
            } else {
                $raw .= $this->right;
            }
        }

        foreach ($this->children as $child) {
            $raw .= ' ' . $child->glueOperator . ' ';
            $enclose = count($child->expression->children) > 0;
            if ($enclose) {
                $raw .= '(';
            }
            $raw .= $this->printExpression($child->expression);
            if ($enclose) {
                $raw .= ')';
            }
        }

        return $raw;
    }
}

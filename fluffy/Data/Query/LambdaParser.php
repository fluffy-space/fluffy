<?php

namespace Fluffy\Data\Query;

use Fluffy\Data\Entities\BaseEntity;

class LambdaParser
{
}

$applicationId = 5;

$expr = fn(ApplicationServerEntity $x) => $x->ApplicationId === $applicationId;

$querySource = function ($query, ApplicationServerEntity $x, ServerEntity $y) use ($applicationId) {
    $query->from($x)
        ->join($y)
        ->on($x->ServerId === $y->Id)
        ->where($x->ApplicationId === $applicationId)
        ->orderBy($x->CreatedOn)
        ->select($x, $y);
};

$querySource2 = function ($query) use ($applicationId) {
    $query->from(ApplicationServerEntity::class)
        ->join(ServerEntity::class)
        ->on(fn(ApplicationServerEntity $x, ServerEntity $y) => $x->ServerId === $y->Id)
        ->where(fn(ApplicationServerEntity $x, ServerEntity $y) => $x->ApplicationId === $applicationId)
        ->orderBy(fn(ApplicationServerEntity $x, ServerEntity $y) => $x->CreatedOn)
        ->select(fn(ApplicationServerEntity $x, ServerEntity $y) => [$x, $y]);
};

/**
 * 
 * @param \PhpParser\Node\Stmt[] $stmt 
 * @return void 
 */
function findClosure($stmt, int $startLine, int $endLine)
{
    foreach ($stmt as $node) {
        if ($node->getStartLine() === $startLine || $node->getEndLine() === $endLine) {
            // print_r($node);
            print_r(['Node has been found', $node->getStartFilePos()]);
        }
        if (isset($node->stmts)) {
            findClosure($node->stmts, $startLine, $endLine);
        }
    }
}

function functionToExpression($expr)
{
    $rf = new ReflectionFunction($expr);
    print_r([$rf, $expr, spl_object_id($expr), $rf->getFileName()]);
    print_r($rf->getClosureUsedVariables());
    print_r($rf->getParameters());
    //print_r([$rf->getParameters()[0]->getType()->getName()]);

    $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
    try {
        $code = file_get_contents($rf->getFileName());
        //$code = substr($code, $rf->getStartLine(), $rf->getEndLine() - $rf->getStartLine());
        // print_r([$code]);
        $ast = $parser->parse($code);
        findClosure($ast, $rf->getStartLine(), $rf->getEndLine());
    } catch (Error $error) {
        echo "Parse error: {$error->getMessage()}\n";
    }
}

//functionToExpression($expr);
functionToExpression($querySource);
<?php

namespace Fluffy\Data\Query;

/**
 * Helpers for the array conditions the repositories take (`find`, `search`, `updateWhere`,
 * `deleteWhere`, ...), for what the plain `[column, value]` / `[column, operator, value]` shape
 * cannot say well. SQL has no `= NULL`: a row with a NULL column is matched by IS NULL only.
 *
 *     $repository->updateWhere(['ConfirmedOn' => $now], [
 *         ['Id', $id],
 *         Where::isNull('ConfirmedOn'),
 *         [Where::isNull('ConfirmSentOn'), ['ConfirmSentOn', '<', $cutoff]],   // an OR group
 *     ]);
 *
 * For the query builder (`from()->where(...)`) the same is `isNull(c('ConfirmedOn'))`
 * (QueryFunctions.php).
 */
class Where
{
    /** `"column" IS NULL` */
    public static function isNull(string $column): NullCondition
    {
        return new NullCondition($column, false);
    }

    /** `"column" IS NOT NULL` */
    public static function isNotNull(string $column): NullCondition
    {
        return new NullCondition($column, true);
    }
}

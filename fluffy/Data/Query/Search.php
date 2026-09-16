<?php

namespace Fluffy\Data\Query;

/**
 * Free-text search terms, made safe to put in a LIKE / ILIKE pattern.
 *
 * Values are already escaped as SQL LITERALS by the connector (PDO::quote / PQescapeLiteral), so a
 * quote in a search box was never an injection. What no literal escape touches is LIKE's own
 * pattern language: `%` and `_` stay wildcards INSIDE the quoted string. A search for `%` then
 * matches every row, `_` matches any single character, and a code lookup written as
 * `LIKE '%:' . $code` resolves to a row the caller never named.
 *
 * escape() prefixes the three characters LIKE gives meaning to — `\`, `%`, `_` — with a backslash,
 * which is LIKE's default escape character (no ESCAPE clause needed). Verified against PostgreSQL
 * with standard_conforming_strings=on: PDO::quote passes a backslash through untouched, and
 * PQescapeLiteral emits an E'' literal with the backslash doubled, which un-escapes to the same
 * single backslash. Both reach the matcher as an escape, not as data.
 *
 * terms() is the other half: it bounds the input. Every caller splits a search box on whitespace
 * and ORs one LIKE per part per column, so an unbounded string is an unbounded number of scans of
 * a backtracking matcher — which is a cheap way for one request to occupy a worker.
 */
class Search
{
    /** Longest search string considered; the rest is dropped. */
    public const MAX_LENGTH = 200;

    /** Most whitespace-separated parts considered; the rest are dropped. */
    public const MAX_PARTS = 8;

    /** Escape LIKE's wildcards so the term matches itself, literally. */
    public static function escape(string $term): string
    {
        // Backslash FIRST: escaping it after the wildcards would escape the backslashes they added.
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * `%term%` — the "contains" pattern every search box builds. Bounded to MAX_LENGTH: this is
     * the free-text helper, so the cap belongs here too — several callers pass the whole search
     * box straight in as one pattern rather than splitting it through terms() first.
     * startsWith() / endsWith() are NOT capped: those match a known prefix or suffix, not prose.
     */
    public static function contains(string $term): string
    {
        if (mb_strlen($term) > self::MAX_LENGTH) {
            $term = mb_substr($term, 0, self::MAX_LENGTH);
        }
        return '%' . self::escape($term) . '%';
    }

    /** `%term` — matches rows ENDING with the term. */
    public static function endsWith(string $term): string
    {
        return '%' . self::escape($term);
    }

    /** `term%` — matches rows STARTING with the term. */
    public static function startsWith(string $term): string
    {
        return self::escape($term) . '%';
    }

    /**
     * A search box's value as bounded, non-empty parts: trimmed, cut to MAX_LENGTH, split on
     * whitespace, capped at MAX_PARTS. Returns [] for null / blank input, so callers can treat
     * "no parts" as "no search".
     *
     * @return string[]
     */
    public static function terms(?string $search, int $maxParts = self::MAX_PARTS, int $maxLength = self::MAX_LENGTH): array
    {
        $search = trim($search ?? '');
        if ($search === '') {
            return [];
        }
        if (mb_strlen($search) > $maxLength) {
            $search = mb_substr($search, 0, $maxLength);
        }
        $parts = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return count($parts) > $maxParts ? array_slice($parts, 0, $maxParts) : $parts;
    }
}

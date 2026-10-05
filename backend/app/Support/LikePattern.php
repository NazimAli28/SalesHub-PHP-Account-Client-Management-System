<?php

namespace App\Support;

/**
 * Builds LIKE patterns from user input. The wildcards `%` and `_` (and the escape character itself)
 * are escaped with "!", which works on MySQL and SQLite; queries must add `escape '!'`.
 */
final class LikePattern
{
    public const ESCAPE = '!';

    /** @var list<string> */
    private const SPECIAL = ['!', '%', '_'];

    public static function escape(string $term): string
    {
        return str_replace(self::SPECIAL, array_map(fn (string $c): string => self::ESCAPE.$c, self::SPECIAL), $term);
    }

    /**
     * "Contains" pattern: %term% with the term's wildcards escaped.
     */
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }
}

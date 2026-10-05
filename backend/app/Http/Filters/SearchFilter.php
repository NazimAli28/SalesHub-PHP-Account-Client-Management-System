<?php

namespace App\Http\Filters;

use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * `filter[search]=term`: case-insensitive "contains" over several columns, OR-ed together.
 * A dotted column ("client.discord_username") searches through a relation with whereHas.
 * `%` and `_` in the term match literally; terms longer than {@see self::MAX_LENGTH} characters are
 * rejected (422 on `filter.search`).
 *
 * Usage: AllowedFilter::custom('search', new SearchFilter(['last_message', 'client.discord_username']))
 *
 * @implements Filter<Model>
 */
final class SearchFilter implements Filter
{
    public const MAX_LENGTH = 100;

    /**
     * @param  list<string>  $columns
     */
    public function __construct(private readonly array $columns) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        // The query builder splits filter values on commas; a search term is a single string.
        $term = trim(is_array($value) ? implode(',', $value) : (string) $value);

        if ($term === '') {
            return;
        }

        if (mb_strlen($term) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'filter.'.$property => ['The search term may not be longer than '.self::MAX_LENGTH.' characters.'],
            ]);
        }

        $like = LikePattern::contains($term);

        $query->where(function (Builder $q) use ($like): void {
            foreach ($this->columns as $column) {
                if (str_contains($column, '.')) {
                    $relation = Str::beforeLast($column, '.');
                    $attribute = Str::afterLast($column, '.');
                    $q->orWhereHas($relation, fn (Builder $r) => self::whereLike($r, $attribute, $like, 'and'));
                } else {
                    self::whereLike($q, $column, $like, 'or');
                }
            }
        });
    }

    /**
     * `column LIKE ? ESCAPE '!'`. Column names come from the filter's own definition, never from the
     * request; only the pattern is bound.
     *
     * @param  Builder<Model>  $query
     */
    private static function whereLike(Builder $query, string $column, string $like, string $boolean): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));

        // @phpstan-ignore argument.type (the wrapped column name is a plain string, not a literal-string)
        $query->whereRaw($wrapped." like ? escape '".LikePattern::ESCAPE."'", [$like], $boolean);
    }
}

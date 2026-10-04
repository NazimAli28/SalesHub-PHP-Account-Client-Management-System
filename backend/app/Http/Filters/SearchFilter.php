<?php

namespace App\Http\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * `filter[search]=term`: case-insensitive "contains" over several columns, OR-ed together.
 * A dotted column ("client.discord_username") searches through a relation with whereHas.
 *
 * Usage: AllowedFilter::custom('search', new SearchFilter(['last_message', 'client.discord_username']))
 *
 * @implements Filter<Model>
 */
final class SearchFilter implements Filter
{
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

        $like = '%'.$term.'%';

        $query->where(function (Builder $q) use ($like): void {
            foreach ($this->columns as $column) {
                if (str_contains($column, '.')) {
                    $relation = Str::beforeLast($column, '.');
                    $attribute = Str::afterLast($column, '.');
                    $q->orWhereHas($relation, fn (Builder $r) => $r->where($r->qualifyColumn($attribute), 'like', $like));
                } else {
                    $q->orWhere($q->qualifyColumn($column), 'like', $like);
                }
            }
        });
    }
}

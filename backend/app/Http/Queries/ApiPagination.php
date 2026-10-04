<?php

namespace App\Http\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Pagination for every index endpoint: `page[number]` (default 1) and `page[size]` (default 25, max 100).
 * A plain `?page=2` is accepted as the page number too. Links keep the current filters, sorts and includes.
 */
final class ApiPagination
{
    public const DEFAULT_SIZE = 25;

    public const MAX_SIZE = 100;

    /**
     * @template TModel of Model
     *
     * @param  QueryBuilder<TModel>|Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(QueryBuilder|Builder $query, Request $request): LengthAwarePaginator
    {
        $size = self::size($request);

        /** @var LengthAwarePaginator<int, TModel> $paginator */
        $paginator = $query->paginate($size, ['*'], 'page[number]', self::number($request));

        return $paginator
            ->appends(Arr::except($request->query(), ['page']))
            ->appends('page[size]', (string) $size);
    }

    public static function size(Request $request): int
    {
        $page = $request->query('page');
        $size = is_array($page) ? ($page['size'] ?? null) : null;

        if (! is_numeric($size)) {
            return self::DEFAULT_SIZE;
        }

        return max(1, min(self::MAX_SIZE, (int) $size));
    }

    public static function number(Request $request): int
    {
        $page = $request->query('page');
        $number = is_array($page) ? ($page['number'] ?? null) : $page;

        return is_numeric($number) ? max(1, (int) $number) : 1;
    }
}

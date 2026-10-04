<?php

namespace App\Http\Queries;

use App\Http\Filters\SearchFilter;
use App\Models\Service;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/services
 *
 * filter[category]=emotes,overlays  filter[active]=true|false  filter[search]=text (name, slug, description)
 * sort=name (default) | category | base_price_cents | created_at | id
 *
 * The catalog is not scoped: every signed-in role may read it (services.view).
 */
final class ServiceIndexQuery
{
    /**
     * @return QueryBuilder<Service>
     */
    public static function make(Request $request): QueryBuilder
    {
        return QueryBuilder::for(Service::query(), $request)
            ->allowedFilters(
                AllowedFilter::exact('category'),
                AllowedFilter::exact('active', 'is_active'),
                AllowedFilter::custom('search', new SearchFilter(['name', 'slug', 'description'])),
            )
            ->allowedSorts('name', 'category', 'base_price_cents', 'created_at', 'id')
            ->defaultSort('name', 'id');
    }
}

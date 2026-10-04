<?php

namespace App\Http\Queries;

use App\Http\Filters\SearchFilter;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * GET /api/social-accounts
 *
 * filter[platform]=instagram,x  filter[in_use]=1|0  filter[platform_account]=3  filter[search]=text
 * sort=-created_at (default) | created_on | username | platform | is_in_use | id
 * include=platformAccount
 *
 * Always starts from visibleTo(user) (visibility follows the parent platform account).
 */
final class SocialAccountIndexQuery
{
    /**
     * @return QueryBuilder<SocialAccount>
     */
    public static function make(Request $request): QueryBuilder
    {
        /** @var User $user */
        $user = $request->user();

        return QueryBuilder::for(SocialAccount::query()->visibleTo($user)->with(SocialAccountResource::DEFAULT_WITH), $request)
            ->allowedFilters(
                AllowedFilter::exact('platform'),
                AllowedFilter::exact('platform_account', 'platform_account_id'),
                AllowedFilter::callback('in_use', function (Builder $query, mixed $value): void {
                    $query->where('is_in_use', filter_var(is_array($value) ? end($value) : $value, FILTER_VALIDATE_BOOLEAN));
                }),
                AllowedFilter::custom('search', new SearchFilter(['username', 'login_email', 'platformAccount.email', 'platformAccount.discord_username'])),
            )
            ->allowedSorts('created_at', 'created_on', 'username', 'platform', 'is_in_use', 'id')
            ->defaultSort('-created_at', '-id')
            ->allowedIncludes(...SocialAccountResource::INCLUDES);
    }
}

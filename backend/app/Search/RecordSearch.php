<?php

namespace App\Search;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;
use App\Models\PlatformAccount;
use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Global record search behind the Ctrl+K palette. Each group is searched only when the user may view
 * that module, over visibleTo($user) rows, and matches identifier columns only: never credentials.
 */
final class RecordSearch
{
    public const PER_GROUP = 5;

    /**
     * @return list<array{key: string, label: string, hits: list<array{type: string, id: int, title: string, subtitle: string|null, url: string}>}>
     */
    public function search(User $user, string $term): array
    {
        $like = '%'.$this->escape($term).'%';

        $groups = [
            ['clients', 'Clients', Client::class, fn (): array => $this->clients($user, $like)],
            ['leads', 'Leads', Lead::class, fn (): array => $this->leads($user, $like)],
            ['orders', 'Orders', Order::class, fn (): array => $this->orders($user, $like)],
            ['platform-accounts', 'Platform accounts', PlatformAccount::class, fn (): array => $this->platformAccounts($user, $like)],
        ];

        $result = [];

        foreach ($groups as [$key, $label, $model, $hits]) {
            if (Gate::forUser($user)->allows('viewAny', $model)) {
                $result[] = ['key' => $key, 'label' => $label, 'hits' => $hits()];
            }
        }

        return $result;
    }

    /**
     * Escapes LIKE wildcards in user input (the escape character is "!", which works on MySQL and SQLite).
     */
    public function escape(string $term): string
    {
        return LikePattern::escape($term);
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     */
    private function matching(Builder $query, array $columns, string $like): void
    {
        $query->where(function (Builder $q) use ($columns, $like): void {
            foreach ($columns as $column) {
                // Column names come from this class's own constants, never from user input; only the LIKE term is bound.
                // @phpstan-ignore argument.type (the qualified column name is a plain string, not a literal-string)
                $q->orWhereRaw($q->getModel()->qualifyColumn($column)." like ? escape '!'", [$like]);
            }
        });
    }

    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string|null, url: string}>
     */
    private function clients(User $user, string $like): array
    {
        $query = Client::query()->visibleTo($user);
        $this->matching($query, ['name', 'discord_username'], $like);

        return array_values($query->orderBy('name')->limit(self::PER_GROUP)->get(['id', 'name', 'discord_username', 'email'])
            ->map(fn (Client $c): array => [
                'type' => 'client',
                'id' => $c->id,
                'title' => $c->name ?: $c->discord_username,
                'subtitle' => $c->name ? $c->discord_username : $c->email,
                'url' => '/clients/'.$c->id,
            ])->all());
    }

    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string|null, url: string}>
     */
    private function leads(User $user, string $like): array
    {
        $query = Lead::query()->visibleTo($user)->with('client:id,name,discord_username');
        $query->whereHas('client', fn (Builder $c) => $this->matching($c, ['name', 'discord_username'], $like));

        return array_values($query->latest('contacted_on')->latest('id')->limit(self::PER_GROUP)->get()
            ->map(function (Lead $l): array {
                $discord = $l->client === null ? '' : $l->client->discord_username;

                return [
                    'type' => 'lead',
                    'id' => $l->id,
                    'title' => $l->client === null ? '' : ($l->client->name ?: $discord),
                    'subtitle' => $l->stage->label().' lead, '.$discord,
                    'url' => '/leads?search='.rawurlencode($discord),
                ];
            })->all());
    }

    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string|null, url: string}>
     */
    private function orders(User $user, string $like): array
    {
        $query = Order::query()->visibleTo($user)->with('client:id,name,discord_username');
        $query->where(function (Builder $q) use ($like): void {
            $this->matching($q, ['order_number'], $like);
            $q->orWhereHas('client', fn (Builder $c) => $this->matching($c, ['name', 'discord_username'], $like));
        });

        return array_values($query->latest('ordered_on')->latest('id')->limit(self::PER_GROUP)->get()
            ->map(fn (Order $o): array => [
                'type' => 'order',
                'id' => $o->id,
                'title' => $o->order_number,
                'subtitle' => $o->client?->name ?: $o->client?->discord_username,
                'url' => '/orders/'.$o->id,
            ])->all());
    }

    /**
     * Only the account's identifiers (login email, Discord username); passwords and recovery data are never selected.
     *
     * @return list<array{type: string, id: int, title: string, subtitle: string|null, url: string}>
     */
    private function platformAccounts(User $user, string $like): array
    {
        $query = PlatformAccount::query()->visibleTo($user);
        $this->matching($query, ['email', 'discord_username'], $like);

        return array_values($query->orderBy('email')->limit(self::PER_GROUP)->get(['id', 'email', 'discord_username', 'standing'])
            ->map(fn (PlatformAccount $a): array => [
                'type' => 'platform_account',
                'id' => $a->id,
                'title' => $a->email,
                'subtitle' => $a->discord_username,
                'url' => '/platform-accounts/'.$a->id,
            ])->all());
    }
}

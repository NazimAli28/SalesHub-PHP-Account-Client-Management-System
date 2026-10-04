<?php

namespace App\Exports;

use App\Enums\ImportType;
use App\Http\Queries\ClientIndexQuery;
use App\Http\Queries\LeadIndexQuery;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * What a CSV export contains: the index endpoint's filtered, sorted, visibility-scoped query plus the
 * columns. Hidden or encrypted attributes (credentials) are never part of a column list.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $headings
     * @param  QueryBuilder<Model>  $query
     */
    private function __construct(
        public readonly array $headings,
        public readonly QueryBuilder $query,
    ) {}

    public static function for(ImportType $type, Request $request): self
    {
        return match ($type) {
            ImportType::Leads => self::leads($request),
            ImportType::Clients => self::clients($request),
        };
    }

    /**
     * @return array<int, mixed>
     */
    public function row(Model $model): array
    {
        return match (true) {
            $model instanceof Lead => self::leadRow($model),
            $model instanceof Client => self::clientRow($model),
            default => [],
        };
    }

    private static function leads(Request $request): self
    {
        /** @var QueryBuilder<Model> $query */
        $query = LeadIndexQuery::make($request)->with(['client', 'owner', 'services']);

        return new self(
            ['Id', 'Client Discord username', 'Client name', 'Client email', 'Owner', 'Stage', 'Contacted on', 'Estimated value', 'Currency', 'Last message', 'Next follow-up on', 'Lost reason', 'Lost note', 'Services', 'Created at'],
            $query,
        );
    }

    /**
     * @return array<int, mixed>
     */
    private static function leadRow(Lead $lead): array
    {
        return [
            $lead->id,
            $lead->client->discord_username ?? '',
            $lead->client->name ?? '',
            $lead->client->email ?? '',
            $lead->owner->username ?? '',
            $lead->stage->value,
            $lead->contacted_on->format('Y-m-d'),
            self::money($lead->estimated_value_cents),
            $lead->currency,
            $lead->last_message,
            $lead->next_follow_up_on?->format('Y-m-d'),
            $lead->lost_reason?->value,
            $lead->lost_note,
            $lead->services->pluck('name')->implode('; '),
            $lead->created_at->toIso8601String(),
        ];
    }

    private static function clients(Request $request): self
    {
        /** @var QueryBuilder<Model> $query */
        $query = ClientIndexQuery::make($request)->with(['owner']);

        return new self(
            ['Id', 'Discord username', 'Name', 'Email', 'Payment name', 'Country', 'Owner', 'Status', 'Nurturing rating', 'Next upsell plan', 'Expected upsell on', 'Notes', 'Lifetime value (USD)', 'Created at'],
            $query,
        );
    }

    /**
     * @return array<int, mixed>
     */
    private static function clientRow(Client $client): array
    {
        return [
            $client->id,
            $client->discord_username,
            $client->name,
            $client->email,
            $client->payment_name,
            $client->country,
            $client->owner->username ?? '',
            $client->status->value,
            $client->nurturing_rating,
            $client->next_upsell_plan,
            $client->expected_upsell_on?->format('Y-m-d'),
            $client->getAttributes()['notes'] ?? null,
            self::money((int) ($client->getAttributes()['lifetime_value_cents'] ?? 0)),
            $client->created_at->toIso8601String(),
        ];
    }

    private static function money(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}

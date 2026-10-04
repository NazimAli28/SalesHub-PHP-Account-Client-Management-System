<?php

namespace App\Http\Resources\Concerns;

use App\Http\Resources\Summaries\UserSummaryResource;
use App\Models\ApprovalRequest;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Str;

/**
 * Shared value formats for API Resources (docs/api/conventions.md): enums `{value, label}`,
 * money `{amount_cents, currency, formatted}`, dates `YYYY-MM-DD`, timestamps ISO 8601 UTC,
 * and the `pending_change` block of approvable models.
 */
trait FormatsApiValues
{
    /**
     * @return array{value: string|int, label: string}|null
     */
    protected function enum(?BackedEnum $enum): ?array
    {
        if ($enum === null) {
            return null;
        }

        return [
            'value' => $enum->value,
            'label' => method_exists($enum, 'label') ? (string) $enum->label() : Str::headline($enum->name),
        ];
    }

    /**
     * @return array{amount_cents: int, currency: string, formatted: string}|null
     */
    protected function money(?int $cents, ?string $currency): ?array
    {
        return $cents === null ? null : Money::toArray($cents, $currency ?? 'USD');
    }

    /**
     * Calendar date (contact dates, due dates): `2026-10-04`.
     */
    protected function date(?CarbonInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    /**
     * Point in time, always UTC: `2026-10-04T08:12:00Z`.
     */
    protected function dateTime(?CarbonInterface $dateTime): ?string
    {
        return $dateTime?->toIso8601ZuluString();
    }

    /**
     * `pending_change` for models using HasApprovals. Present only when `pendingApproval.requester` was eager-loaded.
     *
     * @return MissingValue|array<string, mixed>|null
     */
    protected function pendingChange(): MissingValue|array|null
    {
        if (! $this->resource->relationLoaded('pendingApproval')) {
            return new MissingValue;
        }

        $pending = $this->resource->getRelation('pendingApproval');

        if (! $pending instanceof ApprovalRequest) {
            return null;
        }

        return [
            'id' => $pending->id,
            'action' => $this->enum($pending->action),
            'fields' => $pending->changedFields(),
            'requested_by' => UserSummaryResource::make($pending->requester),
            'requested_at' => $this->dateTime($pending->created_at),
        ];
    }
}

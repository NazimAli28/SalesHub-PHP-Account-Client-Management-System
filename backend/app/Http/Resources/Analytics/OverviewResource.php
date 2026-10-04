<?php

namespace App\Http\Resources\Analytics;

use App\Models\Payment;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the array built by App\Analytics\OverviewReport. Money is `{amount_cents, currency, formatted}`,
 * enums `{value, label}`, calendar dates `YYYY-MM-DD`. Series points stay plain cents (chart friendly).
 *
 * @property array<string, mixed> $resource
 */
class OverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $currency = (string) $data['currency'];
        $kpis = $data['kpis'];

        $moneyKpi = fn (array $kpi): array => [
            'value' => $this->money((int) $kpi['value'], $currency),
            'previous' => $this->money((int) $kpi['previous'], $currency),
            'change_pct' => $kpi['change_pct'],
        ];

        return [
            'range' => [
                'from' => $this->date($data['range']['from']),
                'to' => $this->date($data['range']['to']),
                'previous_from' => $this->date($data['range']['previous_from']),
                'previous_to' => $this->date($data['range']['previous_to']),
                'bucket' => $data['range']['bucket'],
                'days' => $data['range']['days'],
            ],
            'currency' => $currency,
            'kpis' => [
                'revenue_collected' => $moneyKpi($kpis['revenue_collected']),
                'won_value' => $moneyKpi($kpis['won_value']),
                'won_count' => $kpis['won_count'],
                'new_leads' => $kpis['new_leads'],
                'conversion_rate' => $kpis['conversion_rate'],
                'average_order_value' => $moneyKpi($kpis['average_order_value']),
                'overdue_payments' => [
                    'count' => $kpis['overdue_payments']['count'],
                    'amount' => $this->money($kpis['overdue_payments']['amount_cents'], $currency),
                ],
                'pending_approvals' => $kpis['pending_approvals'],
                'active_clients' => $kpis['active_clients'],
            ],
            'funnel' => array_map(fn (array $row): array => [
                'stage' => $this->enum($row['stage']),
                'count' => $row['count'],
            ], $data['funnel']),
            'revenue_series' => $data['revenue_series'],
            'leaderboard' => $data['leaderboard'] === null ? null : array_map(fn (array $row): array => [
                'user' => $row['user'],
                'collected' => $this->money($row['collected_cents'], $currency),
                'won_leads' => $row['won_leads'],
            ], $data['leaderboard']),
            'account_health' => $data['account_health'] === null ? null : array_map(fn (array $row): array => [
                'standing' => $this->enum($row['standing']),
                'count' => $row['count'],
            ], $data['account_health']),
            'upcoming_payments' => array_map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'order' => [
                    'id' => $payment->order_id,
                    'order_number' => $payment->order?->order_number,
                    'client_name' => $payment->order?->client?->name,
                ],
                'amount' => $this->money($payment->amount_cents, $payment->currency),
                'due_date' => $this->date($payment->due_date),
            ], $data['upcoming_payments']),
        ];
    }

    /**
     * @return array{amount_cents: int, currency: string, formatted: string}
     */
    private function money(int $cents, string $currency): array
    {
        return Money::toArray($cents, $currency);
    }

    /**
     * @return array{value: string|int, label: string}
     */
    private function enum(BackedEnum $enum): array
    {
        return ['value' => $enum->value, 'label' => method_exists($enum, 'label') ? (string) $enum->label() : $enum->name];
    }

    private function date(CarbonInterface $date): string
    {
        return $date->format('Y-m-d');
    }
}

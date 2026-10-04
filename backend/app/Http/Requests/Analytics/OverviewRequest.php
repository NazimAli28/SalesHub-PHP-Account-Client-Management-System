<?php

namespace App\Http\Requests\Analytics;

use App\Analytics\AnalyticsScope;
use App\Analytics\DateRange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/analytics/overview?from&to[&team_id][&user_id]. Defaults to the last 30 days.
 */
class OverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->can('reports.view-all') || $user->can('reports.view-team') || $user->can('reports.view-own'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->range()->days() > DateRange::MAX_DAYS) {
                $validator->errors()->add('to', 'The date range may not be longer than '.DateRange::MAX_DAYS.' days.');
            }
        }];
    }

    public function range(): DateRange
    {
        $to = $this->filled('to') ? CarbonImmutable::parse((string) $this->input('to')) : CarbonImmutable::today();
        $from = $this->filled('from')
            ? CarbonImmutable::parse((string) $this->input('from'))
            : $to->subDays(DateRange::DEFAULT_DAYS - 1);

        return new DateRange($from->startOfDay(), $to->startOfDay());
    }

    public function scope(): AnalyticsScope
    {
        /** @var User $user */
        $user = $this->user();

        return AnalyticsScope::resolve(
            $user,
            $this->filled('team_id') ? (int) $this->input('team_id') : null,
            $this->filled('user_id') ? (int) $this->input('user_id') : null,
        );
    }
}

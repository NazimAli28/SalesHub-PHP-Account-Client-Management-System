<?php

namespace App\Http\Filters;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Filters\Filter;
use Throwable;

/**
 * One side of a date range on a date or datetime column. The value is `YYYY-MM-DD`; bounds are inclusive.
 *
 * Usage:
 *   AllowedFilter::custom('contacted_from', new DateFilter('>='), 'contacted_on'),
 *   AllowedFilter::custom('contacted_to', new DateFilter('<='), 'contacted_on'),
 *
 * @implements Filter<Model>
 */
final class DateFilter implements Filter
{
    /**
     * @param  '>='|'<='|'='  $operator
     */
    public function __construct(private readonly string $operator) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $query->whereDate($query->qualifyColumn($property), $this->operator, $this->parse($value, $property));
    }

    private function parse(mixed $value, string $property): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
                if ($date !== null && $date->format('Y-m-d') === $value) {
                    return $value;
                }
            } catch (Throwable) {
                // Reported as a validation error below.
            }
        }

        throw ValidationException::withMessages([
            'filter' => "The {$property} filter must be a date in the format YYYY-MM-DD.",
        ]);
    }
}

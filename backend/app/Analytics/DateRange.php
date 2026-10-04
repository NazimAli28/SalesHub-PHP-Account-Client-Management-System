<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;

/**
 * The reporting window (inclusive calendar days), the equally long previous window and the chart bucket size.
 */
final class DateRange
{
    public const MAX_DAYS = 366;

    public const DEFAULT_DAYS = 30;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function between(string $from, string $to): self
    {
        return new self(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->startOfDay());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function previousTo(): CarbonImmutable
    {
        return $this->from->subDay();
    }

    public function previousFrom(): CarbonImmutable
    {
        return $this->from->subDays($this->days());
    }

    /**
     * The same window shifted back by its own length.
     */
    public function previous(): self
    {
        return new self($this->previousFrom(), $this->previousTo());
    }

    /**
     * @return 'day'|'week'|'month'
     */
    public function bucket(): string
    {
        return match (true) {
            $this->days() <= 31 => 'day',
            $this->days() <= 120 => 'week',
            default => 'month',
        };
    }

    public function startsAt(): CarbonImmutable
    {
        return $this->from->startOfDay();
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->to->endOfDay();
    }

    /**
     * First day of the bucket that contains $date, never earlier than the range start.
     */
    public function bucketStart(CarbonImmutable $date): CarbonImmutable
    {
        $start = match ($this->bucket()) {
            'day' => $date,
            'week' => $date->startOfWeek(),
            default => $date->startOfMonth(),
        };

        return $start->lt($this->from) ? $this->from : $start->startOfDay();
    }

    /**
     * Every bucket start date (Y-m-d) from the range start to its end.
     *
     * @return list<string>
     */
    public function bucketKeys(): array
    {
        $keys = [];
        $cursor = $this->from;

        while ($cursor->lte($this->to)) {
            $keys[] = $this->bucketStart($cursor)->toDateString();
            $cursor = match ($this->bucket()) {
                'day' => $cursor->addDay(),
                'week' => $cursor->startOfWeek()->addWeek(),
                default => $cursor->startOfMonth()->addMonth(),
            };
        }

        return $keys;
    }
}

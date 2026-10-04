<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Money is stored as integer minor units plus an ISO 4217 currency (data-model section 5).
 */
final class Money
{
    public static function format(int $cents, string $currency = 'USD'): string
    {
        return (string) Number::currency($cents / 100, in: $currency, locale: 'en');
    }

    /**
     * The API shape of every money value.
     *
     * @return array{amount_cents: int, currency: string, formatted: string}
     */
    public static function toArray(int $cents, string $currency = 'USD'): array
    {
        return [
            'amount_cents' => $cents,
            'currency' => $currency,
            'formatted' => self::format($cents, $currency),
        ];
    }
}

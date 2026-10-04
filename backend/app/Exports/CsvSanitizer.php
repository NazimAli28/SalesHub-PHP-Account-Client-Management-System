<?php

namespace App\Exports;

/**
 * Neutralises spreadsheet formula injection: a text cell starting with = + - @ tab or CR gets a leading
 * single quote, so Excel and Sheets show it as text instead of evaluating it.
 */
final class CsvSanitizer
{
    public static function cell(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /**
     * @param  array<int|string, mixed>  $cells
     * @return list<string|int|float>
     */
    public static function row(array $cells): array
    {
        return array_values(array_map(self::cell(...), $cells));
    }

    /**
     * @param  resource  $handle
     * @param  array<int|string, mixed>  $cells
     */
    public static function write($handle, array $cells): void
    {
        fputcsv($handle, self::row($cells), ',', '"', '');
    }
}

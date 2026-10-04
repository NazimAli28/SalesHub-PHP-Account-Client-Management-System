<?php

namespace App\Imports;

use Generator;
use RuntimeException;

/**
 * Reads an uploaded CSV: UTF-8 with or without BOM, comma or semicolon delimiter, blank rows skipped.
 * Row numbers are record numbers as a spreadsheet shows them (the header is row 1).
 */
final class CsvFile
{
    private string $delimiter;

    /** @var list<string> */
    private array $headers;

    public function __construct(private readonly string $path)
    {
        $handle = $this->open();
        $first = fgets($handle);
        fclose($handle);

        $line = $this->stripBom($first === false ? '' : $first);
        $this->delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';

        $handle = $this->open();
        $record = $this->readRecord($handle);
        fclose($handle);

        $this->headers = $this->uniqueHeaders($record ?? []);
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Data rows as header => value, keyed by row number. Rows with no values at all are skipped.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(): Generator
    {
        $handle = $this->open();
        $number = 0;

        try {
            while (($record = $this->readRecord($handle)) !== null) {
                $number++;
                if ($number === 1 || $this->isBlank($record)) {
                    continue;
                }

                $row = [];
                foreach ($this->headers as $index => $header) {
                    $row[$header] = trim((string) ($record[$index] ?? ''));
                }

                yield $number => $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new RuntimeException('The import file could not be opened.');
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @return list<string|null>|null
     */
    private function readRecord($handle): ?array
    {
        $record = fgetcsv($handle, 0, $this->delimiter, '"', '');
        if ($record === false) {
            return null;
        }

        if (isset($record[0])) {
            $record[0] = $this->stripBom($record[0]);
        }

        /** @var list<string|null> $record */
        return $record;
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * @param  list<string|null>  $record
     */
    private function isBlank(array $record): bool
    {
        foreach ($record as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string|null>  $record
     * @return list<string>
     */
    private function uniqueHeaders(array $record): array
    {
        if ($this->isBlank($record)) {
            return [];
        }

        $headers = [];
        $seen = [];
        foreach ($record as $index => $cell) {
            $name = trim((string) $cell);
            $name = $name === '' ? 'Column '.($index + 1) : $name;
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $headers[] = $seen[$name] > 1 ? $name.' ('.$seen[$name].')' : $name;
        }

        return $headers;
    }
}

<?php

namespace App\Imports;

use App\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * Validates the column mapping the user chose: `CSV header => field key (or null to skip)`.
 */
final class ImportMapping
{
    /**
     * Returns the mapping with every header present; throws a 422 on `mapping` otherwise.
     *
     * @return array<string, string|null>
     */
    public static function validate(Import $import, mixed $input): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['mapping' => [$message]]);

        if (! is_array($input)) {
            $fail('The mapping must be an object of CSV column to field.');
        }

        /** @var list<string> $headers */
        $headers = $import->headers;
        $fieldKeys = ImportSchema::fieldKeys($import->type);
        $mapping = array_fill_keys($headers, null);
        $used = [];

        foreach ((array) $input as $header => $field) {
            $header = (string) $header;
            if (! array_key_exists($header, $mapping)) {
                $fail('Unknown CSV column "'.$header.'".');
            }
            if ($field === null || $field === '') {
                continue;
            }
            if (! is_string($field) || ! in_array($field, $fieldKeys, true)) {
                $fail('Unknown field for column "'.$header.'".');
            }
            if (isset($used[$field])) {
                $fail('"'.ImportSchema::label($import->type, $field).'" is mapped to more than one column.');
            }
            $used[$field] = true;
            $mapping[$header] = $field;
        }

        foreach (ImportSchema::requiredKeys($import->type) as $key) {
            if (! isset($used[$key])) {
                $fail('Map a column to "'.ImportSchema::label($import->type, $key).'".');
            }
        }

        return $mapping;
    }
}

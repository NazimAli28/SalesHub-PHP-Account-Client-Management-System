<?php

namespace App\Imports;

/**
 * One importable column of an import type.
 */
final readonly class ImportField
{
    /**
     * @param  list<string>  $aliases  Extra header names (any case/punctuation) that map to this field.
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
        public string $example = '',
        public string $hint = '',
        public array $aliases = [],
    ) {}

    /**
     * @return array{key: string, label: string, required: bool, example: string, hint: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'example' => $this->example,
            'hint' => $this->hint,
        ];
    }
}

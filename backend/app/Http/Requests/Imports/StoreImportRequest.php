<?php

namespace App\Http\Requests\Imports;

use App\Enums\ImportType;
use App\Imports\ImportFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = ImportType::tryFrom((string) $this->input('type'));

        // An unknown type is a 422 from the rules below, not a 403.
        return $type === null || (bool) $this->user()?->can($type->permission());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ImportType::class)],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:2048'],
        ];
    }

    public function importType(): ImportType
    {
        return ImportType::from($this->validated('type'));
    }

    public function maxRows(): int
    {
        return ImportFiles::MAX_ROWS;
    }
}

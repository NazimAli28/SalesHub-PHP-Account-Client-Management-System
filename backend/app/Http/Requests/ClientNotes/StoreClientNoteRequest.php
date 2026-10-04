<?php

namespace App\Http\Requests\ClientNotes;

use App\Models\ClientNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ClientNote::class, $this->route('client')]) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.ClientNote::MAX_LENGTH],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}

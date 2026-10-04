<?php

namespace App\Http\Requests\ClientNotes;

use App\Models\ClientNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `is_pinned` may be changed by anyone who can view the client; `body` only by the author.
 */
class UpdateClientNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ClientNote $note */
        $note = $this->route('note');
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->has('body') ? $user->can('update', $note) : $user->can('pin', $note);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['sometimes', 'string', 'max:'.ClientNote::MAX_LENGTH],
            'is_pinned' => ['sometimes', 'boolean'],
        ];
    }
}

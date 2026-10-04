<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The submitted identifier, trimmed and lowercased.
     */
    public function identifier(): string
    {
        return Str::lower(trim($this->string('login')->toString()));
    }

    /**
     * Column the identifier is matched against.
     */
    public function identifierField(): string
    {
        return str_contains($this->identifier(), '@') ? 'email' : 'username';
    }
}

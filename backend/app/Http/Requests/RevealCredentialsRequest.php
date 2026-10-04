<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base for the credential reveal endpoints: authorization runs before validation.
 */
abstract class RevealCredentialsRequest extends FormRequest
{
    /**
     * Secret columns that may be revealed.
     *
     * @return list<string>
     */
    abstract public function allowedFields(): array;

    /**
     * Route parameter holding the account.
     */
    abstract protected function routeParameter(): string;

    public function authorize(): bool
    {
        $account = $this->route($this->routeParameter());

        return $account !== null && (bool) $this->user()?->can('revealCredentials', $account);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', 'distinct', Rule::in($this->allowedFields())],
        ];
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        /** @var list<string> $fields */
        $fields = $this->validated('fields');

        return $fields;
    }
}

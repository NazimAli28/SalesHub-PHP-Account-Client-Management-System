<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ResolvesActor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DELETE /api/auth/two-factor: the current password plus, while two-factor sign-in is on, a code from
 * the authenticator app or one of the recovery codes (a stolen session and password alone cannot
 * turn it off). Cancelling an unfinished setup needs only the password.
 */
class DisableTwoFactorRequest extends FormRequest
{
    use ResolvesActor;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password:web'],
            'code' => [Rule::requiredIf(fn (): bool => $this->actor()->hasTwoFactorEnabled()), 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.current_password' => 'The password is incorrect.',
            'code.required' => 'Enter a code from your authenticator app or one of your recovery codes.',
        ];
    }
}

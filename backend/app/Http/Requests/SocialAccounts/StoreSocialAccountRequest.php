<?php

namespace App\Http\Requests\SocialAccounts;

use App\Http\Requests\SocialAccounts\Concerns\SocialAccountRules;
use App\Models\SocialAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Creating a social account is a direct write for support, admin and sales executives (own workstation's accounts only).
 * The password is write-only.
 */
class StoreSocialAccountRequest extends FormRequest
{
    use SocialAccountRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', SocialAccount::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $attributes = $this->socialAccountAttributeRules($user);

        return [
            'platform_account_id' => ['required', ...$attributes['platform_account_id']],
            'platform' => ['required', ...$attributes['platform']],
            'username' => ['required', ...$attributes['username']],
            'login_email' => $attributes['login_email'],
            'password' => ['required', 'string', 'min:1', 'max:255'],
            'created_on' => $attributes['created_on'],
            'is_in_use' => ['sometimes', ...$attributes['is_in_use']],
        ];
    }

    /**
     * @return list<Closure>
     */
    public function after(): array
    {
        return [$this->uniqueUsernameCheck(null)];
    }

    /**
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}

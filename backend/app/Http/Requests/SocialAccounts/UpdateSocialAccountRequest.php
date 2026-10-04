<?php

namespace App\Http\Requests\SocialAccounts;

use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Http\Requests\SocialAccounts\Concerns\SocialAccountRules;
use App\Models\SocialAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * PUT and PATCH are both partial updates, used for the direct write and for the approval payload alike.
 * The password is accepted only from users who may write directly (support/admin); a queued change never carries it.
 */
class UpdateSocialAccountRequest extends FormRequest
{
    use AuthorizesChangeOrRequest, SocialAccountRules;

    public function authorize(): bool
    {
        return $this->canChangeOrRequest('update', $this->route('socialAccount'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $account = $this->route('socialAccount');
        $direct = $account instanceof SocialAccount && $user->can('update', $account);
        $attributes = $this->socialAccountAttributeRules($user);

        return [
            'platform_account_id' => ['sometimes', ...$attributes['platform_account_id']],
            'platform' => ['sometimes', ...$attributes['platform']],
            'username' => ['sometimes', ...$attributes['username']],
            'login_email' => ['sometimes', ...$attributes['login_email']],
            'password' => $direct ? ['sometimes', 'string', 'min:1', 'max:255'] : ['prohibited'],
            'created_on' => ['sometimes', ...$attributes['created_on']],
            'is_in_use' => ['sometimes', ...$attributes['is_in_use']],
            'reason' => $attributes['reason'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['password.prohibited' => 'Credentials can only be changed by support or admin.'];
    }

    /**
     * @return list<Closure>
     */
    public function after(): array
    {
        $account = $this->route('socialAccount');

        return [$this->uniqueUsernameCheck($account instanceof SocialAccount ? $account : null)];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}

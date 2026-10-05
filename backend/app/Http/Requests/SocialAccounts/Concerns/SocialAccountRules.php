<?php

namespace App\Http\Requests\SocialAccounts\Concerns;

use App\Enums\SocialPlatform;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;
use App\Models\User;
use App\Rules\VisibleTo;
use App\Support\LocalToday;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Field rules shared by the social account Form Requests. Prefix each list with 'required' or 'sometimes'.
 */
trait SocialAccountRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function socialAccountAttributeRules(User $user): array
    {
        return [
            'platform_account_id' => ['integer', new VisibleTo(PlatformAccount::class, $user)],
            'platform' => [Rule::enum(SocialPlatform::class)],
            'username' => ['string', 'max:100'],
            'login_email' => ['nullable', 'string', 'email', 'max:255'],
            'created_on' => ['nullable', 'date_format:Y-m-d', LocalToday::notFuture()],
            'is_in_use' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * After-validation hook: (platform, username) is unique, including soft-deleted rows (database index).
     */
    protected function uniqueUsernameCheck(?SocialAccount $account): Closure
    {
        return function (Validator $validator) use ($account): void {
            if ($validator->errors()->hasAny(['platform', 'username']) || ! ($this->has('platform') || $this->has('username'))) {
                return;
            }

            $platform = $this->has('platform') ? $this->input('platform') : $account?->platform->value;
            $username = $this->has('username') ? $this->input('username') : $account?->username;

            if (! is_string($platform) || ! is_string($username)) {
                return;
            }

            $taken = SocialAccount::withTrashed()
                ->where('platform', $platform)
                ->where('username', $username)
                ->when($account, fn ($q) => $q->whereKeyNot($account?->getKey()))
                ->exists();

            if ($taken) {
                $validator->errors()->add('username', 'This username is already registered for that platform.');
            }
        };
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}

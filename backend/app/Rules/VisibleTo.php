<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `exists` plus row visibility: the ID must belong to a record the user can see through the model's
 * `visibleTo` scope, so nobody can attach another team's client, account or user. Fails with the
 * standard "The selected :attribute is invalid." message, so out-of-scope IDs look like missing ones.
 *
 * Usage: 'client_id' => ['required', 'integer', new VisibleTo(Client::class, $this->user())]
 *        new VisibleTo(User::class, $user, fn (Builder $q) => $q->where('is_active', true))
 */
final class VisibleTo implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model  A model using HasVisibilityScope.
     * @param  (Closure(Builder<Model>): mixed)|null  $constraint  Extra conditions on the visible rows.
     */
    public function __construct(
        private readonly string $model,
        private readonly User $user,
        private readonly ?Closure $constraint = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $fail('validation.exists')->translate();

            return;
        }

        /** @var Builder<Model> $query */
        $query = $this->model::query()->scopes(['visibleTo' => [$this->user]]);
        $query->whereKey((int) $value);

        if ($this->constraint !== null) {
            ($this->constraint)($query);
        }

        if (! $query->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}

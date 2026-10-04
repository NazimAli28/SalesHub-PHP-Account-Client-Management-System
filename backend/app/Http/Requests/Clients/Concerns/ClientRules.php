<?php

namespace App\Http\Requests\Clients\Concerns;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;
use App\Rules\VisibleTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by the client Form Requests. Prefix each rule list with 'required', 'sometimes' or 'nullable'.
 */
trait ClientRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function clientAttributeRules(User $user, ?Client $client = null): array
    {
        return [
            // Unique across soft-deleted rows too: the database index does not ignore them.
            'discord_username' => ['string', 'max:64', Rule::unique('clients', 'discord_username')->ignore($client?->id)],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'payment_name' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'owner_id' => ['nullable', 'integer', new VisibleTo(User::class, $user, fn (Builder $q) => $q->where('is_active', true))],
            'status' => [Rule::enum(ClientStatus::class)],
            'nurturing_rating' => ['nullable', 'integer', 'between:0,100'],
            'next_upsell_plan' => ['nullable', 'string', 'max:5000'],
            'expected_upsell_on' => ['nullable', 'date_format:Y-m-d'],
            'lost_note' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}

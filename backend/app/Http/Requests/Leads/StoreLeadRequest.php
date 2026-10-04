<?php

namespace App\Http\Requests\Leads;

use App\Http\Requests\Leads\Concerns\LeadRules;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Rules\VisibleTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Creating a lead is always a direct write (sales executives included).
 * Either link a visible client (`client_id`) or create one inline (`client.discord_username`, ...).
 */
class StoreLeadRequest extends FormRequest
{
    use LeadRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Lead::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $attributes = $this->leadAttributeRules($user);

        return [
            'client_id' => ['required_without:client', 'prohibits:client', 'integer', new VisibleTo(Client::class, $user)],
            'client' => ['required_without:client_id', 'array:discord_username,name,email'],
            'client.discord_username' => ['required_with:client', 'string', 'max:64', Rule::unique('clients', 'discord_username')],
            'client.name' => ['nullable', 'string', 'max:120'],
            'client.email' => ['nullable', 'email', 'max:255'],
            // Visible users only: a sales executive can only own their own leads, a team lead picks a teammate.
            'owner_id' => ['nullable', 'integer', new VisibleTo(User::class, $user, fn (Builder $q) => $q->where('is_active', true))],
            'closer_id' => $attributes['closer_id'],
            'platform_account_id' => $attributes['platform_account_id'],
            'stage' => ['sometimes', ...$attributes['stage']],
            'contacted_on' => ['sometimes', ...$attributes['contacted_on']],
            'estimated_value_cents' => $attributes['estimated_value_cents'],
            'currency' => ['sometimes', ...$attributes['currency']],
            'last_message' => $attributes['last_message'],
            'next_follow_up_on' => $attributes['next_follow_up_on'],
            'lost_reason' => $attributes['lost_reason'],
            'lost_note' => $attributes['lost_note'],
            'service_ids' => ['sometimes', ...$attributes['service_ids']],
            'service_ids.*' => $attributes['service_ids.*'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [$this->lostReasonCheck(null)];
    }

    /**
     * @return array<string, mixed>
     */
    public function leadAttributes(): array
    {
        return Arr::except($this->validated(), ['client', 'service_ids', 'reason']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function newClient(): ?array
    {
        /** @var array<string, mixed>|null $client */
        $client = $this->validated('client');

        return $client;
    }
}

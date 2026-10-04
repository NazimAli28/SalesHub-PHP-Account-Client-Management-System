<?php

namespace App\Http\Requests\Clients;

use App\Http\Requests\Clients\Concerns\ClientRules;
use App\Http\Requests\Concerns\ResolvesActor;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a client is always a direct write (sales executives included).
 */
class StoreClientRequest extends FormRequest
{
    use ClientRules, ResolvesActor;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Client::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->actor();
        $rules = $this->clientAttributeRules($user);

        return [
            'discord_username' => ['required', ...$rules['discord_username']],
            'name' => $rules['name'],
            'email' => $rules['email'],
            'payment_name' => $rules['payment_name'],
            'country' => $rules['country'],
            'owner_id' => $rules['owner_id'],
            'status' => ['sometimes', ...$rules['status']],
            'nurturing_rating' => $rules['nurturing_rating'],
            'next_upsell_plan' => $rules['next_upsell_plan'],
            'expected_upsell_on' => $rules['expected_upsell_on'],
            'lost_note' => $rules['lost_note'],
            'notes' => $rules['notes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clientAttributes(): array
    {
        return $this->validated();
    }
}

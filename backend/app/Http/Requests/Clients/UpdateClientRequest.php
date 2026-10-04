<?php

namespace App\Http\Requests\Clients;

use App\Http\Requests\Clients\Concerns\ClientRules;
use App\Http\Requests\Concerns\AuthorizesChangeOrRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * PUT and PATCH are both partial updates. Validates direct writes and approval payloads alike.
 */
class UpdateClientRequest extends FormRequest
{
    use AuthorizesChangeOrRequest, ClientRules;

    public function authorize(): bool
    {
        return $this->canChangeOrRequest('update', $this->route('client'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $client = $this->route('client');
        $rules = $this->clientAttributeRules($user, $client instanceof Client ? $client : null);

        return [
            'discord_username' => ['sometimes', 'required', ...$rules['discord_username']],
            'name' => ['sometimes', ...$rules['name']],
            'email' => ['sometimes', ...$rules['email']],
            'payment_name' => ['sometimes', ...$rules['payment_name']],
            'country' => ['sometimes', ...$rules['country']],
            'owner_id' => ['sometimes', 'required', ...$rules['owner_id']],
            'status' => ['sometimes', ...$rules['status']],
            'nurturing_rating' => ['sometimes', ...$rules['nurturing_rating']],
            'next_upsell_plan' => ['sometimes', ...$rules['next_upsell_plan']],
            'expected_upsell_on' => ['sometimes', ...$rules['expected_upsell_on']],
            'lost_note' => ['sometimes', ...$rules['lost_note']],
            'notes' => ['sometimes', ...$rules['notes']],
            'reason' => $rules['reason'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return Arr::except($this->validated(), ['reason']);
    }
}

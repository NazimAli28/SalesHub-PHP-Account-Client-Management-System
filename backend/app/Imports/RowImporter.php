<?php

namespace App\Imports;

use App\Actions\Clients\CreateClient;
use App\Actions\Leads\CreateLead;
use App\Enums\ImportType;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Validates and creates one imported row with the same Form Request rules and create actions as the
 * normal endpoints, so ownership, defaults and visibility behave exactly like the UI.
 *
 * Rows are `field key => trimmed string` with empty cells left out (see {@see self::values()}).
 * Errors are `field key => list<message>`; an empty array means the row is valid.
 */
final class RowImporter
{
    public function __construct(private readonly ImportType $type, private readonly User $user) {}

    /**
     * Applies the header => field mapping to a raw CSV row.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     * @return array<string, string>
     */
    public static function values(array $row, array $mapping): array
    {
        $values = [];
        foreach ($mapping as $header => $field) {
            $cell = $row[(string) $header] ?? '';
            if ($field !== null && $field !== '' && $cell !== '') {
                $values[$field] = $cell;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, list<string>>
     */
    public function check(array $values): array
    {
        return $this->prepare($values)[1];
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, list<string>>
     */
    public function create(array $values): array
    {
        [$request, $errors] = $this->prepare($values);

        if ($errors !== [] || $request === null) {
            return $errors;
        }

        if ($request instanceof StoreLeadRequest) {
            app(CreateLead::class)->handle($this->user, $request->leadAttributes(), $request->serviceIds() ?? [], $request->newClient());
        } elseif ($request instanceof StoreClientRequest) {
            app(CreateClient::class)->handle($this->user, $request->clientAttributes());
        }

        return [];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{0: FormRequest|null, 1: array<string, list<string>>}
     */
    private function prepare(array $values): array
    {
        $errors = [];

        foreach (ImportSchema::requiredKeys($this->type) as $key) {
            if (! isset($values[$key])) {
                $errors[$key][] = ImportSchema::label($this->type, $key).' is required.';
            }
        }

        $payload = $this->type === ImportType::Leads
            ? $this->leadPayload($values, $errors)
            : $this->clientPayload($values, $errors);

        $request = $this->type === ImportType::Leads
            ? StoreLeadRequest::create('/', 'POST', $payload)
            : StoreClientRequest::create('/', 'POST', $payload);
        $request->setUserResolver(fn () => $this->user);

        $validator = Validator::make($payload, $request->rules(), [], $this->attributeNames());
        foreach (method_exists($request, 'after') ? $request->after() : [] as $callback) {
            $validator->after($callback);
        }
        $request->setValidator($validator);

        foreach ($validator->errors()->messages() as $key => $messages) {
            $field = $this->fieldFor($key);
            if (isset($errors[$field])) {
                continue;
            }
            $errors[$field] = array_values(array_unique($messages));
        }

        return [$errors === [] ? $request : null, $errors];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function leadPayload(array $values, array &$errors): array
    {
        $payload = [];

        if (isset($values['client_discord_username'])) {
            $client = Client::query()->visibleTo($this->user)->where('discord_username', $values['client_discord_username'])->first();
            if ($client !== null) {
                $payload['client_id'] = $client->id;
            } else {
                $payload['client'] = array_filter([
                    'discord_username' => $values['client_discord_username'],
                    'name' => $values['client_name'] ?? null,
                    'email' => $values['client_email'] ?? null,
                ], fn ($value) => $value !== null);
            }
        }

        foreach (['contacted_on', 'next_follow_up_on', 'last_message', 'lost_note'] as $key) {
            if (isset($values[$key])) {
                $payload[$key] = $values[$key];
            }
        }
        foreach (['stage', 'lost_reason'] as $key) {
            if (isset($values[$key])) {
                $payload[$key] = $this->slug($values[$key]);
            }
        }
        if (isset($values['currency'])) {
            $payload['currency'] = strtoupper($values['currency']);
        }
        if (isset($values['estimated_value'])) {
            $cents = $this->cents($values['estimated_value']);
            if ($cents === null) {
                $errors['estimated_value'][] = 'Estimated value must be a number.';
            } else {
                $payload['estimated_value_cents'] = $cents;
            }
        }
        $this->resolveOwner($values, $payload, $errors);

        return $payload;
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function clientPayload(array $values, array &$errors): array
    {
        $payload = [];

        foreach (['discord_username', 'name', 'email', 'payment_name', 'nurturing_rating', 'next_upsell_plan', 'expected_upsell_on', 'notes'] as $key) {
            if (isset($values[$key])) {
                $payload[$key] = $values[$key];
            }
        }
        if (isset($values['country'])) {
            $payload['country'] = strtoupper($values['country']);
        }
        if (isset($values['status'])) {
            $payload['status'] = $this->slug($values['status']);
        }
        $this->resolveOwner($values, $payload, $errors);

        return $payload;
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $errors
     */
    private function resolveOwner(array $values, array &$payload, array &$errors): void
    {
        if (! isset($values['owner_username'])) {
            return;
        }

        $owner = User::query()->where('username', strtolower($values['owner_username']))->first();
        if ($owner === null) {
            $errors['owner_username'][] = 'No user has the username "'.$values['owner_username'].'".';

            return;
        }

        // Whether the importer may assign to this user is checked by the request's VisibleTo rule.
        $payload['owner_id'] = $owner->id;
    }

    private function slug(string $value): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($value)));
    }

    private function cents(string $value): ?int
    {
        $clean = str_replace([',', ' ', '$'], '', $value);

        return is_numeric($clean) ? (int) round(((float) $clean) * 100) : null;
    }

    private function fieldFor(string $key): string
    {
        return match (true) {
            $key === 'client_id', $key === 'client', $key === 'client.discord_username' => 'client_discord_username',
            $key === 'client.name' => 'client_name',
            $key === 'client.email' => 'client_email',
            $key === 'owner_id' => 'owner_username',
            $key === 'estimated_value_cents' => 'estimated_value',
            default => $key,
        };
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(): array
    {
        $names = [];
        foreach (ImportSchema::fields($this->type) as $field) {
            $names[$field->key] = strtolower($field->label);
        }

        return [
            ...$names,
            'client_id' => 'client',
            'client.discord_username' => 'client Discord username',
            'client.name' => 'client name',
            'client.email' => 'client email',
            'owner_id' => 'owner',
            'estimated_value_cents' => 'estimated value',
        ];
    }
}

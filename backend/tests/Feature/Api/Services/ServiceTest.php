<?php

use App\Enums\RoleName;
use App\Models\Service;

beforeEach(function () {
    $this->emote = Service::factory()->create(['name' => 'Twitch Emote Set', 'slug' => 'twitch-emote-set', 'category' => 'emotes', 'base_price_cents' => 12000]);
    $this->overlay = Service::factory()->create(['name' => 'Stream Overlay', 'slug' => 'stream-overlay', 'category' => 'overlays', 'base_price_cents' => 8000, 'is_active' => false]);
});

function servicePayload(array $overrides = []): array
{
    return [
        'name' => 'Channel Banner',
        'category' => 'branding',
        'base_price_cents' => 4500,
        ...$overrides,
    ];
}

it('lists the catalog for every role with formatted values', function (RoleName $role) {
    $this->actingAsRole($role);

    $response = $this->getJson('/api/services')->assertOk()->assertJsonCount(2, 'data');
    $emote = collect($response->json('data'))->firstWhere('id', $this->emote->id);

    expect($emote['category'])->toBe(['value' => 'emotes', 'label' => 'Emotes'])
        ->and($emote['base_price'])->toBe(['amount_cents' => 12000, 'currency' => 'USD', 'formatted' => '$120.00'])
        ->and($emote['is_active'])->toBeTrue();
})->with(RoleName::cases());

it('requires authentication', function () {
    $this->getJson('/api/services')->assertUnauthorized();
});

it('filters, sorts and paginates', function () {
    $this->actingAsRole(RoleName::SalesExecutive);

    expect($this->getJson('/api/services?filter[category]=emotes')->json('data.*.id'))->toBe([$this->emote->id]);
    expect($this->getJson('/api/services?filter[category]=emotes,overlays')->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/services?filter[active]=false')->json('data.*.id'))->toBe([$this->overlay->id]);
    expect($this->getJson('/api/services?filter[search]=overlay')->json('data.*.id'))->toBe([$this->overlay->id]);
    expect($this->getJson('/api/services?sort=-base_price_cents')->json('data.0.id'))->toBe($this->emote->id);
    expect($this->getJson('/api/services?sort=base_price_cents')->json('data.0.id'))->toBe($this->overlay->id);
    expect($this->getJson('/api/services')->json('data.0.id'))->toBe($this->overlay->id); // default sort: name
    $this->getJson('/api/services?page[size]=1&page[number]=2')->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
    $this->getJson('/api/services?filter[bogus]=1')->assertStatus(400);
    $this->getJson('/api/services?sort=secret')->assertStatus(400);
});

it('shows a service', function () {
    $this->actingAsRole(RoleName::SalesExecutive);

    $this->getJson('/api/services/'.$this->emote->id)->assertOk()->assertJsonPath('data.slug', 'twitch-emote-set');
    $this->getJson('/api/services/99999')->assertNotFound();
});

it('lets only support and admin write the catalog', function () {
    foreach ([RoleName::TeamLead, RoleName::SalesExecutive] as $role) {
        $this->actingAsRole($role);
        $this->postJson('/api/services', servicePayload())->assertForbidden();
        $this->patchJson('/api/services/'.$this->emote->id, ['name' => 'x'])->assertForbidden();
        $this->deleteJson('/api/services/'.$this->emote->id)->assertForbidden();
    }

    foreach ([RoleName::Support, RoleName::Admin] as $i => $role) {
        $this->actingAsRole($role);
        $this->postJson('/api/services', servicePayload(['name' => "Banner {$i}"]))->assertCreated();
    }
});

it('creates a service with a default slug, currency and active flag', function () {
    $this->actingAsRole(RoleName::Support);

    $this->postJson('/api/services', servicePayload(['description' => 'A banner.']))
        ->assertCreated()
        ->assertJsonPath('data.slug', 'channel-banner')
        ->assertJsonPath('data.base_price.formatted', '$45.00')
        ->assertJsonPath('data.base_price.currency', 'USD')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.description', 'A banner.');

    $this->postJson('/api/services', servicePayload(['name' => 'Other', 'slug' => 'custom-slug', 'currency' => 'EUR', 'is_active' => false]))
        ->assertCreated()
        ->assertJsonPath('data.slug', 'custom-slug')
        ->assertJsonPath('data.base_price.currency', 'EUR')
        ->assertJsonPath('data.is_active', false);
});

it('validates service input', function (array $payload, string $field) {
    $this->actingAsRole(RoleName::Admin);

    $this->postJson('/api/services', servicePayload($payload))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => null], 'name'],
    'bad category' => [['category' => 'plumbing'], 'category'],
    'negative price' => [['base_price_cents' => -1], 'base_price_cents'],
    'decimal price' => [['base_price_cents' => 12.5], 'base_price_cents'],
    'missing price' => [['base_price_cents' => null], 'base_price_cents'],
    'bad currency' => [['currency' => 'usd'], 'currency'],
    'bad slug' => [['slug' => 'Not A Slug'], 'slug'],
    'duplicate slug' => [['slug' => 'stream-overlay'], 'slug'],
    'bad active flag' => [['is_active' => 'perhaps'], 'is_active'],
]);

it('updates a service and toggles it inactive', function () {
    $this->actingAsRole(RoleName::Support);

    $this->patchJson('/api/services/'.$this->emote->id, ['base_price_cents' => 15000, 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.base_price.amount_cents', 15000)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.name', 'Twitch Emote Set');

    $this->putJson('/api/services/'.$this->emote->id, ['slug' => 'stream-overlay'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->putJson('/api/services/'.$this->emote->id, ['slug' => 'twitch-emote-set', 'description' => null])->assertOk();
});

it('soft deletes a service', function () {
    $this->actingAsRole(RoleName::Admin);

    $this->deleteJson('/api/services/'.$this->emote->id)->assertNoContent();

    expect(Service::query()->find($this->emote->id))->toBeNull()
        ->and(Service::withTrashed()->find($this->emote->id))->not->toBeNull();
    $this->getJson('/api/services/'.$this->emote->id)->assertNotFound();
    $this->getJson('/api/services')->assertJsonCount(1, 'data');
});

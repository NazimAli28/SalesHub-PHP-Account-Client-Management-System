<?php

use App\Enums\ImportType;
use App\Enums\RoleName;
use App\Models\Import;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('local');
});

if (! function_exists('importRequest')) {
    function importRequest(mixed $test, string $verb, Import $import, string $suffix = ''): TestResponse
    {
        return $test->{$verb.'Json'}("/api/imports/{$import->id}{$suffix}", ['mapping' => []]);
    }
}

it('lets only the uploader open, preview, start or download errors of an import', function () {
    $owner = $this->makeUser(RoleName::Support);
    $import = Import::factory()->create(['user_id' => $owner->id]);

    $this->actingAsRole(RoleName::Support);

    importRequest($this, 'get', $import)->assertForbidden();
    importRequest($this, 'post', $import, '/preview')->assertForbidden();
    importRequest($this, 'post', $import, '/start')->assertForbidden();
    importRequest($this, 'get', $import, '/errors')->assertForbidden();
    expect($import->refresh()->status->value)->toBe('uploaded');

    $this->actingAsUser($owner);
    importRequest($this, 'get', $import)->assertOk();
});

it('lets an admin open anyone\'s import', function () {
    $import = Import::factory()->create();

    $this->actingAsRole(RoleName::Admin);

    importRequest($this, 'get', $import)->assertOk();
});

it('denies users without the import permission', function (RoleName $role) {
    $user = $this->makeUser($role);
    $import = Import::factory()->create(['user_id' => $user->id, 'type' => ImportType::Leads]);

    $this->actingAsUser($user);

    importRequest($this, 'get', $import)->assertForbidden();
    importRequest($this, 'post', $import, '/start')->assertForbidden();
})->with([RoleName::SalesExecutive, RoleName::TeamLead]);

it('requires authentication', function () {
    $this->getJson('/api/imports')->assertUnauthorized();
    $this->postJson('/api/imports')->assertUnauthorized();
});

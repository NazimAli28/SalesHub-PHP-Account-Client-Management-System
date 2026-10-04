<?php

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\RoleName;
use App\Jobs\ProcessImport;
use App\Models\Client;
use App\Models\Import;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->support = $this->actingAsRole(RoleName::Support);
    $this->day = now()->subDays(3)->toDateString();
});

if (! function_exists('importUploadCsv')) {
    /**
     * @return array{0: int, 1: array<string, string|null>} The import id and its suggested mapping.
     */
    function importUploadCsv(mixed $test, string $type, string $content): array
    {
        $response = $test->postJson('/api/imports', [
            'type' => $type,
            'file' => UploadedFile::fake()->createWithContent($type.'.csv', $content),
        ])->assertCreated();

        return [$response->json('data.id'), $response->json('data.suggested_mapping')];
    }
}

it('previews rows with the real create rules and reports row errors per field', function () {
    $owner = User::factory()->create(['username' => 'agent-one', 'is_active' => true]);
    [$id, $mapping] = importUploadCsv($this, 'leads', implode("\n", [
        'discord,name,stage,contacted_on,value,owner_username',
        "good_one,Jordan Rivera,quoted,{$this->day},150.00,agent-one",
        ",No Discord,new,{$this->day},,",
        "bad_stage,X,flying,{$this->day},abc,nobody",
        'bad_date,X,new,2999-01-01,,',
        "lost_one,X,lost,{$this->day},,",
    ])."\n");
    $mapping['owner_username'] = 'owner_username';

    $response = $this->postJson("/api/imports/{$id}/preview", ['mapping' => $mapping])
        ->assertOk()
        ->assertJsonPath('data.summary', ['total_rows' => 5, 'checked' => 5, 'valid' => 1, 'invalid' => 4])
        ->assertJsonPath('data.rows.0.valid', true)
        ->assertJsonPath('data.rows.0.row', 2)
        ->assertJsonPath('data.rows.1.errors.client_discord_username.0', 'Client Discord username is required.')
        ->assertJsonPath('data.rows.4.errors.lost_reason.0', 'A lost reason is required when the lead is lost.');

    $errors = $response->json('data.rows.2.errors');
    expect($errors)->toHaveKeys(['stage', 'estimated_value', 'owner_username'])
        ->and($response->json('data.rows.3.errors'))->toHaveKey('contacted_on');

    // Preview writes nothing.
    expect(Lead::query()->count())->toBe(0)->and(Client::query()->count())->toBe(0);
});

it('previews at most 50 rows and flags duplicate client usernames inside the file', function () {
    $lines = collect(range(1, 60))->map(fn ($i) => 'fox'.($i === 3 ? 1 : $i))->implode("\n");
    [$id, $mapping] = importUploadCsv($this, 'clients', "discord_username\n{$lines}\n");

    $response = $this->postJson("/api/imports/{$id}/preview", ['mapping' => $mapping])
        ->assertOk()
        ->assertJsonPath('data.summary.total_rows', 60)
        ->assertJsonPath('data.summary.checked', 50)
        ->assertJsonPath('data.summary.invalid', 1);

    expect($response->json('data.rows.2.errors.discord_username.0'))->toContain('Duplicate of row 2');
});

it('validates the mapping', function (mixed $mapping) {
    [$id] = importUploadCsv($this, 'leads', "discord,name\nfox,Jordan\n");

    $this->postJson("/api/imports/{$id}/preview", ['mapping' => $mapping])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mapping');
    $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])->assertUnprocessable();
    expect(Import::query()->find($id)->status)->toBe(ImportStatus::Uploaded);
})->with([
    'required field not mapped' => [['discord' => null, 'name' => 'client_name']],
    'duplicate target' => [['discord' => 'client_discord_username', 'name' => 'client_discord_username']],
    'unknown field' => [['discord' => 'client_discord_username', 'name' => 'password']],
    'unknown column' => [['discord' => 'client_discord_username', 'ghost' => 'client_name']],
    'not an object' => ['nope'],
]);

it('imports leads end to end: creates clients and leads, reuses clients, records row errors', function () {
    $existing = Client::factory()->create(['discord_username' => 'old_friend', 'owner_id' => $this->support->id]);
    $owner = User::factory()->create(['username' => 'agent-one', 'is_active' => true]);

    [$id, $mapping] = importUploadCsv($this, 'leads', implode("\n", [
        'Discord;Name;Email;Stage;Contacted On;Value;Owner',
        "new_streamer;Jordan Rivera;jordan@example.com;Engaged;{$this->day};1,250.50;",
        "old_friend;Ignored Name;;quoted;{$this->day};;agent-one",
        "broken;;;flying;{$this->day};;",
        ';;;new;;;',
        "second_lead;;;new;{$this->day};;",
    ])."\n");
    $mapping['Owner'] = 'owner_username';
    $mapping['Email'] = 'client_email';

    $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])
        ->assertStatus(202)
        ->assertJsonPath('data.id', $id);

    $import = $this->getJson("/api/imports/{$id}")->assertOk()
        ->assertJsonPath('data.status.value', 'completed')
        ->assertJsonPath('data.total_rows', 5)
        ->assertJsonPath('data.processed_rows', 5)
        ->assertJsonPath('data.created_rows', 3)
        ->assertJsonPath('data.failed_rows', 2)
        ->assertJsonPath('data.errors.0.row', 4)
        ->assertJsonPath('data.errors.0.column', 'Stage')
        ->json('data');
    expect($import)->not->toHaveKey('path');

    $new = Client::query()->where('discord_username', 'new_streamer')->firstOrFail();
    $lead = Lead::query()->where('client_id', $new->id)->firstOrFail();
    expect($new->email)->toBe('jordan@example.com')
        ->and($new->owner_id)->toBe($this->support->id)
        ->and($lead->owner_id)->toBe($this->support->id)
        ->and($lead->stage->value)->toBe('engaged')
        ->and($lead->estimated_value_cents)->toBe(125050)
        ->and(Client::query()->where('discord_username', 'old_friend')->count())->toBe(1)
        ->and(Lead::query()->where('client_id', $existing->id)->firstOrFail()->owner_id)->toBe($owner->id)
        ->and(Lead::query()->count())->toBe(3);

    expect(Activity::query()->where('log_name', 'import')->pluck('event')->all())->toBe(['started', 'completed']);
});

it('imports clients and downloads the failed rows as a CSV', function () {
    Client::factory()->create(['discord_username' => 'taken_name']);
    [$id, $mapping] = importUploadCsv($this, 'clients', implode("\n", [
        'discord_username,name,country,status,nurturing_rating',
        'fresh_one,Jordan Rivera,us,Nurturing,70',
        'taken_name,Someone,US,active,10',
        'fresh_two,Bad Country,USA,active,500',
    ])."\n");

    $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])->assertStatus(202);

    $this->getJson("/api/imports/{$id}")
        ->assertJsonPath('data.created_rows', 1)
        ->assertJsonPath('data.failed_rows', 2);

    $fresh = Client::query()->where('discord_username', 'fresh_one')->firstOrFail();
    expect($fresh->country)->toBe('US')->and($fresh->status->value)->toBe('nurturing')->and($fresh->owner_id)->toBe($this->support->id);

    $csv = $this->get("/api/imports/{$id}/errors")->assertOk();
    $content = $csv->streamedContent();
    expect($content)->toContain('discord_username,name,country,status,nurturing_rating,Error')
        ->and($content)->toContain('taken_name,Someone,US,active,10')
        ->and($content)->toContain('fresh_two,"Bad Country"')
        ->and($content)->not->toContain('fresh_one')
        ->and($csv->headers->get('Content-Disposition'))->toContain("import-{$id}-errors.csv");
});

it('stops one import from running twice', function () {
    [$id, $mapping] = importUploadCsv($this, 'clients', "discord_username\nonly_once\n");

    $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])->assertStatus(202);
    $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])->assertStatus(409);
    $this->postJson("/api/imports/{$id}/preview", ['mapping' => $mapping])->assertStatus(409);

    expect(Client::query()->where('discord_username', 'only_once')->count())->toBe(1);
});

it('marks a crashed import as failed', function () {
    [$id, $mapping] = importUploadCsv($this, 'clients', "discord_username\nfine_one\n");
    Import::query()->whereKey($id)->update(['status' => ImportStatus::Queued->value, 'mapping' => json_encode($mapping)]);

    (new ProcessImport($id))->failed(new RuntimeException('boom'));

    expect(Import::query()->find($id)->status)->toBe(ImportStatus::Failed);
});

it('neutralises formulas in the error CSV', function () {
    [$id, $mapping] = importUploadCsv($this, 'clients', "discord_username,name,country\n=cmd,x,USA\n");

    $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])->assertStatus(202);

    expect($this->get("/api/imports/{$id}/errors")->streamedContent())->toContain("'=cmd");
});

it('lists own imports only, admins see everyone', function () {
    $mine = Import::factory()->create(['user_id' => $this->support->id]);
    $theirs = Import::factory()->create(['type' => ImportType::Clients]);

    $ids = fn () => collect($this->getJson('/api/imports')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids())->toBe([$mine->id]);

    $this->actingAsRole(RoleName::Admin);
    expect($ids())->toBe([$theirs->id, $mine->id]);
});

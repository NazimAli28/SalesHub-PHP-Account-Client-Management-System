<?php

use App\Enums\ImportStatus;
use App\Enums\RoleName;
use App\Http\Middleware\SecurityHeaders;
use App\Jobs\ProcessImport;
use App\Models\Client;
use App\Models\Import;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

const SECURITY_NEW_PASSWORD = 'N3w-Passw0rd!x';

/**
 * A user with one of the seeded demo usernames (config saleshub.demo_usernames).
 */
function securityDemoUser(string $username, RoleName $role = RoleName::SalesExecutive): User
{
    $factory = match ($role) {
        RoleName::Admin => User::factory()->admin(),
        RoleName::Support => User::factory()->support(),
        RoleName::TeamLead => User::factory()->teamLead(),
        RoleName::SalesExecutive => User::factory()->salesExecutive(),
    };

    return $factory->create(['username' => $username, 'email' => $username.'@example.com']);
}

/**
 * @return array{0: int, 1: array<string, string|null>} The import id and its suggested mapping.
 */
function securityUploadCsv(mixed $test, string $content): array
{
    $response = $test->postJson('/api/imports', [
        'type' => 'clients',
        'file' => UploadedFile::fake()->createWithContent('clients.csv', $content),
    ])->assertCreated();

    return [(int) $response->json('data.id'), $response->json('data.suggested_mapping')];
}

describe('user administration (S1)', function () {
    it('does not let users set their own password through the user endpoint', function () {
        $admin = $this->actingAsRole(RoleName::Admin);
        $hash = $admin->password;

        $this->patchJson("/api/users/{$admin->id}", ['password' => SECURITY_NEW_PASSWORD])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        expect($admin->refresh()->password)->toBe($hash);

        // Other fields of your own account are still editable.
        $this->patchJson("/api/users/{$admin->id}", ['name' => 'Riley Brooks'])->assertOk();
    });

    describe('in demo mode', function () {
        beforeEach(fn () => config(['saleshub.demo_mode' => true]));

        it('refuses to change what is needed to sign in to a seeded demo account', function (array $payload) {
            $this->actingAsRole(RoleName::Support);
            $agent = securityDemoUser('agent1');
            $hash = $agent->password;

            $this->patchJson("/api/users/{$agent->id}", $payload)
                ->assertForbidden()
                ->assertJsonPath('code', 'demo_mode');

            $fresh = $agent->fresh();
            expect($fresh->password)->toBe($hash)
                ->and($fresh->username)->toBe('agent1')
                ->and($fresh->email)->toBe('agent1@example.com')
                ->and($fresh->getRoleNames()->all())->toBe(['sales_executive']);
        })->with([
            'password' => [['password' => SECURITY_NEW_PASSWORD]],
            'username' => [['username' => 'someone-else']],
            'email' => [['email' => 'taken-over@example.com']],
            'role' => [['role' => 'team_lead']],
        ]);

        it('still allows other edits and unchanged values on a demo account', function () {
            $this->actingAsRole(RoleName::Support);
            $agent = securityDemoUser('agent1');

            $this->patchJson("/api/users/{$agent->id}", [
                'name' => 'Jordan Rivera',
                'username' => 'agent1',
                'email' => 'Agent1@Example.com',
                'role' => 'sales_executive',
            ])->assertOk()->assertJsonPath('data.name', 'Jordan Rivera');
        });

        it('refuses to deactivate, activate or delete a demo account', function () {
            $this->actingAsRole(RoleName::Admin);
            $agent = securityDemoUser('agent2');
            $inactive = securityDemoUser('agent7');
            $inactive->update(['is_active' => false]);

            $this->patchJson("/api/users/{$agent->id}/deactivate")->assertForbidden()->assertJsonPath('code', 'demo_mode');
            $this->patchJson("/api/users/{$inactive->id}/activate")->assertForbidden()->assertJsonPath('code', 'demo_mode');
            $this->deleteJson("/api/users/{$agent->id}")->assertForbidden()->assertJsonPath('code', 'demo_mode');

            expect($agent->fresh()->is_active)->toBeTrue()
                ->and($inactive->fresh()->is_active)->toBeFalse()
                ->and(User::query()->whereKey($agent->id)->exists())->toBeTrue();
        });

        it('keeps users created during the demo fully editable', function () {
            $this->actingAsRole(RoleName::Admin);
            $visitorMade = User::factory()->salesExecutive()->create(['username' => 'new-hire']);

            $this->patchJson("/api/users/{$visitorMade->id}", ['password' => SECURITY_NEW_PASSWORD, 'username' => 'new-hire-2'])->assertOk();
            expect(Hash::check(SECURITY_NEW_PASSWORD, $visitorMade->fresh()->password))->toBeTrue();

            $this->patchJson("/api/users/{$visitorMade->id}/deactivate")->assertOk();
            $this->deleteJson("/api/users/{$visitorMade->id}")->assertNoContent();
        });
    });

    it('lets admins manage the seeded usernames when demo mode is off', function () {
        $this->actingAsRole(RoleName::Support);
        $agent = securityDemoUser('agent1');

        $this->patchJson("/api/users/{$agent->id}", ['password' => SECURITY_NEW_PASSWORD])->assertOk();
        $this->patchJson("/api/users/{$agent->id}/deactivate")->assertOk();
    });
});

describe('API security headers (S3)', function () {
    it('sends a locked-down policy with API responses', function () {
        $this->actingAsRole(RoleName::Support);

        $response = $this->getJson('/api/leads')->assertOk();

        expect($response->headers->get('Content-Security-Policy'))->toBe(SecurityHeaders::API_CSP)
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
    });

    it('keeps the download headers of CSV exports and marks them no-store', function () {
        $this->actingAsRole(RoleName::Admin);

        $response = $this->get('/api/exports/leads')->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain('attachment')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('adds HSTS to HTTPS requests', function () {
        $this->actingAsRole(RoleName::Support);

        $this->getJson('https://localhost/api/leads')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);
    });

    it('also secures error responses', function () {
        $this->getJson('/api/leads')
            ->assertUnauthorized()
            ->assertHeader('Content-Security-Policy', SecurityHeaders::API_CSP);
    });
});

describe('audit log in demo mode (S4)', function () {
    it('coarsens IP addresses for everyone in demo mode', function () {
        $this->actingAsRole(RoleName::Admin);
        activity('auth')->event('security-test')->withProperties(['ip' => '203.0.113.77', 'nested' => ['ip' => '2001:db8:abcd:12::1']])->log('login');

        expect($this->getJson('/api/audit-log?filter[event]=security-test')->assertOk()->json('data.0.properties.ip'))->toBe('203.0.113.77');

        config(['saleshub.demo_mode' => true]);

        $properties = $this->getJson('/api/audit-log?filter[event]=security-test')->assertOk()->json('data.0.properties');
        expect($properties['ip'])->toBe('203.0.113.x')
            ->and($properties['nested']['ip'])->toBe('2001:db8:abcd::/48');
    });
});

describe('imports (S5, S11)', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->support = $this->actingAsRole(RoleName::Support);
    });

    it('deletes the uploaded file when the import completes and still serves the error CSV', function () {
        Client::factory()->create(['discord_username' => 'taken_name']);
        [$id, $mapping] = securityUploadCsv($this, "discord_username,name\nfresh_one,Jordan Rivera\ntaken_name,Someone\n");
        $path = Import::query()->findOrFail($id)->path;
        Storage::disk('local')->assertExists($path);

        $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])->assertStatus(202);

        Storage::disk('local')->assertMissing($path);
        $import = $this->getJson("/api/imports/{$id}")->assertOk()
            ->assertJsonPath('data.status.value', 'completed')
            ->json('data');
        expect($import['errors'][0])->not->toHaveKey('values');

        $csv = $this->get("/api/imports/{$id}/errors")->assertOk()->streamedContent();
        expect($csv)->toContain('discord_username,name,Error')
            ->and($csv)->toContain('taken_name,Someone,')
            ->and($csv)->not->toContain('fresh_one');
    });

    it('deletes the uploaded file when the import fails', function () {
        [$id, $mapping] = securityUploadCsv($this, "discord_username\nfine_one\n");
        $import = Import::query()->findOrFail($id);
        $import->forceFill(['status' => ImportStatus::Queued, 'mapping' => $mapping])->save();

        (new ProcessImport($id))->failed(new RuntimeException('Worker stopped'));

        expect($import->fresh()->status)->toBe(ImportStatus::Failed);
        Storage::disk('local')->assertMissing($import->path);
    });

    it('prunes never-started uploads after a day, with their files', function () {
        [$oldId] = securityUploadCsv($this, "discord_username\nold_one\n");
        [$freshId] = securityUploadCsv($this, "discord_username\nnew_one\n");
        $old = Import::query()->findOrFail($oldId);
        $old->forceFill(['created_at' => now()->subHours(25)])->save();
        $finished = Import::factory()->create(['status' => ImportStatus::Completed, 'created_at' => now()->subDays(3)]);

        Artisan::call('model:prune', ['--model' => [Import::class]]);

        expect(Import::query()->pluck('id')->sort()->values()->all())->toBe(collect([$freshId, $finished->id])->sort()->values()->all());
        Storage::disk('local')->assertMissing($old->path);
    });

    it('does not process an import that is no longer queued', function () {
        [$id, $mapping] = securityUploadCsv($this, "discord_username\nonly_once\n");
        Import::query()->whereKey($id)->update(['status' => ImportStatus::Processing->value, 'mapping' => json_encode($mapping)]);

        (new ProcessImport($id))->handle();

        expect(Client::query()->where('discord_username', 'only_once')->exists())->toBeFalse()
            ->and(Import::query()->findOrFail($id)->status)->toBe(ImportStatus::Processing);
    });

    it('answers 409 when the import was claimed in between', function () {
        [$id, $mapping] = securityUploadCsv($this, "discord_username\nraced\n");
        $import = Import::query()->findOrFail($id);

        // Another request queued it after this one loaded the row: only the guarded update can tell.
        Import::query()->whereKey($id)->update(['status' => ImportStatus::Queued->value]);
        $this->app['router']->bind('import', fn () => $import);

        $this->postJson("/api/imports/{$id}/start", ['mapping' => $mapping])->assertStatus(409);

        expect(Activity::query()->where('log_name', 'import')->where('event', 'started')->count())->toBe(0);
    });
});

describe('rate limits (S8)', function () {
    it('allows five CSV exports per minute', function () {
        $this->actingAsRole(RoleName::Admin);

        for ($i = 0; $i < 5; $i++) {
            $this->get('/api/exports/clients')->assertOk();
        }

        $this->getJson('/api/exports/clients')->assertStatus(429)->assertHeader('Retry-After');
    });

    it('limits analytics and imports per user', function () {
        $user = $this->actingAsRole(RoleName::Admin);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/analytics/overview')->assertOk();
        }
        $this->getJson('/api/analytics/overview')->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/imports', ['type' => 'clients'])->assertUnprocessable();
        }
        $this->postJson('/api/imports', ['type' => 'clients'])->assertStatus(429);

        // Another user has their own budget.
        $this->actingAsRole(RoleName::Admin);
        $this->getJson('/api/analytics/overview')->assertOk();
        expect($user->id)->toBeInt();
    });
});

describe('search filter (S10)', function () {
    beforeEach(function () {
        $this->actingAsRole(RoleName::Admin);
    });

    it('matches % and _ literally', function () {
        $percent = Lead::factory()->create(['last_message' => 'Offered 50% off the overlay']);
        $plain = Lead::factory()->create(['last_message' => 'Offered 500 credits']);
        $underscore = Lead::factory()->create(['last_message' => 'Sent file_v2']);
        Lead::factory()->create(['last_message' => 'Sent fileXv2']);

        $ids = fn (string $term) => collect($this->getJson('/api/leads?filter[search]='.rawurlencode($term))->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('50%'))->toBe([$percent->id])
            ->and($ids('file_v2'))->toBe([$underscore->id])
            ->and($ids('500'))->toBe([$plain->id]);
    });

    it('rejects search terms longer than 100 characters', function () {
        $this->getJson('/api/leads?filter[search]='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filter.search');

        $this->getJson('/api/leads?filter[search]='.str_repeat('a', 100))->assertOk();
    });
});

describe('lead closer (S12)', function () {
    it('only accepts a closer the actor can see', function () {
        $alpha = $this->makeTeamWithMembers(2);
        $bravo = $this->makeTeamWithMembers(1);
        $this->actingAsUser($alpha->teamLead);
        $client = Client::factory()->create(['owner_id' => $alpha->agent(0)->id]);

        $this->postJson('/api/leads', ['client_id' => $client->id, 'closer_id' => $bravo->agent(0)->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('closer_id');

        $this->postJson('/api/leads', ['client_id' => $client->id, 'closer_id' => $alpha->agent(1)->id])
            ->assertCreated()
            ->assertJsonPath('data.closer_id', $alpha->agent(1)->id);
    });
});

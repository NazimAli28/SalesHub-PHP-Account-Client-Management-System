<?php

use App\Enums\LeadStage;
use App\Enums\RoleName;
use App\Http\Controllers\Exports\ExportController;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);

    $this->mine = Lead::factory()->create(['owner_id' => $this->alpha->agent(0)->id]);
    $this->teammate = Lead::factory()->create(['owner_id' => $this->alpha->agent(1)->id]);
    $this->other = Lead::factory()->create(['owner_id' => $this->bravo->agent(0)->id]);
});

if (! function_exists('exportRows')) {
    /**
     * Parsed CSV rows (header row included) of a streamed response.
     *
     * @return list<list<string|null>>
     */
    function exportRows(TestResponse $response): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());

        return array_map(fn ($line) => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", (string) $content)));
    }
}

it('exports the leads each role may see', function (RoleName $role, array $visible) {
    $role === RoleName::TeamLead ? $this->actingAsUser($this->alpha->teamLead) : $this->actingAsRole($role);

    $response = $this->get('/api/exports/leads')->assertOk();
    $rows = exportRows($response);

    expect($rows[0])->toContain('Client Discord username', 'Stage')
        ->and(collect(array_slice($rows, 1))->pluck(0)->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect($visible)->map(fn ($name) => $this->{$name}->id)->sort()->values()->all())
        ->and($response->headers->get('Content-Disposition'))->toContain('leads-')
        ->and($response->headers->get('Content-Type'))->toContain('text/csv');
})->with([
    'support sees all' => [RoleName::Support, ['mine', 'teammate', 'other']],
    'admin sees all' => [RoleName::Admin, ['mine', 'teammate', 'other']],
    'team lead sees the team' => [RoleName::TeamLead, ['mine', 'teammate']],
]);

it('denies users without reports.export', function () {
    $this->actingAsUser($this->alpha->agent(0));

    $this->getJson('/api/exports/leads')->assertForbidden();
    $this->getJson('/api/exports/clients')->assertForbidden();
});

it('requires authentication and a known type', function () {
    $this->getJson('/api/exports/leads')->assertUnauthorized();

    $this->actingAsRole(RoleName::Support);
    $this->getJson('/api/exports/orders')->assertNotFound();
});

it('applies the same filters and sort as the index endpoint', function () {
    $this->mine->update(['stage' => LeadStage::Quoted]);
    $this->teammate->update(['stage' => LeadStage::Engaged]);
    $this->other->update(['stage' => LeadStage::Quoted]);
    $this->actingAsRole(RoleName::Support);

    $rows = exportRows($this->get('/api/exports/leads?filter[stage]=quoted&filter[owner]='.$this->alpha->agent(0)->id.'&sort=id'));

    expect($rows)->toHaveCount(2)->and((int) $rows[1][0])->toBe($this->mine->id);

    $sorted = exportRows($this->get('/api/exports/leads?sort=-id'));
    expect(array_map(fn ($row) => (int) $row[0], array_slice($sorted, 1)))->toBe([$this->other->id, $this->teammate->id, $this->mine->id]);
});

it('exports clients with lifetime value and no hidden fields', function () {
    $client = Client::factory()->create(['owner_id' => $this->alpha->agent(0)->id, 'discord_username' => 'neon_fox']);
    $this->actingAsRole(RoleName::Support);

    $rows = exportRows($this->get('/api/exports/clients?filter[search]=neon_fox'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toContain('Discord username', 'Lifetime value (USD)')
        ->and($rows[1][1])->toBe('neon_fox')
        ->and($rows[1][6])->toBe($this->alpha->agent(0)->username);
});

it('scopes client exports for a team lead', function () {
    Client::factory()->create(['owner_id' => $this->alpha->agent(0)->id, 'discord_username' => 'in_team']);
    Client::factory()->create(['owner_id' => $this->bravo->agent(0)->id, 'discord_username' => 'out_of_team']);
    $this->actingAsUser($this->alpha->teamLead);

    $names = collect(array_slice(exportRows($this->get('/api/exports/clients')), 1))->pluck(1)->all();

    expect($names)->toContain('in_team')->not->toContain('out_of_team');
});

it('neutralises spreadsheet formulas in exported cells', function () {
    $client = Client::factory()->create(['discord_username' => 'sneaky', 'name' => '=HYPERLINK("http://evil.example.com")', 'email' => '+cmd@example.com', 'notes' => '@SUM(1+1)']);
    $client->forceFill(['payment_name' => "\tTabbed", 'next_upsell_plan' => '-2+3'])->save();
    $this->actingAsRole(RoleName::Support);

    $row = exportRows($this->get('/api/exports/clients?filter[search]=sneaky'))[1];

    expect($row[2])->toBe("'=HYPERLINK(\"http://evil.example.com\")")
        ->and($row[3])->toBe("'+cmd@example.com")
        ->and($row[4])->toBe("'\tTabbed")
        ->and($row[9])->toBe("'-2+3")
        ->and($row[11])->toBe("'@SUM(1+1)");
});

it('neutralises formulas in lead cells', function () {
    $this->mine->forceFill(['last_message' => '=1+1'])->save();
    $this->actingAsRole(RoleName::Support);

    $rows = exportRows($this->get('/api/exports/leads?sort=id'));

    expect($rows[1][9])->toBe("'=1+1");
});

it('writes an audit entry with the row count and filters but no data', function () {
    $this->actingAsRole(RoleName::Support);

    $this->get('/api/exports/leads?filter[stage]=new')->assertOk()->streamedContent();

    $entry = Activity::query()->where('log_name', 'export')->latest('id')->firstOrFail();
    expect($entry->event)->toBe('exported')
        ->and($entry->properties['type'])->toBe('leads')
        ->and($entry->properties['rows'])->toBeInt()
        ->and($entry->properties['filters'])->toBe(['stage' => 'new'])
        ->and(json_encode($entry->properties))->not->toContain('@example.com');
});

it('caps an export at 10,000 rows', function () {
    expect(ExportController::MAX_ROWS)->toBe(10000);
});

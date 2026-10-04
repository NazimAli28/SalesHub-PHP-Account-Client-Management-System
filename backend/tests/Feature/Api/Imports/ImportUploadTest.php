<?php

use App\Enums\RoleName;
use App\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

if (! function_exists('importFile')) {
    function importFile(string $content, string $name = 'leads.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }
}

it('stores an upload and returns headers, sample rows, a suggested mapping and the target fields', function () {
    $this->actingAsRole(RoleName::Support);

    $rows = collect(range(1, 7))->map(fn ($i) => "streamer{$i},Streamer {$i},new,120.50")->implode("\n");
    $response = $this->postJson('/api/imports', [
        'type' => 'leads',
        'file' => importFile("Discord,Name,Stage,Value\n{$rows}\n"),
    ])->assertCreated()
        ->assertJsonPath('data.status.value', 'uploaded')
        ->assertJsonPath('data.type.value', 'leads')
        ->assertJsonPath('data.total_rows', 7)
        ->assertJsonPath('data.headers', ['Discord', 'Name', 'Stage', 'Value'])
        ->assertJsonPath('data.suggested_mapping', [
            'Discord' => 'client_discord_username',
            'Name' => 'client_name',
            'Stage' => 'stage',
            'Value' => 'estimated_value',
        ])
        ->assertJsonCount(5, 'data.sample_rows')
        ->assertJsonPath('data.sample_rows.0.Discord', 'streamer1');

    expect(collect($response->json('data.fields'))->firstWhere('key', 'client_discord_username')['required'])->toBeTrue()
        ->and($response->json('data'))->not->toHaveKey('path');

    $import = Import::query()->firstOrFail();
    Storage::disk('local')->assertExists($import->path);
    expect($import->path)->toStartWith('imports/');
});

it('accepts a UTF-8 BOM and semicolon delimiters', function () {
    $this->actingAsRole(RoleName::Support);

    $this->postJson('/api/imports', [
        'type' => 'clients',
        'file' => importFile("\xEF\xBB\xBFdiscord_username;name;email\nneonfox;Jordan Rivera;jordan@example.com\n", 'clients.csv'),
    ])->assertCreated()
        ->assertJsonPath('data.headers', ['discord_username', 'name', 'email'])
        ->assertJsonPath('data.sample_rows.0.name', 'Jordan Rivera')
        ->assertJsonPath('data.suggested_mapping.discord_username', 'discord_username');
});

it('skips blank rows and renames blank and duplicate headers', function () {
    $this->actingAsRole(RoleName::Support);

    $this->postJson('/api/imports', [
        'type' => 'clients',
        'file' => importFile("discord_username,,discord_username\nneonfox,a,b\n,,\n\nmossy,c,d\n"),
    ])->assertCreated()
        ->assertJsonPath('data.total_rows', 2)
        ->assertJsonPath('data.headers', ['discord_username', 'Column 2', 'discord_username (2)']);
});

it('rejects invalid uploads', function (array $payload, string $field) {
    $this->actingAsRole(RoleName::Support);

    $this->postJson('/api/imports', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Import::query()->count())->toBe(0);
})->with([
    'unknown type' => [fn () => ['type' => 'orders', 'file' => importFile("a\n1\n")], 'type'],
    'missing type' => [fn () => ['file' => importFile("a\n1\n")], 'type'],
    'missing file' => [fn () => ['type' => 'leads'], 'file'],
    'wrong extension' => [fn () => ['type' => 'leads', 'file' => importFile("a\n1\n", 'leads.pdf')], 'file'],
    'over 2 MB' => [fn () => ['type' => 'leads', 'file' => UploadedFile::fake()->create('big.csv', 3000)], 'file'],
    'empty file' => [fn () => ['type' => 'leads', 'file' => importFile('')], 'file'],
    'header only' => [fn () => ['type' => 'leads', 'file' => importFile("discord\n")], 'file'],
    'not UTF-8' => [fn () => ['type' => 'leads', 'file' => importFile("discord\n\xE9\xE8\n")], 'file'],
    'too many rows' => [fn () => ['type' => 'leads', 'file' => importFile("discord\n".str_repeat("x\n", 2001))], 'file'],
]);

it('accepts exactly 2,000 rows', function () {
    $this->actingAsRole(RoleName::Support);

    $this->postJson('/api/imports', [
        'type' => 'leads',
        'file' => importFile("discord\n".str_repeat("x\n", 2000)),
    ])->assertCreated()->assertJsonPath('data.total_rows', 2000);
});

it('requires the import permission for the type', function () {
    $this->actingAsRole(RoleName::SalesExecutive);

    $this->postJson('/api/imports', ['type' => 'leads', 'file' => importFile("discord\nx\n")])->assertForbidden();
    $this->getJson('/api/imports')->assertForbidden();
    $this->getJson('/api/imports/templates/leads')->assertForbidden();

    $this->actingAsRole(RoleName::TeamLead);
    $this->postJson('/api/imports', ['type' => 'clients', 'file' => importFile("discord\nx\n")])->assertForbidden();
});

it('downloads a CSV template with headers and one example row', function (string $type, string $firstHeader) {
    $this->actingAsRole(RoleName::Support);

    $response = $this->get('/api/imports/templates/'.$type)->assertOk();
    $lines = array_filter(explode("\n", trim($response->getContent())));

    expect($response->headers->get('Content-Disposition'))->toContain($type.'-import-template.csv')
        ->and($lines)->toHaveCount(2)
        ->and($lines[0])->toStartWith($firstHeader)
        ->and($lines[1])->toContain('@example.com');
})->with([['leads', 'client_discord_username'], ['clients', 'discord_username']]);

it('rejects a template for an unknown type', function () {
    $this->actingAsRole(RoleName::Support);

    $this->getJson('/api/imports/templates/orders')->assertNotFound();
});

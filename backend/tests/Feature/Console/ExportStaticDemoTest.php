<?php

use App\Console\Commands\ExportStaticDemoCommand;
use App\Models\PlatformAccount;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Every key name in a decoded JSON tree.
 *
 * @return list<string>
 */
function staticDemoKeys(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $keys = [];
    foreach ($node as $key => $child) {
        if (is_string($key)) {
            $keys[] = strtolower($key);
        }
        array_push($keys, ...staticDemoKeys($child));
    }

    return $keys;
}

beforeEach(function () {
    $this->exportDir = storage_path('framework/testing/static-demo-'.bin2hex(random_bytes(4)));
});

afterEach(function () {
    File::deleteDirectory($this->exportDir);
});

it('refuses to run outside local and testing without --force', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('demo:export-static', ['--path' => $this->exportDir])
            ->expectsOutputToContain('Pass --force')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect(File::exists($this->exportDir.'/'.ExportStaticDemoCommand::FILE))->toBeFalse();
});

it('exports the seeded demo data as JSON without secrets or credential values', function () {
    // Collect the plaintext credentials the seeders create, to prove none of them is exported.
    $secrets = [];
    Event::listen('eloquent.created: '.PlatformAccount::class, function (PlatformAccount $account) use (&$secrets): void {
        foreach (['email_password', 'discord_password', 'recovery_phone', 'phone_holder_name'] as $field) {
            $secrets[] = (string) $account->getAttribute($field);
        }
    });
    Event::listen('eloquent.created: '.SocialAccount::class, function (SocialAccount $account) use (&$secrets): void {
        $secrets[] = (string) $account->getAttribute('password');
    });

    $this->artisan('demo:export-static', ['--path' => $this->exportDir])->assertSuccessful();

    $json = File::get($this->exportDir.'/'.ExportStaticDemoCommand::FILE);
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($data)->toBeArray()
        ->and($data['version'])->toBe(1)
        ->and($data['users'])->toHaveCount(14)
        ->and($data['leads'])->not->toBeEmpty()
        ->and($data['orders'])->not->toBeEmpty()
        ->and($data['approvals'])->not->toBeEmpty()
        ->and($data['role_permissions']['admin'])->toHaveCount(71)
        ->and($data['role_permissions']['sales_executive'])->toHaveCount(26)
        ->and($data['analytics']['snapshots'])->not->toBeEmpty()
        ->and(strlen($json))->toBeLessThan(2 * 1024 * 1024);

    // Real Resource shapes: enums and money as the API formats them.
    expect($data['leads'][0]['stage'])->toHaveKeys(['value', 'label'])
        ->and($data['orders'][0]['total'])->toHaveKeys(['amount_cents', 'currency', 'formatted']);

    // Every active user has a dashboard snapshot for the default range.
    foreach ($data['users'] as $user) {
        if ($user['is_active']) {
            expect($data['analytics']['index'])->toHaveKey($user['id'].'|30d|');
        }
    }

    // The demo accounts sign in with shared-account flags.
    expect($data['me']['1']['demo_mode'])->toBeTrue()
        ->and($data['me']['1']['two_factor_enabled'])->toBeFalse();

    // No secret-looking keys anywhere in the tree.
    $forbidden = collect(staticDemoKeys($data))->filter(fn (string $key): bool => $key === 'password'
        || str_contains($key, 'secret')
        || $key === 'remember_token'
        || str_starts_with($key, 'two_factor_secret')
        || str_starts_with($key, 'two_factor_recovery')
        || in_array($key, ['email_password', 'discord_password', 'recovery_phone', 'phone_holder_name'], true));
    expect($forbidden->all())->toBe([]);

    // No credential values, password hashes or encrypted payloads.
    expect($secrets)->not->toBeEmpty();
    foreach (array_unique($secrets) as $secret) {
        if (strlen($secret) >= 8) {
            expect(str_contains($json, json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))->toBeFalse();
        }
    }
    expect($json)->not->toMatch('/\$2y\$\d{2}\$/')
        ->and($json)->not->toContain('eyJpdiI6')
        ->and($json)->not->toContain('Demo@12345');

    // The export used its own throw-away database: the test database is untouched and empty.
    expect(User::query()->count())->toBe(0);
});

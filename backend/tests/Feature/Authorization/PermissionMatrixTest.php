<?php

use App\Enums\RoleName;
use App\Models\User;
use App\Support\PermissionMatrix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('gives every role exactly the matrix permissions', function (RoleName $role) {
    $actual = Role::findByName($role->value, 'web')->permissions->pluck('name')->sort()->values()->all();
    $expected = PermissionMatrix::forRole($role);
    sort($expected);

    expect($actual)->toBe($expected);
})->with(RoleName::cases());

it('gives admin every permission', function () {
    expect(Role::findByName('admin', 'web')->permissions)->toHaveCount(Permission::count())
        ->and(Permission::count())->toBe(count(PermissionMatrix::permissions()));
});

it('matches the documented role sizes', function () {
    expect(count(PermissionMatrix::forRole(RoleName::Admin)))->toBe(69)
        ->and(count(PermissionMatrix::forRole(RoleName::Support)))->toBe(59)
        ->and(count(PermissionMatrix::forRole(RoleName::TeamLead)))->toBe(37)
        ->and(count(PermissionMatrix::forRole(RoleName::SalesExecutive)))->toBe(26);
});

it('keeps the key rules of the matrix', function () {
    $se = PermissionMatrix::forRole(RoleName::SalesExecutive);
    $tl = PermissionMatrix::forRole(RoleName::TeamLead);
    $support = PermissionMatrix::forRole(RoleName::Support);

    expect($se)->toContain('platform-accounts.reveal-credentials', 'leads.request-change')
        ->not->toContain('leads.update', 'platform-accounts.view-team', 'users.view')
        ->and($tl)->not->toContain('platform-accounts.reveal-credentials', 'leads.view-all', 'leads.delete')
        ->and($tl)->toContain('leads.reassign', 'approvals.review-team', 'users.view')
        ->and($support)->toContain('approvals.review-all', 'users.deactivate')
        ->not->toContain('audit-log.view', 'users.delete', 'users.manage-privileged', 'teams.manage', 'leads.request-change');
});

it('seeds idempotently', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(69)
        ->and(Role::count())->toBe(4);

    $agent = User::factory()->salesExecutive()->create();
    expect($agent->can('leads.update'))->toBeFalse()
        ->and($agent->can('leads.create'))->toBeTrue();
});

it('has no super-admin bypass: an admin is denied what the matrix does not give', function () {
    $admin = User::factory()->admin()->create();
    $admin->roles->first()->revokePermissionTo('leads.delete');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($admin->fresh()->can('leads.delete'))->toBeFalse();
});

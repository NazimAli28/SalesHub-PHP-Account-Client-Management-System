<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->alpha = Team::factory()->create();
    $this->bravo = Team::factory()->create();

    $this->admin = $this->makeUser(RoleName::Admin);
    $this->support = $this->makeUser(RoleName::Support);
    $this->otherSupport = $this->makeUser(RoleName::Support);
    $this->leadA = $this->makeStaff(RoleName::TeamLead, $this->alpha);
    $this->agentA = $this->makeStaff(RoleName::SalesExecutive, $this->alpha);
    $this->agentB = $this->makeStaff(RoleName::SalesExecutive, $this->bravo);
});

it('lets support manage team leads and sales executives only', function () {
    expect($this->support->can('update', $this->agentA))->toBeTrue()
        ->and($this->support->can('update', $this->leadA))->toBeTrue()
        ->and($this->support->can('deactivate', $this->agentB))->toBeTrue()
        ->and($this->support->can('update', $this->admin))->toBeFalse()
        ->and($this->support->can('deactivate', $this->admin))->toBeFalse()
        ->and($this->support->can('update', $this->otherSupport))->toBeFalse()
        ->and($this->support->can('deactivate', $this->otherSupport))->toBeFalse()
        ->and($this->support->can('delete', $this->agentA))->toBeFalse();
});

it('only lets admin hand out the admin and support roles', function () {
    expect($this->support->can('assignRole', [$this->agentA, RoleName::Admin]))->toBeFalse()
        ->and($this->support->can('assignRole', [$this->agentA, RoleName::Support]))->toBeFalse()
        ->and($this->support->can('assignRole', [$this->agentA, RoleName::TeamLead]))->toBeTrue()
        ->and($this->support->can('assignRole', [$this->admin, RoleName::SalesExecutive]))->toBeFalse()
        ->and($this->admin->can('assignRole', [$this->agentA, RoleName::Admin]))->toBeTrue()
        ->and($this->admin->can('assignRole', [$this->agentA, RoleName::Support]))->toBeTrue()
        ->and($this->agentA->can('assignRole', [$this->agentB, RoleName::SalesExecutive]))->toBeFalse();
});

it('allows creating users only for support and admin, with privileged roles for admin only', function () {
    $draft = new User;

    expect($this->support->can('create', User::class))->toBeTrue()
        ->and($this->leadA->can('create', User::class))->toBeFalse()
        ->and($this->support->can('assignRole', [$draft, RoleName::SalesExecutive]))->toBeTrue()
        ->and($this->support->can('assignRole', [$draft, RoleName::Admin]))->toBeFalse()
        ->and($this->admin->can('assignRole', [$draft, RoleName::Support]))->toBeTrue();
});

it('stops anyone from deactivating or deleting themselves', function () {
    $second = $this->makeUser(RoleName::Admin);

    expect($this->admin->can('deactivate', $this->admin))->toBeFalse()
        ->and($this->admin->can('delete', $this->admin))->toBeFalse()
        ->and($this->admin->can('delete', $second))->toBeTrue()
        ->and($this->admin->can('deactivate', $second))->toBeTrue();
});

it('protects the last active admin', function () {
    // An admin-equivalent actor who is not himself an admin could otherwise remove the only admin.
    $manager = $this->makeUser(RoleName::Support);
    $manager->givePermissionTo(['users.delete', 'users.manage-privileged']);

    expect($manager->can('deactivate', $this->admin))->toBeFalse()
        ->and($manager->can('delete', $this->admin))->toBeFalse()
        ->and($manager->can('assignRole', [$this->admin, RoleName::SalesExecutive]))->toBeFalse()
        ->and($manager->can('assignRole', [$this->admin, RoleName::Admin]))->toBeTrue();

    $second = $this->makeUser(RoleName::Admin);

    expect($manager->can('deactivate', $this->admin))->toBeTrue()
        ->and($manager->can('delete', $this->admin))->toBeTrue()
        ->and($manager->can('assignRole', [$this->admin, RoleName::SalesExecutive]))->toBeTrue();

    $second->update(['is_active' => false]);

    expect($manager->can('deactivate', $this->admin))->toBeFalse();
});

it('scopes who can view users', function () {
    expect($this->agentA->can('view', $this->agentA))->toBeTrue()
        ->and($this->agentA->can('view', $this->leadA))->toBeFalse()
        ->and($this->leadA->can('view', $this->agentA))->toBeTrue()
        ->and($this->leadA->can('view', $this->agentB))->toBeFalse()
        ->and($this->leadA->can('viewAny', User::class))->toBeTrue()
        ->and($this->agentA->can('viewAny', User::class))->toBeFalse()
        ->and($this->support->can('view', $this->agentB))->toBeTrue()
        ->and($this->leadA->can('update', $this->agentA))->toBeFalse();
});

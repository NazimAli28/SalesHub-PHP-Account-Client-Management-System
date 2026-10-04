<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Support\PermissionMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every role and permission from PermissionMatrix. Idempotent; also run on production deploys.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PermissionMatrix::permissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, 'web')
                ->syncPermissions(PermissionMatrix::forRole($role));
        }

        $registrar->forgetCachedPermissions();
    }
}

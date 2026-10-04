<?php

namespace App\Support;

use App\Enums\RoleName;

/**
 * The single source of the role x permission matrix (docs/architecture/auth-and-permissions.md section 3).
 */
final class PermissionMatrix
{
    private const S = 0;

    private const TL = 1;

    private const SE = 2;

    /**
     * Every permission, in matrix order.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return list<string>
     */
    public static function forRole(RoleName $role): array
    {
        if ($role === RoleName::Admin) {
            return self::permissions();
        }

        $column = match ($role) {
            RoleName::Support => self::S,
            RoleName::TeamLead => self::TL,
            default => self::SE,
        };

        $granted = [];

        foreach (self::definitions() as $name => $columns) {
            if (in_array($column, $columns, true)) {
                $granted[] = $name;
            }
        }

        return $granted;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $matrix = [];

        foreach (RoleName::cases() as $role) {
            $matrix[$role->value] = self::forRole($role);
        }

        return $matrix;
    }

    /**
     * Permission => non-admin columns that hold it. Admin holds everything, so it is implicit.
     *
     * @return array<string, list<int>>
     */
    private static function definitions(): array
    {
        $all = [self::S, self::TL, self::SE];
        $st = [self::S, self::TL];
        $se = [self::S, self::SE];
        $tlse = [self::TL, self::SE];

        return [
            'dashboard.view' => $all,

            'reports.view-all' => [self::S],
            'reports.view-team' => $st,
            'reports.view-own' => $all,
            'reports.export' => $st,

            'platform-accounts.view-all' => [self::S],
            'platform-accounts.view-team' => $st,
            'platform-accounts.view-own' => $all,
            'platform-accounts.create' => [self::S],
            'platform-accounts.update' => [self::S],
            'platform-accounts.delete' => [self::S],
            'platform-accounts.assign' => [self::S],
            'platform-accounts.change-standing' => [self::S],
            'platform-accounts.reveal-credentials' => $se,
            'platform-accounts.request-new' => $all,
            'platform-accounts.request-change' => $tlse,

            'social-accounts.view-all' => [self::S],
            'social-accounts.view-team' => $st,
            'social-accounts.view-own' => $all,
            'social-accounts.create' => $se,
            'social-accounts.update' => [self::S],
            'social-accounts.delete' => [self::S],
            'social-accounts.reveal-credentials' => $se,
            'social-accounts.request-change' => [self::SE],

            'clients.view-all' => [self::S],
            'clients.view-team' => $st,
            'clients.view-own' => $all,
            'clients.create' => $all,
            'clients.update' => $st,
            'clients.delete' => [self::S],
            'clients.request-change' => $tlse,
            'clients.import' => [self::S],

            'leads.view-all' => [self::S],
            'leads.view-team' => $st,
            'leads.view-own' => $all,
            'leads.create' => $all,
            'leads.update' => $st,
            'leads.delete' => [self::S],
            'leads.reassign' => $st,
            'leads.request-change' => $tlse,
            'leads.import' => [self::S],

            'orders.view-all' => [self::S],
            'orders.view-team' => $st,
            'orders.view-own' => $all,
            'orders.create' => $all,
            'orders.update' => $st,
            'orders.delete' => [self::S],
            'orders.request-change' => $tlse,

            'payments.create' => $all,
            'payments.update' => $st,
            'payments.delete' => [self::S],
            'payments.request-change' => $tlse,

            'services.view' => $all,
            'services.manage' => [self::S],

            'teams.view' => $all,
            'teams.manage' => [],

            'workstations.view' => $all,
            'workstations.manage' => [self::S],

            'users.view' => $st,
            'users.create' => [self::S],
            'users.update' => [self::S],
            'users.deactivate' => [self::S],
            'users.delete' => [],
            'users.manage-privileged' => [],

            'approvals.view-all' => [self::S],
            'approvals.view-team' => $st,
            'approvals.view-own' => $all,
            'approvals.review-all' => [self::S],
            'approvals.review-team' => $st,

            'audit-log.view' => [],

            'notifications.view' => $all,
        ];
    }
}

<?php

namespace App\Approvals;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;

/**
 * Field-level diff for the review screen: `[{field, before, after}]`.
 * `after` is the applied value once approved, otherwise the proposed value.
 */
final class ApprovalDiff
{
    /**
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    public static function for(ApprovalRequest $approval): array
    {
        $payload = $approval->payload ?? [];
        $before = $approval->before ?? [];
        $applied = $approval->status === ApprovalStatus::Approved ? ($approval->after ?? []) : null;

        return match ($approval->action) {
            ApprovalAction::Update => self::rows(
                [...($payload['changes'] ?? []), ...($payload['relations'] ?? [])],
                fn (string $field) => $before[$field] ?? $before['relations'][$field] ?? null,
                fn (string $field, mixed $proposed) => $applied === null ? $proposed : ($applied[$field] ?? $applied['relations'][$field] ?? $proposed),
            ),
            ApprovalAction::Delete => self::rows(
                array_diff_key($before, array_flip(['updated_at', 'relations'])),
                fn (string $field) => $before[$field] ?? null,
                fn () => null,
            ),
            ApprovalAction::Create => self::rows(
                [...($payload['attributes'] ?? []), ...($payload['relations'] ?? [])],
                fn () => null,
                fn (string $field, mixed $proposed) => $proposed,
            ),
            ApprovalAction::RequestAccounts => self::rows(
                $payload,
                fn () => null,
                fn (string $field, mixed $proposed) => $proposed,
            ),
        };
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @param  callable(string): mixed  $before
     * @param  callable(string, mixed): mixed  $after
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    private static function rows(array $fields, callable $before, callable $after): array
    {
        $rows = [];

        foreach ($fields as $field => $value) {
            $field = (string) $field;
            $rows[] = ['field' => $field, 'before' => $before($field), 'after' => $after($field, $value)];
        }

        return $rows;
    }
}

<?php

namespace App\Approvals;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Before/after snapshots of approvable records (data-model section 6.3) and the staleness check.
 *
 * A snapshot is the record's serialized attributes (`attributesToArray`, so hidden and encrypted columns are
 * never included), optionally limited to some keys, plus `relations` (sorted related IDs) and a normalized
 * `updated_at`. It is JSON-normalized so a stored snapshot compares equal to a freshly taken one.
 */
final class RecordSnapshot
{
    /**
     * @param  list<string>|null  $keys  Attribute keys to keep; null keeps every visible attribute.
     * @param  list<string>  $relations  BelongsToMany relation names to include as ID lists.
     * @return array<string, mixed>
     */
    public static function of(Model $record, ?array $keys = null, array $relations = []): array
    {
        $attributes = $record->attributesToArray();
        $snapshot = $keys === null ? $attributes : Arr::only($attributes, $keys);

        foreach ($relations as $relation) {
            $snapshot['relations'][$relation] = self::relatedIds($record, $relation);
        }

        $updatedAt = $record->getAttribute($record->getUpdatedAtColumn() ?? 'updated_at');
        $snapshot['updated_at'] = $updatedAt instanceof CarbonInterface ? $updatedAt->toIso8601ZuluString() : null;

        /** @var array<string, mixed> */
        return json_decode((string) json_encode($snapshot), true);
    }

    /**
     * True when the record no longer matches the snapshot taken at submission.
     *
     * @param  array<string, mixed>  $before
     */
    public static function isStale(Model $current, array $before): bool
    {
        $keys = array_keys(Arr::except($before, ['updated_at', 'relations']));
        $relations = array_keys(is_array($before['relations'] ?? null) ? $before['relations'] : []);

        return self::of($current, array_map('strval', $keys), array_map('strval', $relations)) != $before;
    }

    /**
     * @return list<int>
     */
    public static function relatedIds(Model $record, string $relation): array
    {
        $query = $record->{$relation}();

        if (! $query instanceof BelongsToMany) {
            throw new LogicException("Approval relation [{$relation}] must be a BelongsToMany relation.");
        }

        $ids = $query->pluck($query->getRelated()->getQualifiedKeyName())
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        return $ids;
    }
}

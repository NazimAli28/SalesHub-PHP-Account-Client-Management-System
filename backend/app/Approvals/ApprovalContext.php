<?php

namespace App\Approvals;

use App\Models\ApprovalRequest;
use Closure;

/**
 * Remembers which approval request is being applied, so model activity entries written while applying
 * it carry `approval_request_id` (data-model section 7; see LogsModelActivity::beforeActivityLogged).
 */
final class ApprovalContext
{
    private static ?int $current = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(ApprovalRequest $approval, Closure $callback): mixed
    {
        $previous = self::$current;
        self::$current = $approval->id;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public static function currentId(): ?int
    {
        return self::$current;
    }
}

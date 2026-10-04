<?php

namespace App\Exceptions;

use App\Models\ApprovalRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * HTTP 409 for approval requests that can no longer be decided: already decided, or the target
 * record changed (or disappeared) after submission. Body: `{"message": "...", "code": "..."}`.
 */
class ApprovalConflictException extends ConflictHttpException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?ApprovalRequest $approval = null,
    ) {
        parent::__construct($message);
    }

    public static function alreadyDecided(): self
    {
        return new self('This request has already been decided.', 'approval_already_decided');
    }

    public static function failed(ApprovalRequest $approval): self
    {
        return new self(
            $approval->failure_message ?? 'The request could not be applied.',
            'approval_failed',
            $approval,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], 409);
    }
}

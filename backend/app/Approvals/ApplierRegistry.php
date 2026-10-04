<?php

namespace App\Approvals;

use App\Approvals\Appliers\RequestAccountsApplier;
use App\Approvals\Contracts\ApprovalApplier;
use App\Enums\ApprovalAction;
use App\Models\ApprovalRequest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use LogicException;

/**
 * Resolves the applier for an approval request by convention, so modules never edit a shared list:
 * morph alias `lead` -> App\Approvals\Appliers\LeadApplier, `social_account` -> SocialAccountApplier.
 * Action `request_accounts` (no target record) -> RequestAccountsApplier.
 */
final class ApplierRegistry
{
    public const NAMESPACE = 'App\\Approvals\\Appliers\\';

    public function __construct(private readonly Container $container) {}

    public function for(ApprovalRequest $approval): ApprovalApplier
    {
        if ($approval->action === ApprovalAction::RequestAccounts) {
            return $this->container->make(RequestAccountsApplier::class);
        }

        return $this->forType((string) $approval->approvable_type);
    }

    public function forType(string $morphAlias): ApprovalApplier
    {
        $class = $this->classFor($morphAlias);

        if (! class_exists($class) || ! is_subclass_of($class, ApprovalApplier::class)) {
            throw new LogicException("No approval applier for [{$morphAlias}]: create {$class} implementing ".ApprovalApplier::class.'.');
        }

        /** @var ApprovalApplier */
        return $this->container->make($class);
    }

    public function supports(string $morphAlias): bool
    {
        $class = $this->classFor($morphAlias);

        return class_exists($class) && is_subclass_of($class, ApprovalApplier::class);
    }

    private function classFor(string $morphAlias): string
    {
        return self::NAMESPACE.Str::studly($morphAlias).'Applier';
    }
}

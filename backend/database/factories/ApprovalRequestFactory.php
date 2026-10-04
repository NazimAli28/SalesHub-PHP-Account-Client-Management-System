<?php

namespace Database\Factories;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    /**
     * A pending "update lead" request.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => ApprovalAction::Update,
            'approvable_type' => 'lead',
            'approvable_id' => Lead::factory(),
            'payload' => ['changes' => ['last_message' => fake()->sentence()]],
            'before' => null,
            'after' => null,
            'status' => ApprovalStatus::Pending,
            'pending_key' => fn (array $attributes) => 'lead:'.$attributes['approvable_id'],
            'reason' => fake()->sentence(),
            'requested_by_id' => User::factory()->salesExecutive(),
        ];
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Approved,
            'pending_key' => null,
            'reviewed_by_id' => $reviewer?->getKey() ?? User::factory()->support(),
            'reviewed_at' => now()->subDay(),
            'applied_at' => now()->subDay(),
            'after' => $attributes['payload']['changes'] ?? null,
        ]);
    }

    public function rejected(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Rejected,
            'pending_key' => null,
            'reviewed_by_id' => $reviewer?->getKey() ?? User::factory()->support(),
            'reviewed_at' => now()->subDay(),
            'review_comment' => fake()->sentence(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Cancelled,
            'pending_key' => null,
        ]);
    }
}

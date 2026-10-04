<?php

namespace Database\Seeders;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class ApprovalRequestSeeder extends Seeder
{
    /**
     * 40 requests: 12 pending, 18 approved, 8 rejected, 2 cancelled.
     * Statuses are recorded as history only; nothing is applied to the target records.
     */
    public function run(): void
    {
        $requesters = User::role([RoleName::SalesExecutive->value, RoleName::TeamLead->value])
            ->where('is_active', true)->get();
        $reviewers = User::role([RoleName::Support->value, RoleName::Admin->value])->get();

        $leadIds = Lead::query()->inRandomOrder()->limit(34)->pluck('id')->all();
        $clientIds = Client::query()->inRandomOrder()->limit(6)->pluck('id')->all();

        $statuses = [
            ...array_fill(0, 12, ApprovalStatus::Pending),
            ...array_fill(0, 18, ApprovalStatus::Approved),
            ...array_fill(0, 8, ApprovalStatus::Rejected),
            ...array_fill(0, 2, ApprovalStatus::Cancelled),
        ];

        foreach ($statuses as $index => $status) {
            $requester = $requesters->random();
            $createdAt = now()->subDays(fake()->numberBetween(1, 170))->subMinutes(fake()->numberBetween(0, 900));
            if ($status === ApprovalStatus::Pending) {
                $createdAt = now()->subDays(fake()->numberBetween(0, 6))->subMinutes(fake()->numberBetween(0, 900));
            }

            // Every 5th request is an account request; every 7th is a client change; the rest touch leads.
            $attributes = match (true) {
                $index % 5 === 4 => $this->accountRequest($requester),
                $index % 7 === 6 && $clientIds !== [] => $this->clientUpdate(array_pop($clientIds)),
                default => $this->leadUpdate(array_pop($leadIds)),
            };

            $reviewed = $status !== ApprovalStatus::Pending && $status !== ApprovalStatus::Cancelled;
            $reviewer = $reviewers->random();

            ApprovalRequest::create([
                ...$attributes,
                'status' => $status,
                'pending_key' => $status === ApprovalStatus::Pending ? $attributes['pending_key'] : null,
                'reason' => fake()->sentence(),
                'requested_by_id' => $requester->id,
                'reviewed_by_id' => $reviewed ? $reviewer->id : null,
                'reviewed_at' => $reviewed ? $createdAt->copy()->addHours(fake()->numberBetween(1, 30)) : null,
                'review_comment' => $status === ApprovalStatus::Rejected ? 'Please attach the client confirmation first.' : ($reviewed ? 'Looks fine.' : null),
                'applied_at' => $status === ApprovalStatus::Approved ? $createdAt->copy()->addHours(fake()->numberBetween(1, 30)) : null,
                'after' => $status === ApprovalStatus::Approved ? ($attributes['payload']['changes'] ?? $attributes['payload']) : null,
            ])->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function accountRequest(User $requester): array
    {
        return [
            'action' => ApprovalAction::RequestAccounts,
            'approvable_type' => null,
            'approvable_id' => null,
            'payload' => [
                'workstation_id' => $requester->workstation_id,
                'quantity' => fake()->numberBetween(1, 3),
                'note' => 'Accounts were limited this week.',
            ],
            'before' => null,
            'pending_key' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadUpdate(int $leadId): array
    {
        $lead = Lead::query()->findOrFail($leadId);
        $changes = fake()->boolean(60)
            ? ['last_message' => 'Client confirmed the final scope.']
            : ['next_follow_up_on' => now()->addDays(fake()->numberBetween(2, 10))->toDateString()];

        return [
            'action' => ApprovalAction::Update,
            'approvable_type' => 'lead',
            'approvable_id' => $leadId,
            'payload' => ['changes' => $changes],
            'before' => [...Arr::only($lead->attributesToArray(), array_keys($changes)), 'updated_at' => $lead->updated_at?->toIso8601String()],
            'pending_key' => 'lead:'.$leadId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientUpdate(int $clientId): array
    {
        $client = Client::query()->findOrFail($clientId);
        $changes = ['nurturing_rating' => fake()->numberBetween(30, 95)];

        return [
            'action' => ApprovalAction::Update,
            'approvable_type' => 'client',
            'approvable_id' => $clientId,
            'payload' => ['changes' => $changes],
            'before' => [...Arr::only($client->attributesToArray(), array_keys($changes)), 'updated_at' => $client->updated_at?->toIso8601String()],
            'pending_key' => 'client:'.$clientId,
        ];
    }
}

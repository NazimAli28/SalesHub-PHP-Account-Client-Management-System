<?php

namespace Database\Factories;

use App\Enums\LeadLostReason;
use App\Enums\LeadStage;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contacted = fake()->dateTimeBetween('-6 months', 'now');

        return [
            'client_id' => Client::factory(),
            'owner_id' => User::factory()->salesExecutive(),
            'closer_id' => null,
            'platform_account_id' => null,
            'stage' => LeadStage::New,
            'stage_changed_at' => $contacted,
            'contacted_on' => $contacted->format('Y-m-d'),
            'estimated_value_cents' => fake()->numberBetween(2, 40) * 1000,
            'currency' => 'USD',
            'last_message' => fake()->sentence(),
            'next_follow_up_on' => now()->addDays(fake()->numberBetween(1, 14))->toDateString(),
            'lost_reason' => null,
            'lost_note' => null,
            'order_id' => null,
        ];
    }

    public function stage(LeadStage $stage): static
    {
        return $this->state(fn (array $attributes) => ['stage' => $stage]);
    }

    public function engaged(): static
    {
        return $this->stage(LeadStage::Engaged);
    }

    public function portfolioShared(): static
    {
        return $this->stage(LeadStage::PortfolioShared);
    }

    public function quoted(): static
    {
        return $this->stage(LeadStage::Quoted);
    }

    public function paymentPending(): static
    {
        return $this->stage(LeadStage::PaymentPending);
    }

    public function won(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => LeadStage::Won,
            'next_follow_up_on' => null,
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => LeadStage::Lost,
            'next_follow_up_on' => null,
            'lost_reason' => fake()->randomElement(LeadLostReason::cases()),
            'lost_note' => fake()->sentence(),
        ]);
    }
}

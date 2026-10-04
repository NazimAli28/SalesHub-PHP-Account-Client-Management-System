<?php

namespace Database\Seeders;

use App\Enums\LeadLostReason;
use App\Enums\LeadStage;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PlatformAccount;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeadSeeder extends Seeder
{
    /**
     * 400 leads over the last six months: 180 open (45%), 100 won (25%), 120 lost (30%).
     * Won leads are linked to their order by OrderSeeder.
     */
    public function run(): void
    {
        $plan = [
            ...array_fill(0, 50, LeadStage::New),
            ...array_fill(0, 45, LeadStage::Engaged),
            ...array_fill(0, 35, LeadStage::PortfolioShared),
            ...array_fill(0, 30, LeadStage::Quoted),
            ...array_fill(0, 20, LeadStage::PaymentPending),
            ...array_fill(0, 100, LeadStage::Won),
            ...array_fill(0, 120, LeadStage::Lost),
        ];
        mt_srand(2026);
        shuffle($plan);

        $clients = Client::query()->get();
        $serviceIds = Service::query()->where('slug', '!=', 'custom')->pluck('id')->all();
        $users = User::query()->with('team')->get()->keyBy('id');
        $accountsByStation = PlatformAccount::query()->whereNotNull('workstation_id')->get()->groupBy('workstation_id');

        $messages = [
            'Thanks, I will check the samples tonight.',
            'Can you do it a bit cheaper?',
            'Sent the portfolio link.',
            'Waiting for their payday.',
            'Asked for two extra revisions.',
            'Loves the style, needs a quote.',
            'Sent the package breakdown.',
        ];

        foreach ($plan as $stage) {
            /** @var Client $client */
            $client = fake()->randomElement($clients->all());
            $owner = $users[$client->owner_id];
            $contactedDaysAgo = fake()->numberBetween(1, 180);
            $contacted = now()->subDays($contactedDaysAgo);
            $changedDaysAgo = fake()->numberBetween(0, $contactedDaysAgo);
            $changedAt = now()->subDays($changedDaysAgo)->subMinutes(fake()->numberBetween(0, 600));
            $accounts = $accountsByStation[$owner->workstation_id] ?? collect();

            $factory = Lead::factory()->stage($stage);

            if ($stage === LeadStage::Lost) {
                $factory = $factory->lost();
            }

            $lead = $factory->create([
                'client_id' => $client->id,
                'owner_id' => $owner->id,
                'closer_id' => $stage === LeadStage::Won && fake()->boolean(35) ? $owner->team?->team_lead_id : null,
                'platform_account_id' => $accounts->isEmpty() ? null : $accounts->random()->id,
                'stage' => $stage,
                'stage_changed_at' => $changedAt,
                'contacted_on' => $contacted->toDateString(),
                'estimated_value_cents' => fake()->numberBetween(2, 60) * 1000,
                'last_message' => fake()->randomElement($messages),
                'next_follow_up_on' => $stage->isOpen() ? now()->addDays(fake()->numberBetween(-5, 14))->toDateString() : null,
                'lost_reason' => $stage === LeadStage::Lost ? fake()->randomElement(LeadLostReason::cases()) : null,
                'lost_note' => $stage === LeadStage::Lost ? fake()->randomElement(['Stopped replying after the quote.', 'Found a cheaper designer.', 'Not ready to spend yet.']) : null,
            ]);

            $lead->services()->sync(fake()->randomElements($serviceIds, fake()->numberBetween(1, 3)));
        }
    }
}

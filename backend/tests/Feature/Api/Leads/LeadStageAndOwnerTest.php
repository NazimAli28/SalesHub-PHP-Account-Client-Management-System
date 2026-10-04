<?php

use App\Enums\LeadLostReason;
use App\Enums\LeadStage;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Order;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->lead = Lead::factory()->quoted()->create([
        'owner_id' => $this->agent->id,
        'stage_changed_at' => now()->subWeek(),
        'next_follow_up_on' => today()->addDays(3)->toDateString(),
    ]);
});

describe('stage', function () {
    it('requires a lost reason to move a lead to lost', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'lost'])
            ->assertUnprocessable()->assertJsonValidationErrors(['lost_reason']);
        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'lost', 'lost_reason' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors(['lost_reason']);
    });

    it('moves to lost, stamps stage_changed_at and clears the follow-up date', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}/stage", [
            'stage' => 'lost', 'lost_reason' => 'price', 'lost_note' => 'Went with a cheaper artist',
        ])->assertOk()
            ->assertJsonPath('data.stage.value', 'lost')
            ->assertJsonPath('data.lost_reason', ['value' => 'price', 'label' => 'Price'])
            ->assertJsonPath('data.next_follow_up_on', null);

        $lead = $this->lead->fresh();
        expect($lead->stage)->toBe(LeadStage::Lost)
            ->and($lead->stage_changed_at->isToday())->toBeTrue()
            ->and($lead->lost_note)->toBe('Went with a cheaper artist');
    });

    it('clears the lost reason when a lost lead is reopened', function () {
        $this->lead->update(['stage' => LeadStage::Lost, 'lost_reason' => LeadLostReason::NoResponse, 'lost_note' => 'Ghosted']);
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'engaged'])->assertOk();

        expect($this->lead->fresh())
            ->stage->toBe(LeadStage::Engaged)
            ->lost_reason->toBeNull()
            ->lost_note->toBeNull();
    });

    it('needs an order of the same client to mark a lead won', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $foreignClientOrder = Order::factory()->create(['owner_id' => $this->agent->id, 'client_id' => Client::factory()]);
        $order = Order::factory()->create(['owner_id' => $this->agent->id, 'client_id' => $this->lead->client_id]);

        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'won'])
            ->assertUnprocessable()->assertJsonValidationErrors(['order_id']);
        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'won', 'order_id' => $foreignClientOrder->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['order_id']);

        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'won', 'order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.stage.value', 'won')
            ->assertJsonPath('data.order_id', $order->id);
    });

    it('queues a stage move by a sales executive (202)', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'payment_pending'])
            ->assertAccepted()
            ->assertJsonPath('data.payload.changes', ['stage' => 'payment_pending']);

        expect($this->lead->fresh()->stage)->toBe(LeadStage::Quoted);
    });

    it('denies a stage move outside the user scope (403)', function () {
        $this->actingAsUser($this->bravo->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}/stage", ['stage' => 'engaged'])->assertForbidden();
    });
});

describe('owner', function () {
    it('lets a team lead reassign within the team', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->patchJson("/api/leads/{$this->lead->id}/owner", ['owner_id' => $this->alpha->agent(1)->id])
            ->assertOk()
            ->assertJsonPath('data.owner.id', $this->alpha->agent(1)->id);

        expect(ApprovalRequest::query()->count())->toBe(0);
    });

    it('rejects an owner outside the team or inactive', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $this->alpha->agent(1)->update(['is_active' => false]);

        $this->patchJson("/api/leads/{$this->lead->id}/owner", ['owner_id' => $this->bravo->agent(0)->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);
        $this->patchJson("/api/leads/{$this->lead->id}/owner", ['owner_id' => $this->alpha->agent(1)->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);
    });

    it('requires leads.reassign (403 for a sales executive)', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/leads/{$this->lead->id}/owner", ['owner_id' => $this->agent->id])->assertForbidden();
    });
});

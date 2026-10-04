<?php

use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Models\ApprovalRequest;
use App\Models\Payment;
use Tests\Support\OrderFixtures;

beforeEach(function () {
    $this->alpha = $this->makeTeamWithMembers(2);
    $this->bravo = $this->makeTeamWithMembers(1);
    $this->agent = $this->alpha->agent(0);
    $this->order = OrderFixtures::make($this->agent, 100000);
    $this->first = Payment::factory()->create(['order_id' => $this->order->id, 'sequence' => 1, 'amount_cents' => 40000, 'due_date' => today()->addDays(2)->toDateString(), 'notes' => 'Original']);
});

describe('store (schedule an installment)', function () {
    it('schedules the next installment with the next sequence and the order currency (201)', function (string $who) {
        $this->actingAsUser($who === 'agent' ? $this->agent : $this->alpha->teamLead);

        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 60000, 'due_date' => today()->addDays(10)->toDateString(), 'notes' => 'Second half'])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.amount.formatted', '$600.00')
            ->assertJsonPath('data.status.value', 'scheduled')
            ->assertJsonPath('data.order_id', $this->order->id);

        expect(ApprovalRequest::query()->count())->toBe(0);
    })->with(['agent', 'team lead']);

    it('rejects installments that would exceed the order total (422)', function () {
        $this->actingAsRole(RoleName::Support);
        $due = today()->addDays(5)->toDateString();

        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 60001, 'due_date' => $due])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount_cents']);
        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 60000, 'due_date' => $due])->assertCreated();
        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 1, 'due_date' => $due])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount_cents']);
    });

    it('does not count void payments towards the total', function () {
        $this->first->update(['status' => PaymentStatus::Void]);
        $this->actingAsRole(RoleName::Support);

        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 100000, 'due_date' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.sequence', 2);
    });

    it('validates the payload and the currency (422)', function () {
        $this->actingAsUser($this->agent);

        $this->postJson("/api/orders/{$this->order->id}/payments", [])->assertUnprocessable()->assertJsonValidationErrors(['amount_cents', 'due_date']);
        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 0, 'due_date' => '10/10/2026', 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount_cents', 'due_date', 'method']);
        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 1000, 'due_date' => today()->toDateString(), 'currency' => 'EUR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency']);
    });

    it('denies scheduling on an order out of scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));

        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 1000, 'due_date' => today()->toDateString()])->assertForbidden();
    });
});

describe('show', function () {
    it('returns a visible payment, 403 out of scope, 404 when missing', function () {
        $this->actingAsUser($this->agent);
        $this->getJson("/api/payments/{$this->first->id}")->assertOk()->assertJsonPath('data.id', $this->first->id);
        $this->getJson('/api/payments/999999')->assertNotFound();

        $this->actingAsUser($this->bravo->teamLead);
        $this->getJson("/api/payments/{$this->first->id}")->assertForbidden();
    });
});

describe('update', function () {
    it('applies directly for a team lead and support (200)', function () {
        $this->actingAsUser($this->alpha->teamLead);
        $this->patchJson("/api/payments/{$this->first->id}", ['amount_cents' => 50000, 'notes' => 'Changed'])
            ->assertOk()->assertJsonPath('data.amount.amount_cents', 50000)->assertJsonPath('data.notes', 'Changed');

        $this->actingAsRole(RoleName::Support);
        $this->putJson("/api/payments/{$this->first->id}", ['status' => 'void'])->assertOk()->assertJsonPath('data.status.value', 'void');
    });

    it('queues the change for a sales executive (202) and keeps the payment unchanged', function () {
        $this->actingAsUser($this->agent);

        $this->patchJson("/api/payments/{$this->first->id}", ['amount_cents' => 30000, 'reason' => 'Client asked'])
            ->assertAccepted()->assertJsonPath('data.payload.changes', ['amount_cents' => 30000]);

        expect($this->first->fresh()->amount_cents)->toBe(40000);
        $this->getJson("/api/payments/{$this->first->id}")->assertJsonPath('data.pending_change.fields', ['amount_cents']);
        $this->patchJson("/api/payments/{$this->first->id}", ['notes' => 'x'])->assertUnprocessable();
    });

    it('applies an approved edit through the same validation (refused when the schedule no longer fits)', function () {
        $second = Payment::factory()->create(['order_id' => $this->order->id, 'sequence' => 2, 'amount_cents' => 30000]);

        $this->actingAsUser($this->agent);
        $approval = $this->patchJson("/api/payments/{$this->first->id}", ['amount_cents' => 70000])->assertAccepted()->json('data.id');

        // Another installment grows after the request was queued, so the approved edit would exceed the total.
        $second->update(['amount_cents' => 60000]);
        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$approval}/approve")->assertUnprocessable();
        expect($this->first->fresh()->amount_cents)->toBe(40000);
    });

    it('approves a queued edit of a payment and applies it', function () {
        $this->actingAsUser($this->agent);
        $approval = $this->patchJson("/api/payments/{$this->first->id}", ['amount_cents' => 70000, 'due_date' => '2027-01-15'])->assertAccepted()->json('data.id');

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$approval}/approve")->assertOk();

        $payment = $this->first->fresh();
        expect($payment->amount_cents)->toBe(70000)->and($payment->due_date->toDateString())->toBe('2027-01-15');
    });

    it('enforces the payment-sum rule on update (422)', function () {
        Payment::factory()->create(['order_id' => $this->order->id, 'sequence' => 2, 'amount_cents' => 50000]);
        $this->actingAsRole(RoleName::Support);

        $this->patchJson("/api/payments/{$this->first->id}", ['amount_cents' => 50001])->assertUnprocessable()->assertJsonValidationErrors(['amount_cents']);
        $this->patchJson("/api/payments/{$this->first->id}", ['amount_cents' => 50000])->assertOk();
    });

    it('prohibits immutable fields and status paid (422)', function () {
        $this->actingAsRole(RoleName::Support);

        $this->patchJson("/api/payments/{$this->first->id}", ['order_id' => 1, 'sequence' => 5, 'currency' => 'EUR', 'paid_at' => now()->toDateTimeString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['order_id', 'sequence', 'currency', 'paid_at']);
        $this->patchJson("/api/payments/{$this->first->id}", ['status' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });

    it('queues edits of a paid payment for a team lead and applies them directly for support', function () {
        $paid = Payment::factory()->paid()->create(['order_id' => $this->order->id, 'sequence' => 2, 'amount_cents' => 20000]);

        $this->actingAsUser($this->alpha->teamLead);
        $this->patchJson("/api/payments/{$paid->id}", ['notes' => 'x'])->assertAccepted();
        expect($paid->fresh()->notes)->toBeNull();

        $this->actingAsRole(RoleName::Support);
        $this->patchJson("/api/payments/{$paid->id}", ['notes' => 'Fixed typo'])->assertOk()->assertJsonPath('data.notes', 'Fixed typo');
        $this->patchJson("/api/payments/{$paid->id}", ['status' => 'scheduled'])->assertOk()->assertJsonPath('data.paid_at', null);
    });

    it('denies updates out of scope (403)', function () {
        $this->actingAsUser($this->bravo->teamLead);
        $this->patchJson("/api/payments/{$this->first->id}", ['notes' => 'x'])->assertForbidden();

        $this->actingAsUser($this->alpha->agent(1));
        $this->patchJson("/api/payments/{$this->first->id}", ['notes' => 'x'])->assertForbidden();
    });
});

describe('mark-paid', function () {
    it('records the payment directly for a team lead (200)', function () {
        $this->actingAsUser($this->alpha->teamLead);

        $this->postJson("/api/payments/{$this->first->id}/mark-paid", ['method' => 'stripe', 'reference' => 'TX-1234', 'paid_at' => '2026-10-01T09:30:00Z'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'paid')
            ->assertJsonPath('data.method.value', 'stripe')
            ->assertJsonPath('data.reference', 'TX-1234')
            ->assertJsonPath('data.paid_at', '2026-10-01T09:30:00Z')
            ->assertJsonPath('data.recorded_by_id', $this->alpha->teamLead->id)
            ->assertJsonPath('data.is_overdue', false);

        $this->getJson("/api/orders/{$this->order->id}")
            ->assertJsonPath('data.amount_paid.amount_cents', 40000)
            ->assertJsonPath('data.balance.amount_cents', 60000);
    });

    it('defaults paid_at to now', function () {
        $this->actingAsRole(RoleName::Support);

        $this->postJson("/api/payments/{$this->first->id}/mark-paid")->assertOk();
        expect($this->first->fresh()->paid_at)->not->toBeNull()->and($this->first->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('queues for a sales executive and applies when approved', function () {
        $this->actingAsUser($this->agent);

        $approval = $this->postJson("/api/payments/{$this->first->id}/mark-paid", ['reference' => 'TX-9', 'reason' => 'Screenshot received'])
            ->assertAccepted()
            ->assertJsonPath('data.payload.changes.status', 'paid')
            ->assertJsonPath('data.payload.changes.reference', 'TX-9')
            ->json('data.id');
        expect($this->first->fresh()->status)->toBe(PaymentStatus::Scheduled);

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$approval}/approve")->assertOk();

        $payment = $this->first->fresh();
        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->paid_at)->not->toBeNull()
            ->and($payment->recorded_by_id)->toBe($this->agent->id)
            ->and($this->order->fresh()->paidCents())->toBe(40000);
    });

    it('only accepts scheduled payments and valid input (422)', function () {
        $paid = Payment::factory()->paid()->create(['order_id' => $this->order->id, 'sequence' => 2, 'amount_cents' => 20000]);
        $this->actingAsRole(RoleName::Support);

        $this->postJson("/api/payments/{$paid->id}/mark-paid")->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->postJson("/api/payments/{$this->first->id}/mark-paid", ['paid_at' => now()->addDay()->toDateTimeString(), 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors(['paid_at', 'method']);

        $this->first->update(['status' => PaymentStatus::Void]);
        $this->postJson("/api/payments/{$this->first->id}/mark-paid")->assertUnprocessable();
    });

    it('denies marking payments out of scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));

        $this->postJson("/api/payments/{$this->first->id}/mark-paid")->assertForbidden();
    });
});

describe('overdue calculation', function () {
    it('is derived from the due date and status', function () {
        $overdue = Payment::factory()->overdue()->create(['order_id' => $this->order->id, 'sequence' => 2, 'amount_cents' => 10000]);
        $this->actingAsRole(RoleName::Support);

        $this->getJson("/api/payments/{$overdue->id}")->assertJsonPath('data.is_overdue', true);
        $this->getJson("/api/payments/{$this->first->id}")->assertJsonPath('data.is_overdue', false);
        $this->getJson("/api/orders/{$this->order->id}")->assertJsonPath('data.overdue_payments_count', 1);

        $this->postJson("/api/payments/{$overdue->id}/mark-paid")->assertOk()->assertJsonPath('data.is_overdue', false);
        $this->getJson("/api/orders/{$this->order->id}")->assertJsonPath('data.overdue_payments_count', 0);
    });
});

describe('destroy', function () {
    it('soft-deletes directly for support (204) and keeps the sequence reserved', function () {
        $this->actingAsRole(RoleName::Support);

        $this->deleteJson("/api/payments/{$this->first->id}")->assertNoContent();
        expect(Payment::query()->find($this->first->id))->toBeNull();

        $this->postJson("/api/orders/{$this->order->id}/payments", ['amount_cents' => 1000, 'due_date' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.sequence', 2);
    });

    it('queues the deletion for team lead and sales executive, applied on approval (202)', function (string $who) {
        $this->actingAsUser($who === 'team lead' ? $this->alpha->teamLead : $this->agent);

        $approval = $this->deleteJson("/api/payments/{$this->first->id}", ['reason' => 'Duplicate'])->assertAccepted()->json('data.id');
        expect($this->first->fresh())->not->toBeNull();

        $this->actingAsRole(RoleName::Support);
        $this->postJson("/api/approvals/{$approval}/approve")->assertOk();
        expect(Payment::query()->find($this->first->id))->toBeNull();
    })->with(['team lead', 'sales executive']);

    it('denies deletion out of scope (403)', function () {
        $this->actingAsUser($this->bravo->agent(0));

        $this->deleteJson("/api/payments/{$this->first->id}")->assertForbidden();
    });
});

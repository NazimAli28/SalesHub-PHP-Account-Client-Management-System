<?php

use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentDueReminder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

function reminderPayment(User $owner, string $due, PaymentStatus $status = PaymentStatus::Scheduled, int $sequence = 1, ?Order $order = null): Payment
{
    $order ??= Order::factory()->create([
        'owner_id' => $owner->id,
        'client_id' => Client::factory()->create(['name' => 'Rhea Reminder']),
    ]);

    return Payment::factory()->create([
        'order_id' => $order->id, 'sequence' => $sequence, 'due_date' => $due, 'status' => $status, 'amount_cents' => 15000,
    ]);
}

it('notifies the order owner about payments due within three days or overdue', function () {
    Notification::fake();
    $owner = User::factory()->salesExecutive()->create();
    $overdue = reminderPayment($owner, today()->subDays(4)->toDateString());
    $today = reminderPayment($owner, today()->toDateString());
    $edge = reminderPayment($owner, today()->addDays(3)->toDateString());
    reminderPayment($owner, today()->addDays(4)->toDateString());
    reminderPayment($owner, today()->addDay()->toDateString(), PaymentStatus::Paid);
    reminderPayment($owner, today()->addDay()->toDateString(), PaymentStatus::Void);

    $this->artisan('payments:send-reminders')->expectsOutput('Queued 3 payment reminder(s).')->assertSuccessful();

    Notification::assertSentToTimes($owner, PaymentDueReminder::class, 3);
    Notification::assertSentTo($owner, PaymentDueReminder::class, function (PaymentDueReminder $n) use ($overdue, $owner) {
        return $n->paymentId === $overdue->id
            && $n->isOverdue()
            && in_array('mail', $n->via($owner), true)
            && in_array('database', $n->via($owner), true);
    });
    Notification::assertSentTo($owner, PaymentDueReminder::class, fn (PaymentDueReminder $n) => $n->paymentId === $today->id && ! $n->isOverdue());
    Notification::assertSentTo($owner, PaymentDueReminder::class, fn (PaymentDueReminder $n) => $n->paymentId === $edge->id);
});

it('does not notify other users or inactive owners', function () {
    Notification::fake();
    $owner = User::factory()->salesExecutive()->create();
    $bystander = User::factory()->salesExecutive()->create();
    $inactive = User::factory()->salesExecutive()->create(['is_active' => false]);
    reminderPayment($owner, today()->toDateString());
    reminderPayment($inactive, today()->toDateString());

    $this->artisan('payments:send-reminders')->assertSuccessful();

    Notification::assertSentTo($owner, PaymentDueReminder::class);
    Notification::assertNotSentTo($bystander, PaymentDueReminder::class);
    Notification::assertNotSentTo($inactive, PaymentDueReminder::class);
});

it('is idempotent within a day', function () {
    $owner = User::factory()->salesExecutive()->create();
    $payment = reminderPayment($owner, today()->subDay()->toDateString());

    $this->artisan('payments:send-reminders')->expectsOutput('Queued 1 payment reminder(s).');
    $this->artisan('payments:send-reminders')->expectsOutput('Queued 0 payment reminder(s).');

    expect(DatabaseNotification::query()->where('type', PaymentDueReminder::class)->count())->toBe(1);

    // The next day the reminder is sent again.
    $this->travel(1)->day();
    $this->artisan('payments:send-reminders')->expectsOutput('Queued 1 payment reminder(s).');

    expect(DatabaseNotification::query()->count())->toBe(2)
        ->and(DatabaseNotification::query()->first()->data)->toMatchArray(['payment_id' => $payment->id, 'order_id' => $payment->order_id]);
});

it('writes a readable database payload and a markdown mail', function () {
    $owner = User::factory()->salesExecutive()->create(['name' => 'Olive Owner']);
    $payment = reminderPayment($owner, today()->addDays(2)->toDateString());

    $notification = PaymentDueReminder::forPayment($payment->load('order.client'));
    $data = $notification->toArray($owner);
    $mail = $notification->toMail($owner);

    expect($data['message'])->toContain('$150.00')->toContain('Rhea Reminder')->toContain($payment->order->order_number)
        ->and($data)->toMatchArray(['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'overdue' => false])
        ->and($mail->subject)->toContain($payment->order->order_number);

    $html = (string) $mail->render();
    expect($html)->toContain('Olive Owner')->toContain('SalesHub')->toContain('/orders/'.$payment->order_id);
});

it('is scheduled daily at 08:00', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'payments:send-reminders'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 8 * * *');
});

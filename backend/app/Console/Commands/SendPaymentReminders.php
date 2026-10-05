<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Notifications\PaymentDueReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

#[Signature('payments:send-reminders {--days=3 : Remind this many days ahead}')]
#[Description('Notify order owners about scheduled payments that are overdue or due soon (once per payment per day)')]
class SendPaymentReminders extends Command
{
    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));

        // Idempotency: payments that already have a reminder today (database rows are written when the queued job runs).
        $alreadyReminded = DatabaseNotification::query()
            ->where('type', PaymentDueReminder::class)
            ->where('created_at', '>=', today())
            ->pluck('data')
            ->map(fn (mixed $data): int => (int) (is_array($data) ? ($data['payment_id'] ?? 0) : (json_decode((string) $data, true)['payment_id'] ?? 0)))
            ->flip();

        $sent = 0;

        Payment::query()
            ->where('status', PaymentStatus::Scheduled->value)
            ->whereDate('due_date', '<=', today()->addDays($days)->toDateString())
            ->whereHas('order', fn ($q) => $q->whereHas('owner', fn ($u) => $u->where('is_active', true)))
            ->with(['order.owner', 'order.client'])
            ->orderBy('id')
            ->each(function (Payment $payment) use ($alreadyReminded, &$sent): void {
                if ($alreadyReminded->has($payment->id)) {
                    return;
                }

                $payment->order?->owner?->notify(PaymentDueReminder::forPayment($payment));
                $sent++;
            });

        $this->info("Queued {$sent} payment reminder(s).");

        return self::SUCCESS;
    }
}

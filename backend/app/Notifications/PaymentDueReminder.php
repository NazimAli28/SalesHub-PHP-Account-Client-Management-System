<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an order's owner for a scheduled payment that is due within three days or overdue.
 * Queued; delivered to the notifications bell (database) and by email.
 */
class PaymentDueReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly string $clientName,
        public readonly string $dueDate,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly int $daysUntilDue,
    ) {}

    public static function forPayment(Payment $payment): self
    {
        $order = $payment->order;
        $client = $order->client;

        return new self(
            paymentId: $payment->id,
            orderId: $order->id,
            orderNumber: $order->order_number,
            clientName: $client->name ?: $client->discord_username,
            dueDate: $payment->due_date->toDateString(),
            amountCents: $payment->amount_cents,
            currency: $payment->currency,
            daysUntilDue: (int) today()->diffInDays($payment->due_date, false),
        );
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->email === '' ? ['database'] : ['database', 'mail'];
    }

    public function isOverdue(): bool
    {
        return $this->daysUntilDue < 0;
    }

    public function message(): string
    {
        $amount = Money::format($this->amountCents, $this->currency);
        $who = "{$amount} from {$this->clientName} (order {$this->orderNumber})";

        return match (true) {
            $this->daysUntilDue < 0 => "Payment of {$who} was due on {$this->dueDate} and is overdue.",
            $this->daysUntilDue === 0 => "Payment of {$who} is due today.",
            default => "Payment of {$who} is due on {$this->dueDate}.",
        };
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->isOverdue() ? "Overdue payment for order {$this->orderNumber}" : "Payment due soon for order {$this->orderNumber}")
            ->markdown('mail.payment-due-reminder', [
                'name' => $notifiable->name,
                'message' => $this->message(),
                'orderNumber' => $this->orderNumber,
                'clientName' => $this->clientName,
                'amount' => Money::format($this->amountCents, $this->currency),
                'dueDate' => $this->dueDate,
                'overdue' => $this->isOverdue(),
                'url' => rtrim((string) config('cors.allowed_origins.0', 'http://localhost:5173'), '/').'/orders/'.$this->orderId,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'due_date' => $this->dueDate,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'overdue' => $this->isOverdue(),
            'message' => $this->message(),
        ];
    }
}

<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum LeadStage: string
{
    case New = 'new';
    case Engaged = 'engaged';
    case PortfolioShared = 'portfolio_shared';
    case Quoted = 'quoted';
    case PaymentPending = 'payment_pending';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return Str::headline($this->name);
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Won, self::Lost => false,
            default => true,
        };
    }

    /**
     * Cases in pipeline order (Kanban columns).
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }
}

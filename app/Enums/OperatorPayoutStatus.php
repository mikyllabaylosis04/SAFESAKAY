<?php

namespace App\Enums;

enum OperatorPayoutStatus: string
{
    case OnHold = 'on_hold';
    case ReadyForPayout = 'ready_for_payout';
    case PaidOut = 'paid_out';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::OnHold => 'On Hold',
            self::ReadyForPayout => 'Ready for Payout',
            self::PaidOut => 'Paid Out',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::OnHold => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
            self::ReadyForPayout => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100',
            self::PaidOut => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
            self::Cancelled => 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200',
        };
    }
}

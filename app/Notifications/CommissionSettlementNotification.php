<?php

namespace App\Notifications;

use App\Models\RentalCommission;
use Illuminate\Notifications\Notification;

class CommissionSettlementNotification extends Notification
{
    public function __construct(
        public RentalCommission $commission,
        public string $event,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $commission = $this->commission->loadMissing(['booking.boat', 'operator']);
        $code = $commission->booking?->code ?? 'Rental';
        $amount = '₱'.number_format((float) $commission->commission_amount, 2);
        $due = $commission->settlement_due_at?->format('M d, Y') ?? 'the due date';

        [$title, $body] = match ($this->event) {
            'reminder_day_1' => [
                'Commission settlement reminder',
                "Please remit {$amount} commission for booking {$code}. Settlement is due on {$due}.",
            ],
            'reminder_day_2' => [
                'Commission settlement due soon',
                "Second reminder: commission of {$amount} for {$code} is due on {$due}. Pay via GCash or cash at the barangay office.",
            ],
            'overdue_operator' => [
                'Commission settlement overdue',
                "Commission of {$amount} for {$code} is now overdue. Submit GCash proof or pay cash to the barangay admin.",
            ],
            'overdue_admin' => [
                'Overdue operator commission',
                "Operator commission for {$code} ({$amount}) is overdue and still unsettled.",
            ],
            'admin_follow_up' => [
                'Commission follow-up notice',
                "The barangay admin sent a follow-up about the overdue commission ({$amount}) for booking {$code}.",
            ],
            'cash_recorded' => [
                'Cash commission recorded',
                "Your cash commission payment of {$amount} for {$code} was recorded by the barangay admin.",
            ],
            'gcash_verified' => [
                'Commission settlement verified',
                "Your GCash commission payment of {$amount} for {$code} was verified.",
            ],
            'gcash_rejected' => [
                'Commission settlement rejected',
                "Your GCash commission submission for {$code} was rejected. Please resubmit or pay cash.",
            ],
            default => [
                'Commission update',
                "There is an update for commission settlement on booking {$code}.",
            ],
        };

        return [
            'kind' => 'commission',
            'event' => $this->event,
            'commission_id' => $commission->id,
            'booking_id' => $commission->boat_booking_id,
            'title' => $title,
            'body' => $body,
        ];
    }
}

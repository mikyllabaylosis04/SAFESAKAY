<?php

namespace App\Notifications;

use App\Models\BoatBooking;
use Illuminate\Notifications\Notification;

class PaymentUpdateNotification extends Notification
{
    public function __construct(
        public BoatBooking $booking,
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
        $booking = $this->booking->loadMissing('boat');
        $boatName = $booking->boat?->name ?? 'your boat';
        $boatCode = $booking->boat?->code ?: 'Boat';
        $date = $booking->booking_date->format('M d, Y');
        $passenger = $booking->customer_name ?: 'A passenger';
        $reason = trim((string) $booking->payment_rejection_reason) ?: 'the submitted proof could not be verified';
        $balance = $booking->remaining_balance !== null
            ? '₱'.number_format((float) $booking->remaining_balance, 2)
            : 'the remaining balance';

        [$title, $body] = match ($this->event) {
            'payment_submitted' => [
                'Payment proof submitted',
                $booking->paysBarangayGcash()
                    ? "{$passenger} uploaded a GCash down payment receipt for {$boatName} ({$boatCode}) on {$date}. Verify it against barangay admin GCash in the admin portal."
                    : "{$passenger} uploaded a GCash down payment receipt for {$boatName} ({$boatCode}) on {$date}. Please verify it in your GCash history.",
            ],
            'payment_submitted_operator' => [
                'Down payment submitted',
                "{$passenger} uploaded a GCash down payment receipt for {$boatName} ({$boatCode}) on {$date}. Waiting for barangay admin to verify the down payment.",
            ],
            'payment_rejected' => [
                'Payment proof rejected',
                "Your down payment proof for {$boatName} ({$boatCode}) on {$date} was rejected: {$reason}. You can resubmit a new receipt.",
            ],
            'balance_paid' => [
                'Remaining balance recorded',
                $booking->paysBarangayGcash()
                    ? "The remaining balance of {$balance} for {$boatName} ({$boatCode}) on {$date} was recorded by barangay admin against barangay GCash."
                    : "The remaining balance of {$balance} for {$boatName} ({$boatCode}) on {$date} was recorded by your boat operator.",
            ],
            default => [
                'Rental payment update',
                "There is a payment update for {$boatName} ({$boatCode}) on {$date}.",
            ],
        };

        return [
            'kind' => 'booking',
            'event' => $this->event,
            'booking_id' => $booking->id,
            'title' => $title,
            'body' => $body,
        ];
    }
}

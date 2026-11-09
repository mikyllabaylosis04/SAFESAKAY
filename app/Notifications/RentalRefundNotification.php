<?php

namespace App\Notifications;

use App\Models\BoatBooking;
use App\Models\RentalRefundRequest;
use App\Models\User;
use Illuminate\Notifications\Notification;

class RentalRefundNotification extends Notification
{
    public function __construct(
        public BoatBooking $booking,
        public RentalRefundRequest $refundRequest,
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
        $date = $booking->booking_date->format('M d, Y');
        $passenger = $booking->customer_name ?: 'A passenger';
        $amount = $this->refundRequest->refundAmountLabel() ?? 'your payment';
        $forPassenger = $notifiable instanceof User && (int) $notifiable->id === (int) $booking->user_id;
        $who = $this->refundRequest->type === RentalRefundRequest::TYPE_ADMIN_CANCELLED ? 'The barangay admin' : 'The operator';

        [$title, $body] = $forPassenger
            ? match ($this->event) {
                'choose' => [
                    'Choose a refund or a new date',
                    "{$who} cancelled {$boatName} on {$date}. You will not lose your payment of {$amount}. Open the rental to get a full refund or reschedule. Booking {$booking->code}.",
                ],
                'refunded' => [
                    'Refund completed',
                    "{$amount} was refunded to your GCash for {$boatName} on {$date}. Booking {$booking->code}.",
                ],
                'rescheduled' => [
                    'New date requested',
                    "Your new date for {$boatName} is waiting for approval. Booking {$booking->code}.",
                ],
                'rejected' => [
                    'Refund request not approved',
                    "Your refund request for {$booking->code} was not approved. Reason: ".($this->refundRequest->rejection_reason ?: 'see the rental details').'. Your payment records are kept.',
                ],
                default => [
                    'Refund update',
                    "There is an update on your refund for {$boatName} on {$date}.",
                ],
            }
            : match ($this->event) {
                'requested' => [
                    'New refund request',
                    "{$passenger} cancelled {$boatName} on {$date} and asked for a refund. Review it in Payments → Refund Requests. Booking {$booking->code}.",
                ],
                'failed' => [
                    'Refund needs your action',
                    "The GCash refund of {$amount} for {$booking->code} could not be sent automatically. Open the rental to retry or record a manual refund.",
                ],
                'rescheduled' => [
                    'Reschedule request to review',
                    "{$passenger} chose a new date instead of a refund for {$boatName}. Review it in Rental Requests. Booking {$booking->code}.",
                ],
                default => [
                    'Refund update',
                    "Refund update for {$booking->code}: {$this->refundRequest->statusLabel()}.",
                ],
            };

        return [
            'kind' => 'booking',
            'event' => 'refund_'.$this->event,
            'booking_id' => $booking->id,
            'title' => $title,
            'body' => $body,
        ];
    }
}

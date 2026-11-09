<?php

namespace App\Notifications;

use App\Models\BoatBooking;
use Illuminate\Notifications\Notification;

class OperatorNoShowReportedNotification extends Notification
{
    public function __construct(public BoatBooking $booking) {}

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
        $boatName = $booking->boat?->name ?? 'the assigned boat';
        $boatCode = $booking->boat?->code ?: 'Boat';
        $date = $booking->booking_date->format('M d, Y');
        $passenger = $booking->customer_name ?: 'A passenger';

        return [
            'kind' => 'booking',
            'event' => 'operator_no_show_reported',
            'booking_id' => $booking->id,
            'title' => 'Operator no-show reported',
            'body' => "{$passenger} reported that {$boatName} ({$boatCode}) did not arrive on {$date}. Barangay admin will review this report.",
        ];
    }
}

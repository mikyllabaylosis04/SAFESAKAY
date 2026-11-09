<?php

namespace App\Notifications;

use App\Models\BoatBooking;
use Illuminate\Notifications\Notification;

class PassengerNoShowNotification extends Notification
{
    /**
     * @param  'disputed_admin'|'upheld_passenger'|'upheld_operator'|'reversed_passenger'|'reversed_operator'  $event
     */
    public function __construct(
        public BoatBooking $booking,
        public string $event,
        public ?string $detail = null,
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
        $code = $this->booking->code ?? ('#'.$this->booking->id);
        $date = $this->booking->booking_date?->format('M d, Y');
        $note = filled($this->detail) ? ' Note: '.$this->detail : '';

        [$title, $body] = match ($this->event) {
            'disputed_admin' => [
                'Passenger disputed a no-show',
                "{$this->booking->customer_name} disputed the passenger no-show for booking {$code} on {$date}. Review it in Rental Requests.".$note,
            ],
            'upheld_passenger' => [
                'No-show review completed',
                "The barangay reviewed your dispute for booking {$code} and kept the passenger no-show. Payments stay non-refundable under the no-show policy.".$note,
            ],
            'upheld_operator' => [
                'Passenger no-show upheld',
                "The barangay reviewed the passenger dispute for booking {$code} and kept your no-show report.",
            ],
            'reversed_passenger' => [
                'No-show report corrected',
                "The barangay corrected the passenger no-show for booking {$code}. Open the booking to choose a refund or a new date.".$note,
            ],
            'reversed_operator' => [
                'Passenger no-show reversed',
                "The barangay reversed the passenger no-show for booking {$code} on {$date}.".$note,
            ],
            default => [
                'Passenger no-show update',
                "Booking {$code} was updated.",
            ],
        };

        return [
            'kind' => 'booking',
            'event' => 'passenger_no_show_'.$this->event,
            'booking_id' => $this->booking->id,
            'title' => $title,
            'body' => $body,
        ];
    }
}

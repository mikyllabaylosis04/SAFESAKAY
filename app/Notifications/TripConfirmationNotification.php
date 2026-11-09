<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Notifications\Notification;

class TripConfirmationNotification extends Notification
{
    /**
     * @param  'trip_confirmed'|'replacement_approved'|'no_replacement'|'cannot_operate_admin'|'passenger_no_show'  $event
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
        $booking = $this->booking->loadMissing('boat');
        $boatName = $booking->boat?->name ?? 'your boat';
        $date = $booking->booking_date->format('M d, Y');
        $destination = $booking->destination ?: $booking->package ?: $boatName;
        $code = $booking->code ?? ('#'.$booking->id);
        $forOperator = $notifiable instanceof User && $notifiable->isMobileOperator();

        $forAdmin = $notifiable instanceof User
            && ! $forOperator
            && in_array($notifiable->role, [UserRole::Administrator, UserRole::User], true)
            && (int) $notifiable->organization_id === (int) $booking->organization_id;

        [$title, $body] = $forAdmin
            ? match ($this->event) {
                'cannot_operate_admin' => [
                    'Operator cannot operate trip',
                    "Booking {$code} on {$date} ({$boatName}) was marked as cannot operate. {$this->detail}",
                ],
                'replacement_pending' => [
                    'Replacement operator accepted',
                    "Review and approve the replacement assignment for booking {$code} on {$date}.",
                ],
                'passenger_no_show' => [
                    'Passenger no-show recorded',
                    "Operator recorded a passenger no-show for booking {$code} on {$date}. {$this->detail}",
                ],
                default => [
                    'Trip update',
                    "Booking {$code} on {$date} was updated.",
                ],
            }
            : match ($this->event) {
                'confirmation_request_operator' => [
                    'Confirm your trip today',
                    "You have a scheduled rental on {$date} at ".($booking->start_time ? \Carbon\Carbon::parse($booking->start_time)->format('g:i A') : 'the scheduled time')." to {$destination} ({$boatName}). Open SafeSakay and confirm your availability or tap Cannot Operate if you cannot proceed.",
                ],
                'trip_confirmed' => [
                    'Your scheduled trip is confirmed!',
                    'Your scheduled trip is confirmed! Your assigned operator has confirmed availability. Please be ready and proceed to your designated meeting point at the scheduled departure time.',
                ],
                'replacement_approved' => [
                    'Your assigned boat has been changed',
                    'Your assigned boat has been changed because your original operator is unavailable. Your replacement boat and operator have been confirmed. Please check your updated booking details and proceed to your designated meeting point.',
                ],
                'no_replacement' => [
                    'No replacement available for your trip',
                    'Your original operator cannot operate your scheduled trip and no replacement is currently available. Please contact the barangay admin to reschedule or request a full refund according to the booking policy.',
                ],
                'passenger_no_show' => [
                    'Rental Cancelled — Passenger No-Show',
                    'Our records indicate that you did not arrive at the agreed pickup location within the required waiting period. Your exclusive boat rental has been cancelled and your payment is non-refundable under the accepted no-show policy. If this report is incorrect, open the rental in SafeSakay and dispute it within 1 day.',
                ],
                default => [
                    'Trip update',
                    "Your rental {$code} on {$date} was updated.",
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

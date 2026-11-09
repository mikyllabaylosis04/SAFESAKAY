<?php

namespace App\Notifications;

use App\Enums\BoatBookingStatus;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Notifications\Notification;

class BookingStatusNotification extends Notification
{
    public function __construct(public BoatBooking $booking) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public static function eventFor(BoatBooking $booking): string
    {
        return match ($booking->status) {
            BoatBookingStatus::Pending => 'pending',
            BoatBookingStatus::AwaitingPayment => 'awaiting_payment',
            BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing => 'approved',
            BoatBookingStatus::WeatherHold => 'weather_hold',
            BoatBookingStatus::ReschedulePending => 'reschedule_requested',
            BoatBookingStatus::Completed => 'completed',
            BoatBookingStatus::PassengerNoShow => 'passenger_no_show',
            BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected => 'cancelled',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $booking = $this->booking->loadMissing('boat');
        $event = self::eventFor($booking);
        $boatName = $booking->boat?->name ?? 'your boat';
        $boatCode = $booking->boat?->code ?: 'Boat';
        $date = $booking->booking_date->format('M d, Y');
        $destination = $booking->destination ?: $booking->package ?: $boatName;
        $passenger = $booking->customer_name ?: 'A passenger';
        $forOperator = $notifiable instanceof User && $notifiable->isMobileOperator();

        [$title, $body] = $forOperator
            ? match ($event) {
                'pending' => [
                    'New rental request',
                    "{$passenger} requested {$boatName} ({$boatCode}) to {$destination} on {$date}.",
                ],
                'awaiting_payment' => [
                    'Waiting for passenger payment',
                    "You accepted {$passenger}'s rental of {$boatName} ({$boatCode}). The passenger must pay the down payment to barangay admin GCash and upload proof in SafeSakay.",
                ],
                'approved' => [
                    'Trip confirmed',
                    "{$passenger} is booked on {$boatName} ({$boatCode}) to {$destination} on {$date}.",
                ],
                'cancelled' => [
                    'Request cancelled',
                    "The rental for {$boatName} on {$date} was cancelled.",
                ],
                'weather_hold' => [
                    'Rental on Weather Hold',
                    "{$passenger}'s rental of {$boatName} on {$date} is on weather hold. Downpayment is unchanged. The passenger must pick a new date.",
                ],
                'reschedule_requested' => [
                    'Reschedule request',
                    "{$passenger} requested a new date for {$boatName} ({$boatCode}). Review boat availability before approving.",
                ],
                'passenger_no_show' => [
                    'Passenger did not show',
                    "{$passenger} did not appear for {$boatName} ({$boatCode}) on {$date}. Down payment is not refunded.",
                ],
                default => [
                    'Trip completed',
                    "{$boatName} finished the trip to {$destination} on {$date}.",
                ],
            }
            : match ($event) {
                'pending' => [
                    'Your rental is waiting for the boat operator',
                    "Your rental request for {$boatName} ({$boatCode}) on {$date} is waiting for the boat operator to accept it.",
                ],
                'awaiting_payment' => [
                    'Pay your down payment',
                    $booking->paysBarangayGcash()
                        ? "The boat operator accepted your rental of {$boatName} ({$boatCode}) on {$date}. Pay the down payment to the barangay admin GCash, then submit your proof."
                        : "The boat operator accepted your rental of {$boatName} ({$boatCode}) on {$date}. Please submit your GCash down payment proof.",
                ],
                'approved' => [
                    'Rental Confirmed — Your down payment has been verified.',
                    $booking->paysBarangayGcash()
                        ? "Your rental of {$boatName} ({$boatCode}) on {$date} is confirmed. Pay the remaining balance to the barangay admin GCash before departure."
                        : "Your rental of {$boatName} ({$boatCode}) on {$date} is confirmed. Pay the remaining balance directly to the boat operator before departure.",
                ],
                'cancelled' => [
                    'Your rental was cancelled',
                    "Your rental request for {$boatName} on {$date} was not approved.",
                ],
                'weather_hold' => [
                    'Your rental is on Weather Hold',
                    "Your SafeSakay boat rental has been postponed due to bad weather or rough sea conditions. Your existing verified downpayment remains recorded. Please select another available date to reschedule your rental. Booking {$booking->code}.",
                ],
                'reschedule_requested' => [
                    'Reschedule request received',
                    "Your new date for {$boatName} is waiting for Admin or Operator review. Booking {$booking->code}.",
                ],
                'passenger_no_show' => [
                    'Marked as passenger no-show',
                    "The operator marked that you did not arrive for {$boatName} on {$date}. Your down payment is not refunded. Booking {$booking->code}.",
                ],
                default => [
                    'Your trip has been completed',
                    "Thank you for riding {$boatName} to {$destination} on {$date}.",
                ],
            };

        return [
            'kind' => 'booking',
            'event' => $event,
            'booking_id' => $booking->id,
            'title' => $title,
            'body' => $body,
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\BoatBooking;
use Illuminate\Notifications\Notification;

class OperatorNoShowDecisionNotification extends Notification
{
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
        $code = $booking->code;
        $amount = $booking->down_payment_amount_label
            ?? ($booking->down_payment_amount !== null
                ? '₱'.number_format((float) $booking->down_payment_amount, 2)
                : 'your down payment');

        $time = $booking->start_time ? \Illuminate\Support\Carbon::parse($booking->start_time)->format('g:i A') : 'the scheduled time';
        $destination = $booking->destination ?: $booking->package ?: 'the destination';

        [$title, $body] = match ($this->event) {
            'operator_no_show_refund_approved' => [
                'Refund approved',
                "Barangay approved your refund for booking {$code} because the boat did not arrive on {$date}. You will be notified once the refund is completed.".($this->detail ? " Reference: {$this->detail}." : ''),
            ],
            'operator_no_show_delayed' => [
                'Your boat is delayed',
                "Barangay confirmed {$boatName} is delayed but still coming for booking {$code}. Your booking stays as scheduled.".($this->detail ? " Note: {$this->detail}" : ''),
            ],
            'operator_no_show_replacement_request' => [
                'Replacement trip request',
                "Barangay asks you to cover booking {$code} on {$date} at {$time} to {$destination}. Open the booking to accept or decline.",
            ],
            'operator_no_show_replacement_offered' => [
                'Replacement boat available',
                "Your original boat or operator is unavailable. An alternative service has been arranged. Please choose how you would like to proceed. Booking {$code}.".($this->detail ? " Replacement boat: {$this->detail}." : ''),
            ],
            'operator_no_show_replacement_accepted' => [
                'Replacement operator accepted',
                "The replacement operator accepted booking {$code} on {$date}. Waiting for the passenger to accept.".($this->detail ? " Boat: {$this->detail}." : ''),
            ],
            'operator_no_show_replacement_declined' => [
                'Replacement operator declined',
                "The replacement operator declined booking {$code} on {$date}. Choose another boat or mark no replacement available.".($this->detail ? " Reason: {$this->detail}." : ''),
            ],
            'operator_no_show_replacement_confirmed' => [
                'Replacement confirmed',
                "The passenger accepted the replacement for booking {$code} on {$date} at {$time}.".($this->detail ? " Boat: {$this->detail}." : ''),
            ],
            'operator_no_show_no_replacement' => [
                'No replacement available',
                "No replacement boat is available for booking {$code}. Open the booking to request a refund or pick a new date.",
            ],
            'operator_no_show_reschedule_offered' => [
                'Keep your down payment — pick a new date',
                "The operator did not arrive for {$boatName} on {$date}. Your down payment stays. Barangay offered a new trip date at no extra charge. Booking {$code}.",
            ],
            'operator_no_show_reassigned' => [
                'Another boat was assigned',
                "A replacement boat will handle your trip on {$date}. Your down payment stays. Booking {$code}.".($this->detail ? " New boat: {$this->detail}." : ''),
            ],
            'operator_no_show_verified_full_refund' => [
                'No-show verified — full refund',
                "Barangay confirmed the boat did not arrive for booking {$code} on {$date}. You will receive a full refund".($this->detail ? " of {$this->detail}" : '').'. We will notify you once it is sent.',
            ],
            'operator_no_show_verified_choose_resolution' => [
                'Operator no-show verified',
                "Barangay confirmed {$boatName} did not arrive on {$date}. Open booking {$code} to request a refund or pick a new date. Barangay may also arrange a replacement boat.",
            ],
            'operator_no_show_passenger_chose_refund' => [
                'Refund requested',
                "We received your full refund request for booking {$code}. Barangay will review your verified down payment ({$amount}) and process GCash when approved.",
            ],
            'operator_no_show_passenger_chose_reschedule' => [
                'Reschedule requested',
                "We received your reschedule request for booking {$code}. Barangay will coordinate a new date with the operator. Your down payment stays on the booking.",
            ],
            'operator_no_show_passenger_chose_replacement' => [
                'Replacement boat requested',
                "We received your replacement boat request for booking {$code}. Barangay will find an available boat for the same schedule. Your down payment stays on the booking.",
            ],
            'operator_no_show_refund_completed' => [
                'Refund completed',
                "Your full refund for booking {$code} was sent to your GCash.".($this->detail ? " Reference: {$this->detail}." : ''),
            ],
            'operator_no_show_report_rejected' => [
                'No-show report rejected',
                "Barangay rejected your operator no-show report for booking {$code}.".($this->detail ? " Reason: {$this->detail}." : ''),
            ],
            'operator_no_show_reschedule_approved' => [
                'Booking rescheduled',
                "Your operator no-show reschedule for booking {$code} is confirmed. Your down payment stays on the booking.",
            ],
            'operator_no_show_reschedule_rejected' => [
                'Reschedule declined',
                "The operator could not confirm your requested schedule for booking {$code}. Choose another schedule or request a refund.",
            ],
            'operator_no_show_reschedule_expired' => [
                'Reschedule request timed out',
                "The replacement operator did not confirm booking {$code} within 30 minutes. Your down payment stays — pick another boat and schedule or request a refund.",
            ],
            'operator_no_show_reschedule_needs_confirmation' => [
                'Approve no-show reschedule',
                $this->noShowRescheduleConfirmationBody($booking),
            ],
            'operator_no_show_violation_recorded' => [
                'Operator no-show violation',
                "A confirmed operator no-show was recorded for {$boatName} on {$date}. Booking {$code}.".($this->detail ? " {$this->detail}" : ''),
            ],
            'operator_no_show_rental_suspended' => [
                'Exclusive rentals suspended',
                "Your exclusive boat rental privileges are suspended after 3 confirmed no-shows. Contact barangay management. Booking {$code}.".($this->detail ? " {$this->detail}" : ''),
            ],
            default => [
                'Operator no-show update',
                "Barangay updated your operator no-show report for booking {$code}.",
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

    private function noShowRescheduleConfirmationBody(BoatBooking $booking): string
    {
        $booking->loadMissing(['operatorNoShowReports.rescheduleBoat']);
        $report = $booking->operatorNoShowReports()->latest('id')->first();
        $targetBoat = $report?->rescheduleBoat?->name ?? 'your boat';
        $when = collect([
            $report?->preferred_booking_date?->format('M d, Y'),
            $report?->preferred_start_time
                ? \Illuminate\Support\Carbon::parse($report->preferred_start_time)->format('g:i A')
                : null,
        ])->filter()->implode(' · ');
        $passenger = $booking->customer_name ?: 'A passenger';
        $code = $booking->code;

        return $when
            ? "{$passenger} chose {$targetBoat} for {$when} after a no-show on another boat. Open Rental Requests → Reschedule and approve or decline within 30 minutes. Booking {$code}."
            : "{$passenger} chose {$targetBoat} for a new schedule after a no-show. Open Rental Requests → Reschedule to approve or decline. Booking {$code}.";
    }
}

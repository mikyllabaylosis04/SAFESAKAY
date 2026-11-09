<?php

namespace App\Support;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Models\BoatBooking;
use App\Models\OrganizationRoute;

class NoShowAdminPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function passengerNoShowRecord(BoatBooking $booking): array
    {
        $booking->loadMissing(['boat.operator', 'boat.pictures', 'passengerNoShowAdminAcknowledgedBy']);

        $paymentVerified = $booking->paymentStatus() === BookingPaymentStatus::Verified;
        $operatorNote = self::extractPassengerNoShowNote($booking->remarks);
        $acknowledgedAt = $booking->passenger_no_show_admin_acknowledged_at;
        $isOpen = $acknowledgedAt === null;

        return [
            'id' => $booking->id,
            'incident_kind' => 'passenger_no_show',
            'incident_type_label' => 'Passenger did not show',
            'sort_at' => ($acknowledgedAt ?? $booking->updated_at)?->getTimestamp() ?? 0,
            'status' => BoatBookingStatus::PassengerNoShow->value,
            'status_label' => BoatBookingStatus::PassengerNoShow->label(),
            'incident_status' => $isOpen ? 'passenger_no_show' : 'reviewed',
            'incident_status_label' => $isOpen ? 'Passenger Did Not Show' : 'Reviewed',
            'incident_status_badge_class' => $isOpen
                ? 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200'
                : 'bg-slate-100 text-slate-800 dark:bg-slate-500/20 dark:text-slate-200',
            'is_open' => $isOpen,
            'resolution' => null,
            'resolution_label' => null,
            'refund_status' => null,
            'refund_status_label' => null,
            'refund_reference' => null,
            'refund_amount_label' => $booking->down_payment_amount !== null
                ? OrganizationRoute::formatPeso($booking->down_payment_amount)
                : null,
            'report_note' => $operatorNote,
            'admin_notes' => null,
            'reported_at_label' => $booking->updated_at?->format('M d, Y · g:i A'),
            'reviewed_at_label' => $acknowledgedAt?->format('M d, Y · g:i A'),
            'resolved_at_label' => null,
            'reporter_name' => $booking->boat?->operator?->full_name,
            'reviewer_name' => $booking->passengerNoShowAdminAcknowledgedBy?->name,
            'replacement_boat_name' => null,
            'passenger_choice' => null,
            'passenger_choice_label' => null,
            'passenger_choice_at_label' => null,
            'passenger_choice_note' => null,
            'preferred_booking_date_label' => null,
            'preferred_start_time_label' => null,
            'reschedule_status' => null,
            'reschedule_status_label' => null,
            'reschedule_boat_name' => null,
            'can_verify' => false,
            'can_acknowledge' => $isOpen,
            'can_process_refund' => false,
            'can_process_reschedule' => false,
            'can_process_replacement' => false,
            'can_complete_refund' => false,
            'completion_message' => $isOpen
                ? 'Recorded by the operator on the trip date. The downpayment is not refunded to the passenger. Unpaid operator commission is waived per policy.'
                : 'Admin reviewed this passenger no-show. Downpayment and commission policy unchanged.',
            'booking' => self::bookingPayload($booking, $paymentVerified),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bookingPayload(BoatBooking $booking, bool $paymentVerified): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'customer_name' => $booking->customer_name,
            'contact_number' => $booking->contact_number,
            'destination' => $booking->destination ?: $booking->package,
            'pickup_point' => $booking->pickup_point,
            'booking_date_label' => $booking->booking_date?->format('M d, Y'),
            'booking_weekday_label' => $booking->booking_date?->format('D'),
            'start_time_label' => $booking->start_time
                ? \Illuminate\Support\Carbon::parse($booking->start_time)->format('g:i A')
                : null,
            'boat_name' => $booking->boat?->name,
            'boat_code' => $booking->boat?->code,
            'boat_picture_url' => $booking->boat?->pictureUrl(),
            'operator_name' => $booking->boat?->operator?->full_name,
            'operator_contact_number' => $booking->boat?->operator?->contact_number,
            'passenger_count' => $booking->passenger_count,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'rental_price_label' => $booking->rental_price !== null
                ? OrganizationRoute::formatPeso($booking->rental_price)
                : null,
            'down_payment_amount_label' => $booking->down_payment_amount !== null
                ? OrganizationRoute::formatPeso($booking->down_payment_amount)
                : null,
            'payment_status_label' => $paymentVerified ? 'Down payment verified' : $booking->paymentStatus()->label(),
            'down_payment_paid' => $paymentVerified,
        ];
    }

    private static function extractPassengerNoShowNote(?string $remarks): ?string
    {
        if (! filled($remarks)) {
            return null;
        }

        if (preg_match('/Passenger no-show:\s*(.+)/is', $remarks, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}

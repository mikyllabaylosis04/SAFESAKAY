<?php

namespace App\Support;

use App\Enums\BoatBookingStatus;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\OperatorNoShowRefundStatus;
use App\Models\BoatBooking;
use App\Models\OperatorNoShowReport;
use App\Models\OperatorNoShowViolation as OperatorNoShowViolationModel;
use App\Models\User;
use App\Services\OperatorNoShowService;

class RentalIncidentPresenter
{
    /**
     * PRD-aligned passenger incident block for rental details (mobile My Rentals).
     *
     * @return array<string, mixed>|null
     */
    public static function forBooking(BoatBooking $booking, ?User $viewer = null): ?array
    {
        $booking->loadMissing(['boat.operator', 'operatorNoShowReports.reviewer', 'passengerNoShowAdminAcknowledgedBy']);

        $operatorReport = $booking->operatorNoShowReports()->latest('id')->first();
        if ($operatorReport instanceof OperatorNoShowReport) {
            return self::operatorNoShowIncident($booking, $operatorReport, $viewer);
        }

        if ($booking->status === BoatBookingStatus::PassengerNoShow) {
            return self::passengerNoShowIncident($booking);
        }

        if ($booking->status->isWeatherPaused()) {
            return self::weatherIncident($booking);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function listSummary(BoatBooking $booking): ?array
    {
        $full = self::forBooking($booking);

        if ($full === null) {
            return null;
        }

        return [
            'incident_type' => $full['incident_type'],
            'incident_type_label' => $full['incident_type_label'],
            'report_status' => $full['report_status'],
            'report_status_label' => $full['report_status_label'],
            'has_open_incident' => (bool) ($full['has_open_incident'] ?? false),
        ];
    }

    /**
     * Structured cancellation / closure copy for My Rentals list and detail screens.
     *
     * @return array<string, mixed>|null
     */
    public static function cancellationDisplay(BoatBooking $booking): ?array
    {
        $booking->loadMissing(['passengerNoShowAdminAcknowledgedBy']);

        if ($booking->status === BoatBookingStatus::PassengerNoShow) {
            $acknowledged = $booking->passenger_no_show_admin_acknowledged_at !== null;

            return [
                'kind' => 'passenger_no_show',
                'reason_label' => 'Passenger No-Show',
                'verification_label' => $acknowledged ? 'Verified by Admin' : 'Recorded by Operator',
                'payment_status_label' => 'Non-refundable',
                'card_message' => 'Passenger no-show. Down payment is non-refundable.',
                'list_message' => $acknowledged
                    ? 'Cancellation reason: Passenger No-Show · Verified by Admin · Payment: Non-refundable'
                    : 'Cancellation reason: Passenger No-Show · Pending admin review · Payment: Non-refundable',
                'detail_message' => 'Marked as passenger no-show. Your down payment is not refunded.',
                'use_weather_message' => false,
            ];
        }

        if ($booking->status->isWeatherPaused()) {
            return [
                'kind' => 'weather_hold',
                'reason_label' => 'Weather Suspension',
                'verification_label' => 'Declared by Admin',
                'payment_status_label' => 'Down payment retained',
                'card_message' => 'Your trip is cancelled due to weather. Reschedule to another date.',
                'list_message' => 'Your trip is cancelled due to weather. Reschedule to another date.',
                'detail_message' => 'Your rental is on weather hold. Pick a new date or request a refund if needed.',
                'use_weather_message' => true,
            ];
        }

        $operatorReport = $booking->operatorNoShowReports()->latest('id')->first();
        if ($operatorReport instanceof OperatorNoShowReport
            && $operatorReport->status === OperatorNoShowReportStatus::Verified
            && filled($booking->cancellation_reason)
            && strcasecmp(trim($booking->cancellation_reason), OperatorNoShowService::CANCELLATION_REASON) === 0) {
            $refunded = $operatorReport->refund_status === OperatorNoShowRefundStatus::Refunded;
            $verifiedLabel = 'Verified – Operator No-Show';

            return [
                'kind' => 'operator_no_show',
                'reason_label' => OperatorNoShowService::CANCELLATION_REASON,
                'verification_label' => $verifiedLabel,
                'payment_status_label' => $refunded ? 'Refunded' : 'Down payment retained pending resolution',
                'card_message' => $refunded
                    ? 'Operator no-show verified. Your down payment was refunded on GCash.'
                    : 'Operator no-show verified. Request a full refund or pick another schedule in rental details.',
                'list_message' => $refunded
                    ? 'Cancellation reason: Operator No-Show · Verified · Payment: Refunded'
                    : 'Cancellation reason: Operator No-Show · Verified · Payment: Pending refund or reschedule',
                'detail_message' => $refunded
                    ? 'Your full down payment refund was completed on GCash.'
                    : 'Barangay verified the operator did not arrive. You may request a full GCash refund from rental details.',
                'use_weather_message' => false,
            ];
        }

        if (in_array($booking->status, [BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected], true)) {
            $reason = filled($booking->cancellation_reason)
                ? $booking->cancellation_reason
                : 'Rental cancelled';

            return [
                'kind' => 'cancelled',
                'reason_label' => $reason,
                'verification_label' => null,
                'payment_status_label' => null,
                'card_message' => $reason,
                'list_message' => $reason,
                'detail_message' => $reason,
                'use_weather_message' => false,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operatorNoShowIncident(
        BoatBooking $booking,
        OperatorNoShowReport $report,
        ?User $viewer,
    ): array {
        $service = app(OperatorNoShowService::class);
        $incidentViewer = $viewer ?? $booking->user;
        $mobile = $incidentViewer instanceof User
            ? $service->mobileIncidentPayload($booking, $incidentViewer)
            : null;
        $prdStatus = self::mapOperatorReportStatus($report);
        $violation = OperatorNoShowViolationModel::query()
            ->where('operator_no_show_report_id', $report->id)
            ->first();

        return [
            'incident_id' => $report->id,
            'incident_type' => 'operator_no_show',
            'incident_type_label' => 'Operator No-Show',
            'date_reported' => $report->reported_at?->toDateString(),
            'date_reported_label' => $report->reported_at?->format('M d, Y · g:i A'),
            'report_status' => $prdStatus['value'],
            'report_status_label' => $prdStatus['label'],
            'admin_decision' => self::adminDecisionLabel($report),
            'admin_decision_reason' => $report->status === OperatorNoShowReportStatus::Dismissed
                ? $report->admin_notes
                : null,
            'resolution' => $report->resolution?->label() ?? $report->passenger_choice?->label(),
            'resolution_status' => $mobile['passenger_status'] ?? null,
            'resolution_status_label' => $mobile['passenger_status_label'] ?? null,
            'resolution_message' => $mobile['passenger_message'] ?? null,
            'date_resolved_label' => $report->resolved_at?->format('M d, Y · g:i A'),
            'reviewed_at_label' => $report->reviewed_at?->format('M d, Y · g:i A'),
            'reviewer_name' => $report->reviewer?->name,
            'report_note' => $report->report_note,
            'has_open_incident' => $report->isOpen(),
            'violation' => $violation instanceof OperatorNoShowViolationModel
                ? [
                    'strike_number' => $violation->strike_number,
                    'strike_label' => $violation->strike_number.'/3',
                    'recorded_at_label' => $violation->created_at?->format('M d, Y · g:i A'),
                    'reason' => $violation->reason,
                ]
                : null,
            'operator_no_show' => $mobile,
            'can_report_operator_no_show' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function passengerNoShowIncident(BoatBooking $booking): array
    {
        $acknowledged = $booking->passenger_no_show_admin_acknowledged_at !== null;

        return [
            'incident_id' => $booking->id,
            'incident_type' => 'passenger_no_show',
            'incident_type_label' => 'Passenger No-Show',
            'date_reported_label' => $booking->updated_at?->format('M d, Y · g:i A'),
            'report_status' => $acknowledged ? 'resolved' : 'under_review',
            'report_status_label' => $acknowledged ? 'Resolved' : 'Under Review',
            'admin_decision' => $acknowledged
                ? 'Verified by Admin'
                : 'Pending barangay review',
            'admin_decision_reason' => null,
            'resolution' => 'Downpayment retained; commission waived if unpaid',
            'resolution_status' => $acknowledged ? 'resolved' : 'pending_review',
            'resolution_status_label' => $acknowledged ? 'Resolved' : 'Pending Review',
            'resolution_message' => $acknowledged
                ? 'Barangay reviewed this passenger no-show record.'
                : 'The operator reported that you did not arrive. Barangay will review the record.',
            'date_resolved_label' => $booking->passenger_no_show_admin_acknowledged_at?->format('M d, Y · g:i A'),
            'reviewer_name' => $booking->passengerNoShowAdminAcknowledgedBy?->name,
            'has_open_incident' => ! $acknowledged,
            'violation' => null,
            'operator_no_show' => null,
            'can_report_operator_no_show' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function weatherIncident(BoatBooking $booking): array
    {
        return [
            'incident_id' => $booking->weather_suspension_id,
            'incident_type' => 'weather_suspension',
            'incident_type_label' => 'Weather Suspension',
            'date_reported_label' => $booking->weather_hold_at?->format('M d, Y · g:i A'),
            'report_status' => $booking->canRequestReschedule() ? 'under_review' : 'resolved',
            'report_status_label' => $booking->canRequestReschedule() ? 'Under Review' : 'Resolved',
            'admin_decision' => 'Trip unsafe due to weather — not an operator violation',
            'admin_decision_reason' => $booking->weather_reason
                ? (\App\Enums\WeatherHoldReason::tryFrom($booking->weather_reason)?->label() ?? $booking->weather_reason)
                : null,
            'resolution' => $booking->reschedule_status === 'approved'
                ? 'Rescheduled'
                : ($booking->canRequestReschedule() ? 'Reschedule or refund available' : null),
            'resolution_status' => $booking->reschedule_status,
            'resolution_status_label' => $booking->reschedule_status
                ? ucfirst(str_replace('_', ' ', (string) $booking->reschedule_status))
                : null,
            'resolution_message' => 'You may request a new date or a full refund if rescheduling is not possible.',
            'date_resolved_label' => null,
            'has_open_incident' => $booking->canRequestReschedule()
                || $booking->reschedule_status === 'pending',
            'violation' => null,
            'operator_no_show' => null,
            'can_report_operator_no_show' => false,
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private static function mapOperatorReportStatus(OperatorNoShowReport $report): array
    {
        if ($report->status === OperatorNoShowReportStatus::Pending) {
            return ['value' => 'pending_review', 'label' => 'Pending Review'];
        }

        if ($report->status === OperatorNoShowReportStatus::Dismissed) {
            return ['value' => 'rejected', 'label' => 'Rejected'];
        }

        if ($report->resolved_at !== null
            || $report->refund_status === OperatorNoShowRefundStatus::Refunded) {
            return ['value' => 'resolved', 'label' => 'Resolved'];
        }

        if ($report->status === OperatorNoShowReportStatus::Verified) {
            if ($report->passenger_choice !== null && $report->resolved_at === null) {
                return ['value' => 'under_review', 'label' => 'Under Review'];
            }

            return ['value' => 'approved', 'label' => 'Verified – Operator No-Show'];
        }

        return ['value' => 'under_review', 'label' => 'Under Review'];
    }

    private static function adminDecisionLabel(OperatorNoShowReport $report): ?string
    {
        return match ($report->status) {
            OperatorNoShowReportStatus::Pending => 'Waiting for barangay verification',
            OperatorNoShowReportStatus::Dismissed => 'Report rejected',
            OperatorNoShowReportStatus::Verified => $report->resolved_at !== null
                ? 'Verified — incident closed'
                : 'Report verified — choose refund or book again',
            default => null,
        };
    }
}

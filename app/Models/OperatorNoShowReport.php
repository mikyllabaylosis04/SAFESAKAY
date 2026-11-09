<?php

namespace App\Models;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\OperatorNoShowIncidentDisplayStatus;
use App\Enums\OperatorNoShowPassengerChoice;
use App\Enums\OperatorNoShowRefundStatus;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\OperatorNoShowRescheduleStatus;
use App\Enums\OperatorNoShowResolution;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'organization_id',
    'boat_booking_id',
    'reported_by_user_id',
    'report_note',
    'evidence_photo_path',
    'reported_at',
    'snapshot_booking_date',
    'status',
    'reviewed_by_user_id',
    'reviewed_at',
    'resolved_at',
    'admin_notes',
    'resolution',
    'passenger_choice',
    'passenger_choice_at',
    'passenger_choice_note',
    'preferred_booking_date',
    'preferred_start_time',
    'refund_status',
    'refund_reference',
    'refund_receipt_path',
    'refund_processed_by_user_id',
    'refund_processed_at',
    'refund_amount',
    'refund_gcash_account_name',
    'refund_gcash_mobile',
    'refund_gcash_qr_path',
    'refund_requested_at',
    'replacement_boat_id',
    'reschedule_boat_id',
    'reschedule_status',
    'report_reason',
    'replacement_offer_status',
    'replacement_offer_boat_id',
    'replacement_offered_by_user_id',
    'replacement_offered_at',
    'replacement_responded_at',
    'replacement_decline_reason',
    'rental_refund_request_id',
])]
class OperatorNoShowReport extends Model
{
    use BelongsToOrganization;

    public const REASONS = [
        'boat_not_arrived' => 'Boat did not arrive',
        'operator_not_arrived' => 'Operator did not arrive',
        'both_not_arrived' => 'Boat operator did not arrive',
    ];

    public const OFFER_PENDING_OPERATOR = 'pending_operator';

    public const OFFER_DECLINED = 'declined';

    public const OFFER_AWAITING_PASSENGER = 'awaiting_passenger';

    public const OFFER_ACCEPTED = 'accepted';

    public const OFFER_NONE_AVAILABLE = 'none_available';

    public function reasonLabel(): string
    {
        return self::REASONS[$this->report_reason ?? ''] ?? 'Boat did not arrive';
    }

    public function replacementOfferLabel(): ?string
    {
        return match ($this->replacement_offer_status) {
            self::OFFER_PENDING_OPERATOR => 'Waiting for replacement operator',
            self::OFFER_DECLINED => 'Replacement operator declined',
            self::OFFER_AWAITING_PASSENGER => 'Replacement offered to passenger',
            self::OFFER_ACCEPTED => 'Passenger accepted replacement',
            self::OFFER_NONE_AVAILABLE => 'No replacement available',
            default => null,
        };
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function replacementOfferBoat(): BelongsTo
    {
        return $this->belongsTo(Boat::class, 'replacement_offer_boat_id');
    }

    /**
     * @return BelongsTo<RentalRefundRequest, $this>
     */
    public function rentalRefundRequest(): BelongsTo
    {
        return $this->belongsTo(RentalRefundRequest::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'passenger_choice_at' => 'datetime',
            'refund_requested_at' => 'datetime',
            'refund_processed_at' => 'datetime',
            'replacement_offered_at' => 'datetime',
            'replacement_responded_at' => 'datetime',
            'snapshot_booking_date' => 'date',
            'preferred_booking_date' => 'date',
            'status' => OperatorNoShowReportStatus::class,
            'resolution' => OperatorNoShowResolution::class,
            'passenger_choice' => OperatorNoShowPassengerChoice::class,
            'refund_status' => OperatorNoShowRefundStatus::class,
            'reschedule_status' => OperatorNoShowRescheduleStatus::class,
        ];
    }

    /**
     * @return array{value: string, label: string, message: string, tone: string}
     */
    public function passengerTracking(): array
    {
        if ($this->status === OperatorNoShowReportStatus::Pending) {
            return [
                'value' => 'pending_verification',
                'label' => 'Barangay is checking',
                'message' => 'We told barangay the boat did not arrive. Wait for their check.',
                'tone' => 'orange',
            ];
        }

        if ($this->status === OperatorNoShowReportStatus::Dismissed) {
            return [
                'value' => 'report_rejected',
                'label' => 'Report closed',
                'message' => $this->admin_notes ?: 'Barangay closed this report. Your original booking stays.',
                'tone' => 'red',
            ];
        }

        if ($this->status === OperatorNoShowReportStatus::Delayed) {
            return [
                'value' => 'delayed',
                'label' => 'Boat is delayed',
                'message' => $this->admin_notes ?: 'Barangay confirmed the boat is delayed but still coming. Your booking stays.',
                'tone' => 'orange',
            ];
        }

        if ($this->passenger_choice === null && $this->resolved_at === null
            && $this->replacement_offer_status === self::OFFER_AWAITING_PASSENGER) {
            return [
                'value' => 'replacement_offered',
                'label' => 'Replacement offered',
                'message' => 'Your original boat or operator is unavailable. An alternative service has been arranged. Please choose how you would like to proceed.',
                'tone' => 'blue',
            ];
        }

        if ($this->passenger_choice === null && $this->resolved_at === null
            && $this->replacement_offer_status === self::OFFER_PENDING_OPERATOR) {
            return [
                'value' => 'finding_replacement',
                'label' => 'Finding a replacement',
                'message' => 'Barangay confirmed the no-show and is arranging a replacement boat. You can also choose a refund or a new date.',
                'tone' => 'orange',
            ];
        }

        if ($this->passenger_choice === null && $this->resolved_at === null) {
            return [
                'value' => 'awaiting_passenger_decision',
                'label' => 'Choose help',
                'message' => 'The boat did not arrive. Keep your down payment on a new date, or ask barangay to return it on GCash.',
                'tone' => 'orange',
            ];
        }

        if ($this->passenger_choice === OperatorNoShowPassengerChoice::Refund) {
            $amount = $this->refund_amount !== null ? OrganizationRoute::formatPeso($this->refund_amount) : 'your payment';

            if ($this->refund_status === OperatorNoShowRefundStatus::Refunded) {
                return [
                    'value' => 'refunded',
                    'label' => 'Refunded',
                    'message' => "Your full refund of {$amount} was sent to your GCash."
                        .($this->refund_processed_at ? ' Date: '.$this->refund_processed_at->format('M d, Y').'.' : '')
                        .($this->refund_reference ? " Reference: {$this->refund_reference}." : ''),
                    'tone' => 'green',
                ];
            }
            if (in_array($this->refund_status, [OperatorNoShowRefundStatus::Approved, OperatorNoShowRefundStatus::Processing], true)) {
                return [
                    'value' => 'refund_processing',
                    'label' => 'Verified — Full Refund',
                    'message' => "Barangay confirmed the boat did not arrive. Your full refund of {$amount} is being processed. You will be notified once it is sent.",
                    'tone' => 'orange',
                ];
            }

            return [
                'value' => 'refund_pending',
                'label' => 'Refund Requested',
                'message' => 'Your full refund request is with Barangay Admin for review.',
                'tone' => 'orange',
            ];
        }

        if ($this->passenger_choice === OperatorNoShowPassengerChoice::Reschedule) {
            if ($this->reschedule_status === OperatorNoShowRescheduleStatus::Approved || $this->resolved_at !== null) {
                return [
                    'value' => 'rescheduled',
                    'label' => 'Rescheduled',
                    'message' => 'Your rental was moved to the new boat and schedule.',
                    'tone' => 'green',
                ];
            }
            if ($this->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator) {
                return [
                    'value' => 'awaiting_operator_confirmation',
                    'label' => 'Awaiting Operator Confirmation',
                    'message' => 'The boat operator is confirming your requested schedule.',
                    'tone' => 'orange',
                ];
            }
            if ($this->reschedule_status === OperatorNoShowRescheduleStatus::Rejected) {
                return [
                    'value' => 'reschedule_rejected',
                    'label' => 'Reschedule Declined',
                    'message' => 'The operator could not take that schedule. Pick another date or request a refund.',
                    'tone' => 'red',
                ];
            }

            return [
                'value' => 'reschedule_requested',
                'label' => 'Reschedule Requested',
                'message' => 'Finish choosing a boat and schedule for your new trip.',
                'tone' => 'orange',
            ];
        }

        return [
            'value' => 'resolution_processing',
            'label' => 'Processing',
            'message' => 'Barangay Admin is processing your operator no-show incident.',
            'tone' => 'blue',
        ];
    }

    /**
     * @return BelongsTo<BoatBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(BoatBooking::class, 'boat_booking_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function replacementBoat(): BelongsTo
    {
        return $this->belongsTo(Boat::class, 'replacement_boat_id');
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function rescheduleBoat(): BelongsTo
    {
        return $this->belongsTo(Boat::class, 'reschedule_boat_id');
    }

    public function isOpen(): bool
    {
        if (in_array($this->status, [OperatorNoShowReportStatus::Dismissed, OperatorNoShowReportStatus::Delayed], true)) {
            return false;
        }

        if ($this->resolved_at !== null) {
            return false;
        }

        return $this->incidentDisplayStatus() !== OperatorNoShowIncidentDisplayStatus::Resolved
            && $this->incidentDisplayStatus() !== OperatorNoShowIncidentDisplayStatus::Rejected;
    }

    public function incidentDisplayStatus(): OperatorNoShowIncidentDisplayStatus
    {
        if ($this->status === OperatorNoShowReportStatus::Pending) {
            return OperatorNoShowIncidentDisplayStatus::PendingVerification;
        }

        if ($this->status === OperatorNoShowReportStatus::Dismissed) {
            return OperatorNoShowIncidentDisplayStatus::Rejected;
        }

        if ($this->status === OperatorNoShowReportStatus::Delayed) {
            return OperatorNoShowIncidentDisplayStatus::Delayed;
        }

        if ($this->resolved_at !== null) {
            return OperatorNoShowIncidentDisplayStatus::Resolved;
        }

        if ($this->passenger_choice === null && $this->replacement_offer_status === self::OFFER_PENDING_OPERATOR) {
            return OperatorNoShowIncidentDisplayStatus::ReplacementSearch;
        }

        if ($this->resolution === OperatorNoShowResolution::RefundApproved) {
            if ($this->refund_status === OperatorNoShowRefundStatus::Refunded) {
                return OperatorNoShowIncidentDisplayStatus::Resolved;
            }

            if ($this->refund_status === OperatorNoShowRefundStatus::Rejected) {
                return OperatorNoShowIncidentDisplayStatus::Rejected;
            }

            return OperatorNoShowIncidentDisplayStatus::RefundProcessing;
        }

        if ($this->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
            && $this->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator) {
            return OperatorNoShowIncidentDisplayStatus::AwaitingOperatorConfirmation;
        }

        if ($this->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
            && $this->resolved_at === null
            && $this->reschedule_boat_id
            && $this->preferred_booking_date
            && ! in_array($this->reschedule_status, [
                OperatorNoShowRescheduleStatus::Approved,
                OperatorNoShowRescheduleStatus::Rejected,
            ], true)) {
            return OperatorNoShowIncidentDisplayStatus::AwaitingOperatorConfirmation;
        }

        if ($this->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
            && $this->reschedule_status === OperatorNoShowRescheduleStatus::Rejected) {
            return OperatorNoShowIncidentDisplayStatus::ResolutionProcessing;
        }

        if ($this->resolution === OperatorNoShowResolution::RescheduleOffered) {
            if ($this->rescheduleIsComplete()) {
                return OperatorNoShowIncidentDisplayStatus::Resolved;
            }

            return OperatorNoShowIncidentDisplayStatus::Rescheduling;
        }

        if ($this->resolution === OperatorNoShowResolution::Reassigned) {
            return OperatorNoShowIncidentDisplayStatus::Resolved;
        }

        if ($this->status === OperatorNoShowReportStatus::Verified) {
            if ($this->passenger_choice === null) {
                return OperatorNoShowIncidentDisplayStatus::AwaitingPassengerDecision;
            }

            if ($this->resolution === null) {
                return OperatorNoShowIncidentDisplayStatus::ResolutionProcessing;
            }
        }

        return OperatorNoShowIncidentDisplayStatus::ResolutionProcessing;
    }

    public function rescheduleIsComplete(): bool
    {
        $booking = $this->relationLoaded('booking') ? $this->booking : $this->booking()->first();

        if (! $booking) {
            return false;
        }

        $snapshot = $this->snapshot_booking_date ?? $booking->original_booking_date ?? $booking->booking_date;

        if ($snapshot === null) {
            return false;
        }

        return ! $booking->booking_date->equalTo($snapshot)
            && in_array($booking->status, [
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
                BoatBookingStatus::AwaitingPayment,
            ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminRecord(): array
    {
        $this->loadMissing([
            'booking.boat.operator',
            'booking.boat.pictures',
            'reporter',
            'reviewer',
            'replacementBoat.operator',
            'rescheduleBoat',
            'replacementOfferBoat.operator',
            'rentalRefundRequest',
        ]);

        $booking = $this->booking;
        $display = $this->incidentDisplayStatus();
        $paymentVerified = $booking?->paymentStatus() === BookingPaymentStatus::Verified;

        return [
            'id' => $this->id,
            'incident_id' => $this->id,
            'incident_kind' => 'operator_no_show',
            'incident_type_label' => 'Boat did not arrive',
            'sort_at' => ($this->reported_at ?? $this->created_at)?->getTimestamp() ?? 0,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'incident_status' => $display->value,
            'incident_status_label' => $display->label(),
            'incident_status_badge_class' => $display->badgeClass(),
            'is_open' => $this->isOpen() && $display !== OperatorNoShowIncidentDisplayStatus::Resolved,
            'resolution' => $this->resolution?->value,
            'resolution_label' => $this->resolution?->label(),
            'refund_status' => $this->refund_status?->value,
            'refund_status_label' => $this->refund_status?->workflowLabel(),
            'refund_reference' => $this->refund_reference,
            'refund_requested_at_label' => $this->refund_requested_at?->format('M d, Y · g:i A'),
            'refund_processed_at_label' => $this->refund_processed_at?->format('M d, Y · g:i A'),
            'refund_gcash_account_name' => $this->refund_gcash_account_name,
            'refund_gcash_mobile' => $this->refund_gcash_mobile,
            'refund_gcash_qr_url' => filled($this->refund_gcash_qr_path)
                ? Storage::disk('public')->url($this->refund_gcash_qr_path)
                : null,
            'refund_receipt_url' => filled($this->refund_receipt_path)
                ? Storage::disk('public')->url($this->refund_receipt_path)
                : null,
            'refund_amount_label' => $this->refund_amount !== null
                ? OrganizationRoute::formatPeso($this->refund_amount)
                : ($booking?->down_payment_amount !== null
                    ? OrganizationRoute::formatPeso($booking->down_payment_amount)
                    : null),
            'report_note' => $this->report_note,
            'evidence_photo_url' => filled($this->evidence_photo_path)
                ? '/storage/'.ltrim($this->evidence_photo_path, '/')
                : null,
            'admin_notes' => $this->admin_notes,
            'reported_at_label' => $this->reported_at?->format('M d, Y · g:i A'),
            'reviewed_at_label' => $this->reviewed_at?->format('M d, Y · g:i A'),
            'resolved_at_label' => $this->resolved_at?->format('M d, Y · g:i A'),
            'reporter_name' => $this->reporter?->name,
            'reviewer_name' => $this->reviewer?->name,
            'replacement_boat_name' => $this->replacementBoat?->name,
            'passenger_choice' => $this->passenger_choice?->value,
            'passenger_choice_label' => $this->passenger_choice?->label(),
            'passenger_choice_at_label' => $this->passenger_choice_at?->format('M d, Y · g:i A'),
            'passenger_choice_note' => $this->passenger_choice_note,
            'preferred_booking_date_label' => $this->preferred_booking_date?->format('M d, Y'),
            'preferred_start_time_label' => $this->preferred_start_time
                ? \Illuminate\Support\Carbon::parse($this->preferred_start_time)->format('g:i A')
                : null,
            'report_reason' => $this->report_reason,
            'report_reason_label' => $this->reasonLabel(),
            'replacement_offer_status' => $this->replacement_offer_status,
            'replacement_offer_label' => $this->replacementOfferLabel(),
            'replacement_offer_boat_name' => $this->replacementOfferBoat?->name,
            'replacement_offer_operator_name' => $this->replacementOfferBoat?->operator?->full_name,
            'replacement_offered_at_label' => $this->replacement_offered_at?->format('M d, Y · g:i A'),
            'replacement_responded_at_label' => $this->replacement_responded_at?->format('M d, Y · g:i A'),
            'replacement_decline_reason' => $this->replacement_decline_reason,
            'can_offer_replacement' => $this->status === OperatorNoShowReportStatus::Verified
                && $this->resolved_at === null
                && in_array($this->passenger_choice, [null, OperatorNoShowPassengerChoice::Replacement], true)
                && in_array($this->replacement_offer_status, [null, self::OFFER_DECLINED, self::OFFER_NONE_AVAILABLE], true),
            'can_mark_no_replacement' => $this->status === OperatorNoShowReportStatus::Verified
                && $this->resolved_at === null
                && $this->passenger_choice === null
                && in_array($this->replacement_offer_status, [null, self::OFFER_DECLINED], true),
            'refund_via_xendit' => $this->rental_refund_request_id !== null,
            'xendit_refund' => $this->rental_refund_request_id
                ? app(\App\Services\RentalRefundService::class)->adminPayload($this->rentalRefundRequest)
                : null,
            'can_verify' => $this->status === OperatorNoShowReportStatus::Pending,
            'can_process_refund' => $this->status === OperatorNoShowReportStatus::Verified
                && $this->passenger_choice === OperatorNoShowPassengerChoice::Refund
                && $this->resolution === null
                && $this->refund_status === OperatorNoShowRefundStatus::Pending,
            'can_process_reschedule' => $this->status === OperatorNoShowReportStatus::Verified
                && $this->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
                && $this->resolution === null
                && $this->reschedule_status !== OperatorNoShowRescheduleStatus::PendingOperator
                && (
                    ($this->reschedule_status === null && $this->reschedule_boat_id === null)
                    || $this->reschedule_status === OperatorNoShowRescheduleStatus::Rejected
                ),
            'admin_queue_active' => $this->shouldShowInAdminActiveQueue(),
            'reschedule_status' => $this->reschedule_status?->value,
            'reschedule_status_label' => $this->reschedule_status?->label(),
            'reschedule_boat_name' => $this->rescheduleBoat?->name,
            'reschedule_boat_operator_name' => $this->rescheduleBoat?->operator?->full_name,
            'original_boat_name' => $booking?->boat?->name,
            'downpayment_retained_label' => $booking?->down_payment_amount !== null
                ? OrganizationRoute::formatPeso($booking->down_payment_amount)
                : null,
            'awaiting_confirmation_operator_name' => $this->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator
                ? $this->rescheduleBoat?->operator?->full_name
                : null,
            'can_process_replacement' => $this->status === OperatorNoShowReportStatus::Verified
                && $this->passenger_choice === OperatorNoShowPassengerChoice::Replacement
                && $this->resolution === null,
            'can_reject_reschedule' => $this->status === OperatorNoShowReportStatus::Verified
                && $this->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
                && $this->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator
                && $this->resolved_at === null
                && $this->resolution === null,
            'can_complete_refund' => $this->resolution === OperatorNoShowResolution::RefundApproved
                && in_array($this->refund_status, [
                    OperatorNoShowRefundStatus::Approved,
                    OperatorNoShowRefundStatus::Processing,
                ], true)
                && $this->resolved_at === null
                && ($this->rental_refund_request_id === null
                    || $this->rentalRefundRequest?->status === RentalRefundRequest::STATUS_FAILED),
            'completion_message' => $this->completionMessage(),
            ...$this->managementFlags(),
            'booking' => $booking ? [
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
            ] : null,
        ];
    }

    /**
     * Simplified management stage: pending → verified (full refund) → refunded, or rejected.
     */
    public function managementStage(): string
    {
        return match (true) {
            $this->status === OperatorNoShowReportStatus::Pending => 'pending',
            in_array($this->status, [OperatorNoShowReportStatus::Dismissed, OperatorNoShowReportStatus::Delayed], true) => 'rejected',
            $this->refund_status === OperatorNoShowRefundStatus::Refunded || $this->resolved_at !== null => 'refunded',
            default => 'refund',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function managementFlags(): array
    {
        $stage = $this->managementStage();
        $linked = $this->rentalRefundRequest;
        $linkedOpen = $linked && $linked->status !== RentalRefundRequest::STATUS_FAILED;
        $paidOnline = $this->booking ? app(\App\Services\RentalRefundService::class)->paidOnline($this->booking) > 0 : false;

        return [
            'stage' => $stage,
            'stage_label' => match ($stage) {
                'pending' => 'Pending Verification',
                'refund' => $linked?->status === RentalRefundRequest::STATUS_REFUNDING ? 'Refund Processing' : 'Verified — Refund Due',
                'refunded' => 'Refunded',
                default => 'Rejected',
            },
            'refund_amount_value' => $this->refund_amount !== null ? (float) $this->refund_amount : null,
            'refund_method_label' => $linked ? 'GCash via Xendit' : ($this->refund_reference ? 'GCash transfer (recorded)' : null),
            'can_send_xendit_refund' => $stage === 'refund' && $paidOnline && ! $linkedOpen,
            'can_record_refund' => $stage === 'refund' && ! $linkedOpen,
            'refund_processing' => $stage === 'refund' && $linked?->status === RentalRefundRequest::STATUS_REFUNDING,
        ];
    }

    private function completionMessage(): ?string
    {
        if ($this->incidentDisplayStatus() !== OperatorNoShowIncidentDisplayStatus::Resolved) {
            return null;
        }

        if ($this->resolution === OperatorNoShowResolution::RescheduleOffered || $this->rescheduleIsComplete()) {
            return 'Rescheduling completed — the original downpayment has been retained and applied to the confirmed new schedule. No additional downpayment is required.';
        }

        if ($this->resolution === OperatorNoShowResolution::RefundApproved
            && $this->refund_status === OperatorNoShowRefundStatus::Refunded) {
            return 'Refund completed — the verified refund amount has been returned to the passenger.';
        }

        if ($this->resolution === OperatorNoShowResolution::Reassigned) {
            return 'Replacement boat assigned — the original downpayment remains on this booking.';
        }

        return null;
    }

    private function shouldShowInAdminActiveQueue(): bool
    {
        if ($this->isOpen()) {
            return true;
        }

        return $this->status === OperatorNoShowReportStatus::Verified
            && $this->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
            && $this->reschedule_status === OperatorNoShowRescheduleStatus::Rejected
            && $this->resolved_at === null;
    }
}

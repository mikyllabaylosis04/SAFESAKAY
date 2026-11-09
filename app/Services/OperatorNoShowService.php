<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatStatus;
use App\Enums\OperatorNoShowPassengerChoice;
use App\Enums\OperatorNoShowRefundStatus;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\OperatorNoShowRescheduleStatus;
use App\Enums\OperatorNoShowResolution;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\OperatorNoShowReport;
use App\Models\OrganizationRoute;
use App\Models\User;
use App\Notifications\OperatorNoShowDecisionNotification;
use App\Notifications\OperatorNoShowReportedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OperatorNoShowService
{
    public const OPERATOR_NO_SHOW_REPORT_GRACE_MINUTES = 30;

    public const OPERATOR_RESCHEDULE_CONFIRMATION_DEADLINE_MINUTES = 30;

    public const AUTO_REJECT_OPERATOR_RESCHEDULE_REASON = 'Automatically declined: replacement operator did not confirm within 30 minutes.';

    public const CANCELLATION_REASON = 'Operator No-Show';

    public function canPassengerReport(BoatBooking $booking, User $user): bool
    {
        if ((int) $booking->user_id !== (int) $user->id) {
            return false;
        }

        if (! in_array($booking->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)) {
            return false;
        }

        if (! $this->graceHasPassed($booking, $this->operatorGraceMinutes($booking))) {
            return false;
        }

        return ! $booking->operatorNoShowReports()
            ->where(function ($query) {
                $query->where('status', OperatorNoShowReportStatus::Pending)
                    ->orWhere(function ($inner) {
                        $inner->where('status', OperatorNoShowReportStatus::Verified)
                            ->whereNull('resolved_at');
                    });
            })
            ->exists();
    }

    public function canOperatorMarkPassengerNoShow(BoatBooking $booking, User $user): bool
    {
        return app(TripConfirmationService::class)->canMarkPassengerNoShow($booking, $user);
    }

    public function canOperatorConfirmAvailability(BoatBooking $booking, User $user): bool
    {
        if (! $user->isMobileOperator()) {
            return false;
        }

        $booking->loadMissing('boat.operator');

        if ((int) $booking->boat?->operator?->user_id !== (int) $user->id) {
            return false;
        }

        if (! in_array($booking->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::AwaitingPayment], true)) {
            return false;
        }

        if (! $booking->booking_date->isToday() && ! $booking->booking_date->isFuture()) {
            return false;
        }

        return app(TripConfirmationService::class)->canConfirm($booking, $user);
    }

    public function operatorHasNoShowRescheduleVisibility(BoatBooking $booking, User $user): bool
    {
        if (! $user->isMobileOperator()) {
            return false;
        }

        $report = $this->latestVerifiedOpenReport($booking);
        if (! $report instanceof OperatorNoShowReport
            || $report->passenger_choice !== OperatorNoShowPassengerChoice::Reschedule) {
            return false;
        }

        $boatIds = Operator::query()
            ->where('user_id', $user->id)
            ->pluck('boat_id');

        if ($report->reschedule_boat_id && $boatIds->contains($report->reschedule_boat_id)) {
            return true;
        }

        if ($boatIds->contains($booking->boat_id)) {
            return true;
        }

        return in_array($booking->id, $this->pendingRescheduleBookingIdsForOperator($user), true);
    }

    public function canOperatorRespondReschedule(BoatBooking $booking, User $user): bool
    {
        if (! $user->isMobileOperator()) {
            return false;
        }

        $report = $this->latestVerifiedOpenReport($booking);
        if (! $report instanceof OperatorNoShowReport
            || $report->passenger_choice !== OperatorNoShowPassengerChoice::Reschedule
            || $report->reschedule_status !== OperatorNoShowRescheduleStatus::PendingOperator
            || ! $report->reschedule_boat_id) {
            return false;
        }

        return Operator::userManagesBoat($user, $report->reschedule_boat_id);
    }

    /**
     * @return list<int>
     */
    public function pendingRescheduleBookingIdsForOperator(User $operatorUser): array
    {
        if (! $operatorUser->isMobileOperator()) {
            return [];
        }

        $boatIds = Operator::query()
            ->where('user_id', $operatorUser->id)
            ->pluck('boat_id');

        if ($boatIds->isEmpty()) {
            return [];
        }

        return OperatorNoShowReport::query()
            ->where('status', OperatorNoShowReportStatus::Verified)
            ->whereNull('resolved_at')
            ->where('passenger_choice', OperatorNoShowPassengerChoice::Reschedule)
            ->where('reschedule_status', OperatorNoShowRescheduleStatus::PendingOperator)
            ->whereIn('reschedule_boat_id', $boatIds)
            ->pluck('boat_booking_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function report(
        BoatBooking $booking,
        User $passenger,
        ?string $note = null,
        ?string $evidencePhotoPath = null,
        ?string $reason = null,
    ): OperatorNoShowReport {
        if (! $this->canPassengerReport($booking, $passenger)) {
            $opensAt = $this->reportOpensAt($booking);
            $tooEarly = in_array($booking->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)
                && $opensAt
                && now('Asia/Manila')->lt($opensAt);

            throw ValidationException::withMessages([
                'booking' => [$tooEarly
                    ? 'You can report a no-show from '.$opensAt->format('M d, g:i A').' ('.$this->operatorGraceMinutes($booking).' minutes after departure).'
                    : 'This rental cannot be reported as an operator no-show right now.'],
            ]);
        }

        $report = OperatorNoShowReport::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'reported_by_user_id' => $passenger->id,
            'report_reason' => array_key_exists((string) $reason, OperatorNoShowReport::REASONS) ? $reason : 'both_not_arrived',
            'report_note' => filled($note) ? trim($note) : null,
            'evidence_photo_path' => $evidencePhotoPath,
            'reported_at' => now(),
            'snapshot_booking_date' => $booking->booking_date,
            'status' => OperatorNoShowReportStatus::Pending,
        ]);

        $booking->recordActivity(
            'operator_no_show_reported',
            $passenger,
            $passenger->name,
        );

        $this->notifyOperatorNoShowReport($booking, $passenger);

        return $report;
    }

    /**
     * @throws ValidationException
     */
    public function markPassengerNoShow(
        BoatBooking $booking,
        User $operator,
        ?string $note = null,
        bool $declarationConfirmed = false,
        ?string $evidencePhotoPath = null,
    ): BoatBooking {
        return app(PassengerNoShowService::class)->report(
            $booking,
            $operator,
            $declarationConfirmed,
            $note,
            $evidencePhotoPath,
        );
    }

    public function canPassengerChooseResolution(BoatBooking $booking, User $user): bool
    {
        if ((int) $booking->user_id !== (int) $user->id) {
            return false;
        }

        $report = $this->latestVerifiedOpenReport($booking);

        if (! $report instanceof OperatorNoShowReport) {
            return false;
        }

        return $report->passenger_choice === null
            || ($report->passenger_choice === OperatorNoShowPassengerChoice::Replacement
                && $report->replacement_offer_status === OperatorNoShowReport::OFFER_AWAITING_PASSENGER);
    }

    /**
     * @throws ValidationException
     */
    public function passengerChooseResolution(
        BoatBooking $booking,
        User $passenger,
        OperatorNoShowPassengerChoice $choice,
        ?string $note = null,
        ?string $preferredDate = null,
        ?string $preferredTime = null,
    ): OperatorNoShowReport {
        if (! $this->canPassengerChooseResolution($booking, $passenger)) {
            throw ValidationException::withMessages([
                'choice' => ['You cannot select a resolution for this incident right now.'],
            ]);
        }

        $report = $this->latestVerifiedOpenReport($booking);
        if (! $report instanceof OperatorNoShowReport) {
            throw ValidationException::withMessages([
                'choice' => ['No verified operator no-show incident found.'],
            ]);
        }

        if ($choice === OperatorNoShowPassengerChoice::Replacement
            && $report->replacement_offer_status === OperatorNoShowReport::OFFER_AWAITING_PASSENGER) {
            return $this->acceptReplacementOffer($booking, $passenger, $report, $note);
        }

        $booking->loadMissing('boat');
        $updates = [
            'passenger_choice' => $choice,
            'passenger_choice_at' => now(),
            'passenger_choice_note' => filled($note) ? trim($note) : null,
            'preferred_booking_date' => null,
            'preferred_start_time' => null,
        ];

        if ($choice === OperatorNoShowPassengerChoice::Refund) {
            $updates['refund_status'] = OperatorNoShowRefundStatus::Pending;
            $updates['refund_amount'] = $booking->down_payment_amount;
        }

        if ($choice !== OperatorNoShowPassengerChoice::Replacement
            && in_array($report->replacement_offer_status, [OperatorNoShowReport::OFFER_PENDING_OPERATOR, OperatorNoShowReport::OFFER_AWAITING_PASSENGER], true)) {
            $updates['replacement_offer_status'] = null;
            $updates['replacement_offer_boat_id'] = null;
        }

        $report->update($updates);

        $event = match ($choice) {
            OperatorNoShowPassengerChoice::Refund => 'operator_no_show_passenger_chose_refund',
            OperatorNoShowPassengerChoice::Reschedule => 'operator_no_show_passenger_chose_reschedule',
            OperatorNoShowPassengerChoice::Replacement => 'operator_no_show_passenger_chose_replacement',
        };

        $booking->recordActivity($event, $passenger, $passenger->name);
        $this->notifyDecision($booking, $event);
        $this->notifyAdminsPassengerChoice($booking, $report);

        return $report->fresh();
    }

    public function latestVerifiedOpenReport(BoatBooking $booking): ?OperatorNoShowReport
    {
        return $booking->operatorNoShowReports()
            ->where('status', OperatorNoShowReportStatus::Verified)
            ->whereNull('resolved_at')
            ->latest('id')
            ->first();
    }

    public function confirmAvailability(BoatBooking $booking, User $operator): BoatBooking
    {
        return app(TripConfirmationService::class)->confirmByOperator($booking, $operator);
    }

    public function verify(OperatorNoShowReport $report, User $admin, ?string $adminNotes = null): OperatorNoShowReport
    {
        $this->assertPending($report);

        $report->update([
            'status' => OperatorNoShowReportStatus::Verified,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => filled($adminNotes) ? trim($adminNotes) : $report->admin_notes,
        ]);

        $report->booking?->recordActivity(
            'operator_no_show_verified',
            $admin,
            $admin->name,
        );

        if ($report->booking) {
            $this->cancelBookingForVerifiedOperatorNoShow($report->booking, $admin);
            $this->approveFullRefund($report->fresh(), $admin);
            app(OperatorNoShowViolationService::class)->recordOnVerifiedReport($report->fresh(), $admin);
        }

        return $report->fresh();
    }

    /**
     * A verified operator no-show always earns a full refund of what the passenger paid — no choice step.
     */
    private function approveFullRefund(OperatorNoShowReport $report, User $admin): void
    {
        $booking = $report->booking;
        $paid = round(app(RentalRefundService::class)->paidTotal($booking) / 100, 2);

        $report->update([
            'passenger_choice' => OperatorNoShowPassengerChoice::Refund,
            'passenger_choice_at' => now(),
            'resolution' => OperatorNoShowResolution::RefundApproved,
            'refund_status' => $paid > 0 ? OperatorNoShowRefundStatus::Approved : OperatorNoShowRefundStatus::Refunded,
            'refund_amount' => $paid,
            'resolved_at' => $paid > 0 ? null : now(),
        ]);

        $booking->recordActivity('operator_no_show_refund_approved', $admin, $admin->name);
        $this->notifyDecision($booking, 'operator_no_show_verified_full_refund', $paid > 0 ? OrganizationRoute::formatPeso($paid) : null);
    }

    /**
     * Send the full refund back to the passenger's GCash through Xendit (rentals paid online).
     *
     * @throws ValidationException
     */
    public function sendXenditRefund(OperatorNoShowReport $report, User $admin): OperatorNoShowReport
    {
        $this->assertRefundOpen($report);
        $booking = $report->booking;

        if ($report->rentalRefundRequest && $report->rentalRefundRequest->status !== \App\Models\RentalRefundRequest::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'refund' => ['The Xendit refund was already sent for this rental.'],
            ]);
        }

        $refund = $report->rentalRefundRequest?->status === \App\Models\RentalRefundRequest::STATUS_FAILED
            ? app(RentalRefundService::class)->retry($report->rentalRefundRequest, $admin)
            : app(RentalRefundService::class)->refundForOperatorNoShow($booking, $admin);

        if (! $refund) {
            throw ValidationException::withMessages([
                'refund' => ['This rental was not paid through Xendit. Send the refund by GCash, then use Record Refund.'],
            ]);
        }

        $fresh = $report->fresh();
        $fresh->update([
            'rental_refund_request_id' => $refund->id,
            'refund_status' => $fresh->refund_status === OperatorNoShowRefundStatus::Refunded
                ? OperatorNoShowRefundStatus::Refunded
                : OperatorNoShowRefundStatus::Processing,
        ]);

        if ($fresh->fresh()->refund_status === OperatorNoShowRefundStatus::Refunded) {
            $this->notifyDecision($booking, 'operator_no_show_refund_completed', 'Xendit refund');
        }

        return $report->fresh();
    }

    /**
     * @throws ValidationException
     */
    private function assertRefundOpen(OperatorNoShowReport $report): void
    {
        // Reports verified under the old choose-a-resolution flow move straight to the full refund.
        if ($report->status === OperatorNoShowReportStatus::Verified
            && $report->resolution !== OperatorNoShowResolution::RefundApproved
            && $report->resolved_at === null
            && auth()->user() instanceof User) {
            $this->approveFullRefund($report, auth()->user());
            $report->refresh();
        }

        if ($report->status !== OperatorNoShowReportStatus::Verified || $report->resolution !== OperatorNoShowResolution::RefundApproved) {
            throw ValidationException::withMessages([
                'report' => ['Verify the no-show report before processing the refund.'],
            ]);
        }

        if ($report->refund_status === OperatorNoShowRefundStatus::Refunded) {
            throw ValidationException::withMessages([
                'refund' => ['This refund has already been completed.'],
            ]);
        }
    }

    /**
     * Boat/operator is late but still coming: the booking stays as-is and the passenger may report again later.
     */
    public function markDelayed(OperatorNoShowReport $report, User $admin, string $adminNotes): OperatorNoShowReport
    {
        $this->assertPending($report);

        $report->update([
            'status' => OperatorNoShowReportStatus::Delayed,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'resolved_at' => now(),
            'admin_notes' => trim($adminNotes),
        ]);

        $report->booking?->recordActivity('operator_no_show_delayed', $admin, $admin->name);

        if ($report->booking) {
            $this->notifyDecision($report->booking, 'operator_no_show_delayed', trim($adminNotes));
        }

        return $report->fresh();
    }

    public function dismiss(OperatorNoShowReport $report, User $admin, string $adminNotes): OperatorNoShowReport
    {
        $this->assertPending($report);

        $report->update([
            'status' => OperatorNoShowReportStatus::Dismissed,
            'resolution' => OperatorNoShowResolution::Dismissed,
            'refund_status' => OperatorNoShowRefundStatus::Rejected,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'resolved_at' => now(),
            'admin_notes' => trim($adminNotes),
        ]);

        $report->booking?->recordActivity(
            'operator_no_show_dismissed',
            $admin,
            $admin->name,
        );

        if ($report->booking) {
            $this->notifyDecision($report->booking, 'operator_no_show_report_rejected', trim($adminNotes));
        }

        return $report->fresh();
    }

    public function reassign(
        OperatorNoShowReport $report,
        User $admin,
        int $replacementBoatId,
        ?string $adminNotes = null,
    ): OperatorNoShowReport {
        if ($report->status !== OperatorNoShowReportStatus::Verified) {
            throw ValidationException::withMessages([
                'report' => ['Verify the no-show report before reassigning a boat.'],
            ]);
        }

        $this->assertPassengerChoice($report, OperatorNoShowPassengerChoice::Replacement);

        $booking = $report->booking;
        $replacement = Boat::query()->findOrFail($replacementBoatId);

        if ((int) $replacement->organization_id !== (int) $booking->organization_id) {
            throw ValidationException::withMessages([
                'replacement_boat_id' => ['Replacement boat must belong to your organization.'],
            ]);
        }

        if ($replacement->capacity < $booking->passenger_count) {
            throw ValidationException::withMessages([
                'replacement_boat_id' => ['Replacement boat does not have enough capacity.'],
            ]);
        }

        $date = $booking->booking_date->toDateString();
        $time = Carbon::parse($booking->start_time)->format('H:i');
        $conflict = $replacement->slotConflictMessage($date, $time, $booking->id);

        if ($conflict) {
            throw ValidationException::withMessages([
                'replacement_boat_id' => [$conflict],
            ]);
        }

        $oldBoatName = $booking->boat?->name ?? 'Previous boat';
        $booking->update(['boat_id' => $replacement->id]);

        $report->update([
            'replacement_boat_id' => $replacement->id,
            'resolution' => OperatorNoShowResolution::Reassigned,
            'admin_notes' => filled($adminNotes) ? trim($adminNotes) : $report->admin_notes,
            'reviewed_by_user_id' => $report->reviewed_by_user_id ?? $admin->id,
            'reviewed_at' => $report->reviewed_at ?? now(),
            'resolved_at' => now(),
        ]);

        $booking->recordActivity(
            'operator_no_show_reassigned',
            $admin,
            sprintf('%s reassigned from %s to %s', $admin->name, $oldBoatName, $replacement->name),
        );

        $this->notifyDecision($booking, 'operator_no_show_reassigned', $replacement->name);

        return $report->fresh();
    }

    public function markResolution(
        OperatorNoShowReport $report,
        User $admin,
        OperatorNoShowResolution $resolution,
        ?string $adminNotes = null,
    ): OperatorNoShowReport {
        if ($report->status !== OperatorNoShowReportStatus::Verified) {
            throw ValidationException::withMessages([
                'report' => ['Verify the no-show report before recording a resolution.'],
            ]);
        }

        if (! in_array($resolution, [OperatorNoShowResolution::RescheduleOffered, OperatorNoShowResolution::RefundApproved], true)) {
            throw ValidationException::withMessages([
                'resolution' => ['Invalid resolution for this action.'],
            ]);
        }

        if ($report->resolution !== null) {
            throw ValidationException::withMessages([
                'report' => ['This incident already has a resolution in progress.'],
            ]);
        }

        if ($resolution === OperatorNoShowResolution::RefundApproved) {
            $this->assertPassengerChoice($report, OperatorNoShowPassengerChoice::Refund);
            if ($report->refund_status !== OperatorNoShowRefundStatus::Pending) {
                throw ValidationException::withMessages([
                    'report' => ['The passenger has not requested a refund for this incident.'],
                ]);
            }
        }

        if ($resolution === OperatorNoShowResolution::RescheduleOffered) {
            $this->assertPassengerChoice($report, OperatorNoShowPassengerChoice::Reschedule);
            if ($report->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator) {
                throw ValidationException::withMessages([
                    'report' => ['The passenger already submitted a reschedule request. Wait for the operator to confirm in the app.'],
                ]);
            }
        }

        $booking = $report->booking;

        $report->update([
            'resolution' => $resolution,
            'admin_notes' => filled($adminNotes) ? trim($adminNotes) : $report->admin_notes,
            'refund_status' => $resolution === OperatorNoShowResolution::RefundApproved
                ? OperatorNoShowRefundStatus::Approved
                : $report->refund_status,
            'refund_amount' => $resolution === OperatorNoShowResolution::RefundApproved
                ? ($booking?->down_payment_amount ?? $report->refund_amount)
                : $report->refund_amount,
        ]);

        $event = $resolution === OperatorNoShowResolution::RefundApproved
            ? 'operator_no_show_refund_approved'
            : 'operator_no_show_reschedule_offered';

        $booking?->recordActivity($event, $admin, $admin->name);

        if ($booking) {
            $this->notifyDecision($booking, $event);
        }

        if ($resolution === OperatorNoShowResolution::RefundApproved && $booking instanceof BoatBooking) {
            $refund = app(RentalRefundService::class)->refundForOperatorNoShow($booking, $admin);

            if ($refund) {
                $fresh = $report->fresh();
                $fresh->update([
                    'rental_refund_request_id' => $refund->id,
                    'refund_amount' => round($refund->refund_amount / 100, 2),
                    'refund_status' => $fresh->refund_status === OperatorNoShowRefundStatus::Refunded
                        ? OperatorNoShowRefundStatus::Refunded
                        : OperatorNoShowRefundStatus::Processing,
                ]);
            }
        }

        return $report->fresh();
    }

    /**
     * Record a refund the admin sent by GCash: amount, date, and transaction reference (receipt optional).
     *
     * @throws ValidationException
     */
    public function completeRefund(
        OperatorNoShowReport $report,
        User $admin,
        string $refundReference,
        ?UploadedFile $receipt = null,
        ?string $adminNotes = null,
        ?float $amount = null,
        ?Carbon $refundedAt = null,
    ): OperatorNoShowReport {
        $this->assertRefundOpen($report);

        $booking = $report->booking;
        $reference = trim($refundReference);
        $receiptPath = $receipt?->store('no-show-refund-receipts', 'public');
        $amount = $amount !== null ? round($amount, 2) : (float) ($report->refund_amount ?? $booking?->down_payment_amount ?? 0);
        $refundedAt ??= now();

        $linked = $report->rentalRefundRequest;
        if ($linked instanceof \App\Models\RentalRefundRequest
            && $linked->status !== \App\Models\RentalRefundRequest::STATUS_REFUNDED) {
            // Xendit could not finish: the admin sent it by GCash. Settling the linked request completes this report.
            app(RentalRefundService::class)->recordManualRefund($linked, $admin, $reference, $adminNotes);
            $report->refresh()->update([
                'refund_status' => OperatorNoShowRefundStatus::Refunded,
                'refund_reference' => $reference,
                'refund_receipt_path' => $receiptPath ?? $report->refund_receipt_path,
                'refund_processed_by_user_id' => $admin->id,
                'refund_processed_at' => $refundedAt,
                'refund_amount' => $amount,
                'resolved_at' => $report->resolved_at ?? now(),
            ]);
            $this->notifyDecision($booking, 'operator_no_show_refund_completed', $reference);

            return $report->fresh();
        }

        $notes = 'GCash refund reference: '.$reference;
        if (filled($adminNotes)) {
            $notes .= "\n".trim($adminNotes);
        }

        $report->update([
            'refund_status' => OperatorNoShowRefundStatus::Refunded,
            'refund_reference' => $reference,
            'refund_receipt_path' => $receiptPath,
            'refund_processed_by_user_id' => $admin->id,
            'refund_processed_at' => $refundedAt,
            'refund_amount' => $amount,
            'admin_notes' => trim(($report->admin_notes ? $report->admin_notes."\n" : '').$notes),
            'resolved_at' => now(),
        ]);

        if ($booking instanceof BoatBooking
            && $booking->status !== BoatBookingStatus::Cancelled) {
            $this->cancelBookingForVerifiedOperatorNoShow($booking, $admin);
        }

        $booking?->recordActivity('operator_no_show_refund_completed', $admin, $admin->name);

        if ($booking) {
            $booking->loadMissing('commission');
            if ($booking->commission) {
                app(RentalCommissionService::class)->reverseCommissionForRefund($booking->commission, $admin);
            }
            $this->notifyDecision($booking, 'operator_no_show_refund_completed', $reference);
        }

        return $report->fresh();
    }

    /**
     * Called when the linked Xendit refund succeeds (sync, webhook or manual record).
     */
    public function completeLinkedRefund(\App\Models\RentalRefundRequest $refund, ?User $actor): void
    {
        $booking = BoatBooking::withoutGlobalScope('organization')->find($refund->boat_booking_id);
        if (! $booking instanceof BoatBooking) {
            return;
        }

        $report = $booking->operatorNoShowReports()
            ->where('status', OperatorNoShowReportStatus::Verified)
            ->where('resolution', OperatorNoShowResolution::RefundApproved)
            ->whereNull('resolved_at')
            ->latest('id')
            ->first();

        if (! $report instanceof OperatorNoShowReport) {
            return;
        }

        $report->update([
            'rental_refund_request_id' => $refund->id,
            'refund_status' => OperatorNoShowRefundStatus::Refunded,
            'refund_reference' => $refund->manual_reference ?: 'Xendit refund',
            'refund_processed_by_user_id' => $actor?->id ?? $report->refund_processed_by_user_id,
            'refund_processed_at' => now(),
            'refund_amount' => round(((int) $refund->refund_amount) / 100, 2),
            'resolved_at' => now(),
        ]);

        if ($booking->status !== BoatBookingStatus::Cancelled) {
            $this->cancelBookingForVerifiedOperatorNoShow($booking, $actor);
        }

        $booking->recordActivity('operator_no_show_refund_completed', $actor, $actor?->name ?? 'Xendit');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function replacementOptions(OperatorNoShowReport $report): array
    {
        $booking = $report->booking;
        if (! $booking instanceof BoatBooking) {
            return [];
        }

        $booking->loadMissing('boat');
        $destination = trim((string) ($booking->destination ?: $booking->package));
        $date = $booking->booking_date->toDateString();
        $time = Carbon::parse($booking->start_time)->format('H:i');

        return Boat::query()
            ->where('organization_id', $booking->organization_id)
            ->where('id', '!=', $booking->boat_id)
            ->where('is_active', true)
            ->with(['operator', 'rentalDestinations'])
            ->orderBy('name')
            ->get()
            ->filter(function (Boat $boat) use ($booking, $destination, $date, $time): bool {
                if (in_array($boat->status, [BoatStatus::Maintenance, BoatStatus::Unavailable], true)) {
                    return false;
                }

                if (! $boat->operator?->user_id || $boat->operator->isExclusiveRentalRestricted()) {
                    return false;
                }

                if ((int) $boat->capacity < (int) $booking->passenger_count) {
                    return false;
                }

                if ($destination !== '' && ! $boat->rentalDestinations->contains(
                    fn ($route) => strcasecmp((string) $route->destination, $destination) === 0,
                )) {
                    return false;
                }

                return $boat->slotConflictMessage($date, $time, $booking->id) === null;
            })
            ->map(fn (Boat $boat) => [
                'id' => $boat->id,
                'name' => $boat->name,
                'code' => $boat->code,
                'capacity' => (int) $boat->capacity,
                'operator_name' => $boat->operator?->full_name,
                'status_label' => $boat->status?->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function offerReplacement(OperatorNoShowReport $report, User $admin, int $boatId, ?string $adminNotes = null): OperatorNoShowReport
    {
        $this->assertReplacementOfferable($report);

        if (! collect($this->replacementOptions($report))->contains(fn (array $option) => (int) $option['id'] === $boatId)) {
            throw ValidationException::withMessages([
                'replacement_boat_id' => ['This boat is not available for the same schedule, destination, and passenger count.'],
            ]);
        }

        $boat = Boat::query()->with('operator.user')->findOrFail($boatId);
        $booking = $report->booking;

        $report->update([
            'replacement_offer_status' => OperatorNoShowReport::OFFER_PENDING_OPERATOR,
            'replacement_offer_boat_id' => $boat->id,
            'replacement_offered_by_user_id' => $admin->id,
            'replacement_offered_at' => now(),
            'replacement_responded_at' => null,
            'replacement_decline_reason' => null,
            'admin_notes' => filled($adminNotes) ? trim((string) $adminNotes) : $report->admin_notes,
        ]);

        $booking->recordActivity('operator_no_show_replacement_requested', $admin, $admin->name, [
            'replacement_boat_id' => $boat->id,
            'replacement_boat_name' => $boat->name,
        ]);

        $this->safeNotify($boat->operator?->user, new OperatorNoShowDecisionNotification($booking, 'operator_no_show_replacement_request', $boat->name));

        return $report->fresh();
    }

    public function canOperatorRespondReplacement(BoatBooking $booking, User $user): bool
    {
        if (! $user->isMobileOperator()) {
            return false;
        }

        $report = $this->latestVerifiedOpenReport($booking);

        return $report instanceof OperatorNoShowReport
            && $report->replacement_offer_status === OperatorNoShowReport::OFFER_PENDING_OPERATOR
            && $report->replacement_offer_boat_id
            && Operator::userManagesBoat($user, (int) $report->replacement_offer_boat_id);
    }

    /**
     * @throws ValidationException
     */
    public function respondReplacement(BoatBooking $booking, User $operator, bool $accept, ?string $reason = null): OperatorNoShowReport
    {
        if (! $this->canOperatorRespondReplacement($booking, $operator)) {
            throw ValidationException::withMessages([
                'replacement' => ['There is no replacement request waiting for you on this rental.'],
            ]);
        }

        $report = $this->latestVerifiedOpenReport($booking);
        $report->loadMissing('replacementOfferBoat');

        $report->update([
            'replacement_offer_status' => $accept ? OperatorNoShowReport::OFFER_AWAITING_PASSENGER : OperatorNoShowReport::OFFER_DECLINED,
            'replacement_responded_at' => now(),
            'replacement_decline_reason' => $accept ? null : (filled($reason) ? trim((string) $reason) : null),
        ]);

        $booking->recordActivity(
            $accept ? 'operator_no_show_replacement_accepted' : 'operator_no_show_replacement_declined',
            $operator,
            $operator->name,
        );

        $boatName = $report->replacementOfferBoat?->name;

        if ($accept) {
            $this->safeNotify($booking->user, new OperatorNoShowDecisionNotification($booking, 'operator_no_show_replacement_offered', $boatName));
        }

        $this->admins($booking)->each(fn (User $admin) => $this->safeNotify(
            $admin,
            new OperatorNoShowDecisionNotification(
                $booking,
                $accept ? 'operator_no_show_replacement_accepted' : 'operator_no_show_replacement_declined',
                $accept ? $boatName : ($report->replacement_decline_reason ?: $boatName),
            ),
        ));

        return $report->fresh();
    }

    public function markNoReplacementAvailable(OperatorNoShowReport $report, User $admin, ?string $note = null): OperatorNoShowReport
    {
        $this->assertReplacementOfferable($report);

        $report->update([
            'replacement_offer_status' => OperatorNoShowReport::OFFER_NONE_AVAILABLE,
            'replacement_offer_boat_id' => null,
            'admin_notes' => filled($note) ? trim((string) $note) : $report->admin_notes,
        ]);

        $report->booking?->recordActivity('operator_no_show_no_replacement', $admin, $admin->name);

        if ($report->booking) {
            $this->safeNotify($report->booking->user, new OperatorNoShowDecisionNotification($report->booking, 'operator_no_show_no_replacement'));
        }

        return $report->fresh();
    }

    private function acceptReplacementOffer(BoatBooking $booking, User $passenger, OperatorNoShowReport $report, ?string $note): OperatorNoShowReport
    {
        $report->loadMissing('replacementOfferBoat.operator.user');
        $boat = $report->replacementOfferBoat;

        if (! $boat instanceof Boat) {
            throw ValidationException::withMessages([
                'choice' => ['The replacement boat is no longer available.'],
            ]);
        }

        $conflict = $boat->slotConflictMessage(
            $booking->booking_date->toDateString(),
            Carbon::parse($booking->start_time)->format('H:i'),
            $booking->id,
        );

        if ($conflict) {
            throw ValidationException::withMessages(['choice' => [$conflict]]);
        }

        $oldBoatName = $booking->boat?->name ?? 'Previous boat';

        DB::transaction(function () use ($booking, $passenger, $report, $boat, $note): void {
            $booking->update([
                'boat_id' => $boat->id,
                'status' => BoatBookingStatus::Confirmed,
                'cancellation_reason' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
            ]);

            $report->update([
                'passenger_choice' => OperatorNoShowPassengerChoice::Replacement,
                'passenger_choice_at' => now(),
                'passenger_choice_note' => filled($note) ? trim((string) $note) : null,
                'replacement_offer_status' => OperatorNoShowReport::OFFER_ACCEPTED,
                'replacement_boat_id' => $boat->id,
                'resolution' => OperatorNoShowResolution::Reassigned,
                'resolved_at' => now(),
            ]);
        });

        $booking->recordActivity(
            'operator_no_show_reassigned',
            $passenger,
            sprintf('%s accepted the replacement: %s → %s', $passenger->name, $oldBoatName, $boat->name),
        );

        $fresh = $booking->fresh(['boat']);
        $this->safeNotify($booking->user, new OperatorNoShowDecisionNotification($fresh, 'operator_no_show_reassigned', $boat->name));
        $this->safeNotify($boat->operator?->user, new OperatorNoShowDecisionNotification($fresh, 'operator_no_show_replacement_confirmed', $boat->name));
        $this->admins($booking)->each(fn (User $admin) => $this->safeNotify(
            $admin,
            new OperatorNoShowDecisionNotification($fresh, 'operator_no_show_replacement_confirmed', $boat->name),
        ));

        return $report->fresh();
    }

    private function assertReplacementOfferable(OperatorNoShowReport $report): void
    {
        if ($report->status !== OperatorNoShowReportStatus::Verified || $report->resolved_at !== null) {
            throw ValidationException::withMessages([
                'report' => ['Confirm the no-show before arranging a replacement.'],
            ]);
        }

        if (! in_array($report->passenger_choice, [null, OperatorNoShowPassengerChoice::Replacement], true)) {
            throw ValidationException::withMessages([
                'report' => ['The passenger already chose a refund or a new date.'],
            ]);
        }

        if (in_array($report->replacement_offer_status, [OperatorNoShowReport::OFFER_PENDING_OPERATOR, OperatorNoShowReport::OFFER_AWAITING_PASSENGER, OperatorNoShowReport::OFFER_ACCEPTED], true)) {
            throw ValidationException::withMessages([
                'report' => ['A replacement offer is already in progress.'],
            ]);
        }
    }

    /**
     * @return list<int>
     */
    public function pendingReplacementBookingIdsForOperator(User $operatorUser): array
    {
        if (! $operatorUser->isMobileOperator()) {
            return [];
        }

        $boatIds = Operator::query()->where('user_id', $operatorUser->id)->pluck('boat_id');

        if ($boatIds->isEmpty()) {
            return [];
        }

        return OperatorNoShowReport::query()
            ->where('status', OperatorNoShowReportStatus::Verified)
            ->whereNull('resolved_at')
            ->where('replacement_offer_status', OperatorNoShowReport::OFFER_PENDING_OPERATOR)
            ->whereIn('replacement_offer_boat_id', $boatIds)
            ->pluck('boat_booking_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function admins(BoatBooking $booking)
    {
        return User::query()
            ->where('organization_id', $booking->organization_id)
            ->whereIn('role', [UserRole::Administrator, UserRole::User])
            ->where('is_active', true)
            ->get();
    }

    private function safeNotify(?User $user, object $notification): void
    {
        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function assertPassengerChoice(
        OperatorNoShowReport $report,
        OperatorNoShowPassengerChoice $expected,
    ): void {
        if ($report->passenger_choice !== $expected) {
            throw ValidationException::withMessages([
                'report' => ['Wait for the passenger to select this resolution option before proceeding.'],
            ]);
        }
    }

    private function notifyAdminsPassengerChoice(BoatBooking $booking, OperatorNoShowReport $report): void
    {
        User::query()
            ->where('organization_id', $booking->organization_id)
            ->whereIn('role', [UserRole::Administrator, UserRole::User])
            ->where('is_active', true)
            ->get()
            ->each(function (User $admin) use ($booking, $report): void {
                try {
                    $admin->notify(new OperatorNoShowReportedNotification($booking));
                } catch (\Throwable) {
                }
            });
    }

    public function syncRescheduleResolution(OperatorNoShowReport $report): void
    {
        $this->syncBookingStatusForOperatorReschedule($report);

        if ($report->resolution !== OperatorNoShowResolution::RescheduleOffered
            || $report->resolved_at !== null) {
            return;
        }

        if ($report->rescheduleIsComplete()) {
            $report->update(['resolved_at' => now()]);
        }
    }

    public function syncBookingStatusForOperatorReschedule(OperatorNoShowReport $report): void
    {
        $booking = $report->booking;
        if (! $booking) {
            return;
        }

        $awaitingOperator = $report->status === OperatorNoShowReportStatus::Verified
            && $report->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
            && $report->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator
            && $report->resolved_at === null;

        if ($awaitingOperator && in_array($booking->status, [
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
            BoatBookingStatus::Cancelled,
        ], true)) {
            if ($booking->status !== BoatBookingStatus::ReschedulePending) {
                $booking->update(['status' => BoatBookingStatus::ReschedulePending]);
            }

            return;
        }

        if ($report->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
            && $report->reschedule_status === OperatorNoShowRescheduleStatus::Rejected
            && $report->resolved_at === null
            && in_array($booking->status, [
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Cancelled,
            ], true)) {
            $booking->update(['status' => BoatBookingStatus::ReschedulePending]);
        }
    }

    /**
     * Canonical no-show reschedule workflow phase for clients (null when not in reschedule path).
     */
    public function operatorNoShowReschedulePhase(BoatBooking $booking): ?string
    {
        $report = $this->latestVerifiedOpenReport($booking);
        if (! $report instanceof OperatorNoShowReport
            || $report->passenger_choice !== OperatorNoShowPassengerChoice::Reschedule
            || $report->resolved_at !== null) {
            return null;
        }

        return match ($report->reschedule_status) {
            OperatorNoShowRescheduleStatus::PendingOperator => 'awaiting_operator',
            OperatorNoShowRescheduleStatus::Rejected => 'operator_declined',
            default => $report->reschedule_boat_id ? null : 'awaiting_passenger_details',
        };
    }

    /**
     * @return array{status: BoatBookingStatus, mobile: string, label: string, phase: ?string}
     */
    public function bookingDisplayStatus(BoatBooking $booking, ?User $viewer = null): array
    {
        $booking->loadMissing(['boat.operator']);
        $stored = $booking->status;
        $report = $this->latestVerifiedOpenReport($booking);
        $phase = $this->operatorNoShowReschedulePhase($booking);
        $effective = $stored;

        if ($stored === BoatBookingStatus::WeatherHold) {
            if ($booking->hasOpenWeatherRescheduleRequest()) {
                return [
                    'status' => BoatBookingStatus::ReschedulePending,
                    'mobile' => $this->mobileStatusValue(BoatBookingStatus::ReschedulePending),
                    'label' => BoatBookingStatus::ReschedulePending->label(),
                    'phase' => $phase,
                ];
            }

            return [
                'status' => $effective,
                'mobile' => $this->mobileStatusValue($effective),
                'label' => $effective->label(),
                'phase' => $phase,
            ];
        }

        if ($report instanceof OperatorNoShowReport && $report->resolved_at === null) {
            $awaitingOperator = $report->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator;
            $reschedulePath = $report->passenger_choice === OperatorNoShowPassengerChoice::Reschedule
                && $report->reschedule_status !== OperatorNoShowRescheduleStatus::Approved;

            if ($awaitingOperator || $reschedulePath) {
                if (in_array($stored, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)) {
                    $effective = BoatBookingStatus::ReschedulePending;
                }
            } elseif ($viewer?->isMobileOperator()
                && (int) $booking->boat?->operator?->user_id === (int) $viewer->id
                && $report->status === OperatorNoShowReportStatus::Verified
                && $report->passenger_choice === null
                && in_array($stored, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)) {
                $effective = BoatBookingStatus::ReschedulePending;
            }
        }

        return [
            'status' => $effective,
            'mobile' => $this->mobileStatusValue($effective),
            'label' => $effective === BoatBookingStatus::Cancelled && $booking->cancellation_reason === self::CANCELLATION_REASON
                ? self::CANCELLATION_REASON
                : $effective->label(),
            'phase' => $phase,
        ];
    }

    public function mobileStatusValue(BoatBookingStatus $status): string
    {
        return match ($status) {
            BoatBookingStatus::Pending => 'pending',
            BoatBookingStatus::AwaitingPayment => 'awaiting_payment',
            BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing => 'approved',
            BoatBookingStatus::WeatherHold => 'weather_hold',
            BoatBookingStatus::ReschedulePending => 'reschedule_pending',
            BoatBookingStatus::Completed => 'completed',
            BoatBookingStatus::PassengerNoShow => 'passenger_no_show',
            BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected => 'cancelled',
        };
    }

    public function syncPendingOperatorRescheduleBookingStatuses(): void
    {
        $this->expireStalePendingOperatorReschedules();

        OperatorNoShowReport::query()
            ->where('status', OperatorNoShowReportStatus::Verified)
            ->where('passenger_choice', OperatorNoShowPassengerChoice::Reschedule)
            ->where('reschedule_status', OperatorNoShowRescheduleStatus::PendingOperator)
            ->whereNull('resolved_at')
            ->with('booking')
            ->each(fn (OperatorNoShowReport $report) => $this->syncBookingStatusForOperatorReschedule($report));
    }

    /**
     * @return list<int> Operator no-show report IDs auto-declined
     */
    public function expireStalePendingOperatorReschedules(?Carbon $now = null): array
    {
        $now = $now ?? now();
        $cutoff = $now->copy()->subMinutes(self::OPERATOR_RESCHEDULE_CONFIRMATION_DEADLINE_MINUTES);

        $reports = OperatorNoShowReport::query()
            ->where('status', OperatorNoShowReportStatus::Verified)
            ->where('passenger_choice', OperatorNoShowPassengerChoice::Reschedule)
            ->where('reschedule_status', OperatorNoShowRescheduleStatus::PendingOperator)
            ->whereNull('resolved_at')
            ->where('updated_at', '<=', $cutoff)
            ->with(['booking', 'rescheduleBoat.operator.user'])
            ->orderBy('id')
            ->get();

        $expiredIds = [];

        foreach ($reports as $report) {
            if (! $report->booking instanceof BoatBooking) {
                continue;
            }

            $this->systemRejectPendingOperatorReschedule($report);
            $expiredIds[] = $report->id;
        }

        return $expiredIds;
    }

    private function systemRejectPendingOperatorReschedule(OperatorNoShowReport $report): void
    {
        if ($report->reschedule_status !== OperatorNoShowRescheduleStatus::PendingOperator) {
            return;
        }

        $this->rejectPendingOperatorReschedule(
            $report,
            self::AUTO_REJECT_OPERATOR_RESCHEDULE_REASON,
            null,
            'operator_no_show_reschedule_expired',
            'operator_no_show_reschedule_expired',
        );
    }

    public function adminRejectPendingOperatorReschedule(
        OperatorNoShowReport $report,
        User $admin,
        ?string $adminNotes = null,
    ): OperatorNoShowReport {
        if ($report->status !== OperatorNoShowReportStatus::Verified
            || $report->passenger_choice !== OperatorNoShowPassengerChoice::Reschedule
            || $report->reschedule_status !== OperatorNoShowRescheduleStatus::PendingOperator
            || $report->resolved_at !== null) {
            throw ValidationException::withMessages([
                'report' => ['There is no reschedule request waiting for operator confirmation.'],
            ]);
        }

        $note = filled($adminNotes)
            ? trim($adminNotes)
            : 'Barangay admin declined the reschedule request.';

        $this->rejectPendingOperatorReschedule(
            $report,
            $note,
            $admin,
            'operator_no_show_reschedule_rejected',
            'operator_no_show_reschedule_rejected',
        );

        return $report->fresh();
    }

    /**
     * @param  'operator_no_show_reschedule_expired'|'operator_no_show_reschedule_rejected'  $activityEvent
     * @param  'operator_no_show_reschedule_expired'|'operator_no_show_reschedule_rejected'  $operatorNotifyEvent
     */
    private function rejectPendingOperatorReschedule(
        OperatorNoShowReport $report,
        string $note,
        ?User $actor,
        string $activityEvent,
        string $operatorNotifyEvent,
    ): void {
        $booking = $report->booking;
        if (! $booking instanceof BoatBooking) {
            throw ValidationException::withMessages([
                'report' => ['This incident is not linked to a booking.'],
            ]);
        }

        $report->update([
            'reschedule_status' => OperatorNoShowRescheduleStatus::Rejected,
            'admin_notes' => filled($report->admin_notes)
                ? $report->admin_notes."\n\n".$note
                : $note,
        ]);

        if ($booking->status !== BoatBookingStatus::ReschedulePending) {
            $booking->update(['status' => BoatBookingStatus::ReschedulePending]);
        }

        $booking->recordActivity(
            $activityEvent,
            $actor,
            $actor?->name ?? $note,
        );
        $this->notifyDecision($booking, 'operator_no_show_reschedule_rejected', $note);

        $replacementOperator = $report->rescheduleBoat?->operator?->user;
        if ($replacementOperator instanceof User) {
            try {
                $replacementOperator->notify(new OperatorNoShowDecisionNotification($booking, $operatorNotifyEvent, $note));
            } catch (\Throwable) {
            }
        }
    }

    private function cancelBookingForVerifiedOperatorNoShow(BoatBooking $booking, ?User $admin): void
    {
        if (in_array($booking->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Completed,
            BoatBookingStatus::PassengerNoShow,
        ], true)) {
            return;
        }

        $booking->update([
            'status' => BoatBookingStatus::Cancelled,
            'cancellation_reason' => self::CANCELLATION_REASON,
            'cancelled_at' => now(),
        ]);

        $booking->recordActivity('cancelled', $admin, self::CANCELLATION_REASON);
    }

    private function markBookingAwaitingOperatorReschedule(BoatBooking $booking, User $actor): void
    {
        if ($booking->status !== BoatBookingStatus::ReschedulePending) {
            $booking->update(['status' => BoatBookingStatus::ReschedulePending]);
            $booking->recordActivity(
                'operator_no_show_reschedule_pending_operator',
                $actor,
                'Awaiting confirmation from the replacement boat operator before the trip is confirmed again.',
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rescheduleBoatOptions(BoatBooking $booking, ?string $onDate = null): array
    {
        $report = $this->latestVerifiedOpenReport($booking);
        if (! $report instanceof OperatorNoShowReport) {
            throw ValidationException::withMessages([
                'booking' => ['No verified operator no-show incident is open for this rental.'],
            ]);
        }

        if (! $this->canPassengerSubmitReschedule($booking, $report)) {
            throw ValidationException::withMessages([
                'booking' => ['You cannot request another reschedule for this incident right now.'],
            ]);
        }

        $targetPrice = (float) $booking->rental_price;
        $destination = $booking->destination ?: $booking->package;

        return Boat::query()
            ->with(['pictures', 'rentalDestinations'])
            ->where('organization_id', $booking->organization_id)
            ->where('is_active', true)
            ->whereIn('status', [BoatStatus::OnDuty, BoatStatus::Available])
            ->where('capacity', '>=', $booking->passenger_count)
            ->orderBy('name')
            ->get()
            ->filter(fn (Boat $boat) => $boat->isListedForPrivateBooking()
                && $this->boatRentalPriceForDestination($boat, $destination) === $targetPrice
                && ($onDate === null || ((int) $boat->id !== (int) $booking->boat_id
                    && $boat->slotConflictMessage($onDate, null, $booking->id) === null)))
            ->map(fn (Boat $boat) => [
                'id' => $boat->id,
                'name' => $boat->name,
                'code' => $boat->code,
                'capacity' => $boat->capacity,
                'picture_url' => $boat->pictureUrl(),
                'rental_price_label' => OrganizationRoute::formatPeso($targetPrice),
            ])
            ->values()
            ->all();
    }

    public function passengerSubmitRefundRequest(
        BoatBooking $booking,
        User $passenger,
        string $accountName,
        string $mobile,
        ?UploadedFile $qr = null,
    ): OperatorNoShowReport {
        $report = $this->requireVerifiedOpenReport($booking, $passenger);

        if ($report->passenger_choice === OperatorNoShowPassengerChoice::Refund
            && $report->refund_status !== null
            && $report->refund_status !== OperatorNoShowRefundStatus::Rejected) {
            throw ValidationException::withMessages([
                'gcash_mobile' => ['A refund request is already on file for this incident.'],
            ]);
        }

        $qrPath = $qr?->store('no-show-refund-qr', 'public');

        $report->update([
            'passenger_choice' => OperatorNoShowPassengerChoice::Refund,
            'passenger_choice_at' => now(),
            'refund_status' => OperatorNoShowRefundStatus::Pending,
            'refund_amount' => $booking->down_payment_amount,
            'refund_gcash_account_name' => trim($accountName),
            'refund_gcash_mobile' => trim($mobile),
            'refund_gcash_qr_path' => $qrPath ?? $report->refund_gcash_qr_path,
            'refund_requested_at' => now(),
        ]);

        $booking->recordActivity('operator_no_show_passenger_chose_refund', $passenger, $passenger->name);
        $this->notifyDecision($booking, 'operator_no_show_passenger_chose_refund');
        $this->notifyAdminsPassengerChoice($booking, $report);

        return $report->fresh();
    }

    public function passengerSubmitRescheduleRequest(
        BoatBooking $booking,
        User $passenger,
        int $boatId,
        string $bookingDate,
        string $startTime,
    ): OperatorNoShowReport {
        $report = $this->requireVerifiedOpenReport($booking, $passenger);

        if (! $this->canPassengerSubmitReschedule($booking, $report)) {
            throw ValidationException::withMessages([
                'booking_date' => ['You cannot request a reschedule for this incident right now.'],
            ]);
        }

        $boat = Boat::query()->findOrFail($boatId);
        $time = Carbon::parse($startTime)->format('H:i');
        $options = collect($this->rescheduleBoatOptions($booking));
        if (! $options->contains(fn (array $row) => (int) $row['id'] === (int) $boat->id)) {
            throw ValidationException::withMessages([
                'boat_id' => ['This boat is not available for your reschedule request.'],
            ]);
        }

        $conflict = $boat->slotConflictMessage($bookingDate, $time, $booking->id);
        if ($conflict) {
            throw ValidationException::withMessages([
                'start_time' => [$conflict],
            ]);
        }

        $weather = app(\App\Services\WeatherSuspensionService::class);
        $weatherBlock = $weather->rentalBlockMessage((int) $booking->organization_id, $bookingDate);
        if ($weatherBlock) {
            throw ValidationException::withMessages([
                'booking_date' => [$weatherBlock],
            ]);
        }

        $report->update([
            'passenger_choice' => OperatorNoShowPassengerChoice::Reschedule,
            'passenger_choice_at' => now(),
            'reschedule_boat_id' => $boat->id,
            'preferred_booking_date' => $bookingDate,
            'preferred_start_time' => $time,
            'reschedule_status' => OperatorNoShowRescheduleStatus::PendingOperator,
        ]);

        $this->markBookingAwaitingOperatorReschedule($booking, $passenger);

        $booking->recordActivity('operator_no_show_passenger_chose_reschedule', $passenger, $passenger->name);
        $this->notifyDecision($booking, 'operator_no_show_passenger_chose_reschedule');
        $this->notifyAdminsPassengerChoice($booking, $report);

        $boat->loadMissing('operator.user');
        $targetOperator = $boat->operator?->user;
        if ($targetOperator instanceof User) {
            try {
                $targetOperator->notify(new OperatorNoShowDecisionNotification(
                    $booking,
                    'operator_no_show_reschedule_needs_confirmation',
                ));
            } catch (\Throwable) {
            }
        }

        return $report->fresh();
    }

    public function operatorRespondReschedule(
        BoatBooking $booking,
        User $operator,
        bool $approve,
        ?string $note = null,
    ): OperatorNoShowReport {
        $report = $this->latestVerifiedOpenReport($booking);
        if (! $report instanceof OperatorNoShowReport
            || $report->passenger_choice !== OperatorNoShowPassengerChoice::Reschedule
            || $report->reschedule_status !== OperatorNoShowRescheduleStatus::PendingOperator) {
            throw ValidationException::withMessages([
                'action' => ['There is no pending reschedule request for this rental.'],
            ]);
        }

        $boat = Boat::query()->with('operator')->findOrFail($report->reschedule_boat_id);
        if ((int) $boat->operator?->user_id !== (int) $operator->id) {
            throw ValidationException::withMessages([
                'action' => ['Only the assigned operator for the selected boat can respond.'],
            ]);
        }

        if (! $approve) {
            $report->update([
                'reschedule_status' => OperatorNoShowRescheduleStatus::Rejected,
                'admin_notes' => filled($note) ? trim($note) : $report->admin_notes,
            ]);

            if ($booking->status !== BoatBookingStatus::ReschedulePending) {
                $booking->update(['status' => BoatBookingStatus::ReschedulePending]);
            }

            $booking->recordActivity(
                'operator_no_show_reschedule_rejected',
                $operator,
                filled($note) ? trim($note) : 'Replacement operator declined the reschedule request.',
            );
            $this->notifyDecision($booking, 'operator_no_show_reschedule_rejected');

            return $report->fresh();
        }

        $date = $report->preferred_booking_date?->toDateString();
        $time = $report->preferred_start_time
            ? Carbon::parse($report->preferred_start_time)->format('H:i')
            : null;
        if (! $date || ! $time) {
            throw ValidationException::withMessages([
                'action' => ['The passenger schedule details are missing.'],
            ]);
        }

        $conflict = $boat->slotConflictMessage($date, $time, $booking->id);
        if ($conflict) {
            throw ValidationException::withMessages([
                'action' => [$conflict],
            ]);
        }

        return DB::transaction(function () use ($booking, $report, $boat, $date, $time, $operator, $note) {
            $previousBoatName = $booking->boat?->name ?? 'Previous boat';
            $downpaymentLabel = $booking->down_payment_amount !== null
                ? OrganizationRoute::formatPeso($booking->down_payment_amount)
                : null;

            $booking->update([
                'boat_id' => $boat->id,
                'booking_date' => $date,
                'start_time' => $time,
                'status' => BoatBookingStatus::Confirmed,
            ]);

            $report->update([
                'reschedule_status' => OperatorNoShowRescheduleStatus::Approved,
                'resolution' => OperatorNoShowResolution::RescheduleOffered,
                'resolved_at' => now(),
                'admin_notes' => filled($note) ? trim($note) : $report->admin_notes,
            ]);

            $activityDetail = 'Rescheduled on '.$boat->name.' · '.$date.' '.$time;
            if ($downpaymentLabel) {
                $activityDetail .= ' · Downpayment '.$downpaymentLabel.' retained on this booking (was '.$previousBoatName.')';
            }
            $booking->recordActivity('operator_no_show_reschedule_approved', $operator, $activityDetail);
            app(RentalCommissionService::class)->syncOperatorAfterBoatChange($booking->fresh(), $operator);
            $this->notifyDecision($booking, 'operator_no_show_reschedule_approved');

            return $report->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function mobileIncidentPayload(BoatBooking $booking, User $viewer): ?array
    {
        $report = $booking->operatorNoShowReports()->latest('id')->first();
        if (! $report) {
            return null;
        }

        $report->loadMissing(['rescheduleBoat', 'replacementOfferBoat.operator', 'rentalRefundRequest']);

        $tracking = $report->passengerTracking();

        return [
            'report_id' => $report->id,
            'booking_code' => $booking->code,
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'incident_status' => $report->incidentDisplayStatus()->value,
            'incident_status_label' => $report->incidentDisplayStatus()->label(),
            'passenger_status' => $tracking['value'],
            'passenger_status_label' => $tracking['label'],
            'passenger_message' => $tracking['message'],
            'passenger_status_tone' => $tracking['tone'],
            'reported_at_label' => $report->reported_at?->format('M d, Y · g:i A'),
            'rejection_reason' => $report->status === OperatorNoShowReportStatus::Dismissed
                ? $report->admin_notes
                : null,
            'passenger_choice' => $report->passenger_choice?->value,
            'passenger_choice_label' => $report->passenger_choice?->label(),
            'refund_status' => $report->refund_status?->value,
            'refund_status_label' => $report->refund_status?->workflowLabel(),
            'refund_reference' => $report->refund_reference,
            'refund_requested_at_label' => $report->refund_requested_at?->format('M d, Y · g:i A'),
            'refund_processed_at_label' => $report->refund_processed_at?->format('M d, Y · g:i A'),
            'refund_receipt_url' => $report->refund_status === OperatorNoShowRefundStatus::Refunded
                && filled($report->refund_receipt_path)
                ? '/api/bookings/'.$booking->id.'/no-show/refund-receipt'
                : null,
            'refundable_downpayment_label' => $booking->down_payment_amount !== null
                ? OrganizationRoute::formatPeso($booking->down_payment_amount)
                : null,
            'refund_amount_label' => $report->refund_amount !== null
                ? OrganizationRoute::formatPeso($report->refund_amount)
                : null,
            'commission_deduction_label' => OrganizationRoute::formatPeso(0),
            'refund_gcash_account_name' => $report->refund_gcash_account_name,
            'refund_gcash_mobile' => $report->refund_gcash_mobile,
            'preferred_booking_date' => $report->preferred_booking_date?->toDateString(),
            'preferred_booking_date_label' => $report->preferred_booking_date?->format('M d, Y'),
            'preferred_start_time' => $report->preferred_start_time,
            'preferred_start_time_label' => $report->preferred_start_time
                ? Carbon::parse($report->preferred_start_time)->format('g:i A')
                : null,
            'reschedule_boat_name' => $report->rescheduleBoat?->name,
            'reschedule_status' => $report->reschedule_status?->value,
            'reschedule_status_label' => $report->reschedule_status?->label(),
            'reschedule_phase' => $this->operatorNoShowReschedulePhase($booking),
            'can_operator_respond_reschedule' => $this->canOperatorRespondReschedule($booking, $viewer),
            'operator_reschedule_prompt' => $this->canOperatorRespondReschedule($booking, $viewer)
                ? 'A passenger chose your boat for a new schedule after a no-show on another boat. Approve or decline this request in the operator app.'
                : null,
            'report_reason' => $report->report_reason,
            'report_reason_label' => $report->reasonLabel(),
            'replacement_offer_status' => $report->replacement_offer_status,
            'replacement_offer_label' => $report->replacementOfferLabel(),
            'replacement_offer' => $report->replacement_offer_boat_id && in_array($report->replacement_offer_status, [
                OperatorNoShowReport::OFFER_PENDING_OPERATOR,
                OperatorNoShowReport::OFFER_AWAITING_PASSENGER,
                OperatorNoShowReport::OFFER_ACCEPTED,
            ], true) ? [
                'boat_name' => $report->replacementOfferBoat?->name,
                'boat_code' => $report->replacementOfferBoat?->code,
                'boat_capacity' => $report->replacementOfferBoat?->capacity,
                'operator_name' => $report->replacementOfferBoat?->operator?->full_name,
                'booking_date_label' => $booking->booking_date?->format('M d, Y'),
                'start_time_label' => $booking->start_time ? Carbon::parse($booking->start_time)->format('g:i A') : null,
                'destination' => $booking->destination ?: $booking->package,
                'price_change_label' => 'No extra charge',
            ] : null,
            'can_accept_replacement' => (int) $booking->user_id === (int) $viewer->id
                && $report->status === OperatorNoShowReportStatus::Verified
                && $report->resolved_at === null
                && in_array($report->passenger_choice, [null, OperatorNoShowPassengerChoice::Replacement], true)
                && $report->replacement_offer_status === OperatorNoShowReport::OFFER_AWAITING_PASSENGER,
            'can_operator_respond_replacement' => $this->canOperatorRespondReplacement($booking, $viewer),
            'refund_via_xendit' => app(RentalRefundService::class)->paidOnline($booking) > 0
                || $report->rental_refund_request_id !== null,
            'xendit_refund_status_label' => $report->rentalRefundRequest?->statusLabel(),
            'can_choose_resolution' => $this->canPassengerChooseResolution($booking, $viewer),
            'can_submit_refund_request' => $this->canPassengerSubmitRefund($booking, $report, $viewer),
            'can_submit_reschedule_request' => $this->canPassengerSubmitReschedule($booking, $report),
            'choose_resolution_message' => $this->canPassengerChooseResolution($booking, $viewer)
                ? 'Barangay confirmed the boat did not arrive. Choose one: a new date (down payment stays) or return the down payment on GCash.'
                : null,
        ];
    }

    private function requireVerifiedOpenReport(BoatBooking $booking, User $passenger): OperatorNoShowReport
    {
        if ((int) $booking->user_id !== (int) $passenger->id) {
            throw ValidationException::withMessages([
                'booking' => ['You can only update your own rental incident.'],
            ]);
        }

        $report = $this->latestVerifiedOpenReport($booking);
        if (! $report instanceof OperatorNoShowReport) {
            throw ValidationException::withMessages([
                'booking' => ['No verified operator no-show incident is open for this rental.'],
            ]);
        }

        return $report;
    }

    private function canPassengerSubmitRefund(BoatBooking $booking, OperatorNoShowReport $report, User $viewer): bool
    {
        if ((int) $booking->user_id !== (int) $viewer->id) {
            return false;
        }

        if ($report->status !== OperatorNoShowReportStatus::Verified || $report->resolved_at !== null) {
            return false;
        }

        if ($report->passenger_choice === null) {
            return true;
        }

        if ($report->passenger_choice === OperatorNoShowPassengerChoice::Refund
            && $report->refund_status === OperatorNoShowRefundStatus::Rejected) {
            return true;
        }

        return false;
    }

    private function canPassengerSubmitReschedule(BoatBooking $booking, OperatorNoShowReport $report): bool
    {
        if ($report->status !== OperatorNoShowReportStatus::Verified || $report->resolved_at !== null) {
            return false;
        }

        if ($report->passenger_choice === null) {
            return true;
        }

        if ($report->passenger_choice !== OperatorNoShowPassengerChoice::Reschedule) {
            return false;
        }

        if ($report->reschedule_status === OperatorNoShowRescheduleStatus::PendingOperator) {
            return false;
        }

        if ($report->reschedule_status === OperatorNoShowRescheduleStatus::Approved) {
            return false;
        }

        return $report->reschedule_status === null
            || $report->reschedule_status === OperatorNoShowRescheduleStatus::Rejected;
    }

    private function boatRentalPriceForDestination(Boat $boat, string $destination): ?float
    {
        $route = $boat->rentalDestinations
            ->first(fn ($row) => strcasecmp((string) $row->destination, $destination) === 0
                && $row->isExclusiveBoatRental()
                && $row->is_active);

        if (! $route) {
            return null;
        }

        return (float) $route->rental_price;
    }

    private function assertPending(OperatorNoShowReport $report): void
    {
        if ($report->status !== OperatorNoShowReportStatus::Pending) {
            throw ValidationException::withMessages([
                'report' => ['This report was already reviewed.'],
            ]);
        }
    }

    private function notifyDecision(BoatBooking $booking, string $event, ?string $detail = null): void
    {
        $booking->loadMissing(['user', 'boat.operator.user']);
        $notice = new OperatorNoShowDecisionNotification($booking, $event, $detail);

        foreach ([$booking->user, $booking->boat?->operator?->user] as $user) {
            if (! $user instanceof User) {
                continue;
            }
            try {
                $user->notify($notice);
            } catch (\Throwable) {
            }
        }
    }

    private function notifyOperatorNoShowReport(BoatBooking $booking, User $passenger): void
    {
        $booking->loadMissing('boat.operator.user');
        $recipients = collect();

        $operator = $booking->boat?->operator?->user;
        if ($operator instanceof User) {
            $recipients->push($operator);
        }

        User::query()
            ->where('organization_id', $booking->organization_id)
            ->whereIn('role', [UserRole::Administrator, UserRole::User])
            ->where('is_active', true)
            ->get()
            ->each(fn (User $admin) => $recipients->push($admin));

        $recipients
            ->unique('id')
            ->reject(fn (User $user) => (int) $user->id === (int) $passenger->id)
            ->each(function (User $user) use ($booking): void {
                try {
                    $user->notify(new OperatorNoShowReportedNotification($booking));
                } catch (\Throwable) {
                    // Keep the report even if a notification channel is unavailable.
                }
            });
    }

    public function operatorGraceMinutes(BoatBooking $booking): int
    {
        return \App\Models\Organization::noShowGraceFor((int) $booking->organization_id, 'operator');
    }

    public function reportOpensAt(BoatBooking $booking): ?Carbon
    {
        if (! $booking->booking_date || ! $booking->start_time) {
            return null;
        }

        return Carbon::parse(
            $booking->booking_date->toDateString().' '.Carbon::parse($booking->start_time)->format('H:i'),
            'Asia/Manila',
        )->addMinutes($this->operatorGraceMinutes($booking));
    }

    private function graceHasPassed(BoatBooking $booking, int $minutes): bool
    {
        $time = Carbon::parse($booking->start_time)->format('H:i');
        $start = Carbon::parse(
            $booking->booking_date->toDateString().' '.$time,
            'Asia/Manila',
        );

        return now('Asia/Manila')->greaterThanOrEqualTo($start->copy()->addMinutes($minutes));
    }

    public function acknowledgePassengerNoShow(BoatBooking $booking, User $admin): void
    {
        if ($booking->status !== BoatBookingStatus::PassengerNoShow) {
            abort(422, 'This booking is not marked as a passenger no-show.');
        }

        if ($booking->passenger_no_show_admin_acknowledged_at !== null) {
            abort(422, 'This passenger no-show was already reviewed.');
        }

        $booking->update([
            'passenger_no_show_admin_acknowledged_at' => now(),
            'passenger_no_show_admin_acknowledged_by' => $admin->id,
        ]);

        $booking->recordActivity('passenger_no_show_admin_acknowledged', $admin, $admin->name);
    }
}

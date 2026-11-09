<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\PassengerAttendanceStatus;
use App\Enums\UserRole;
use App\Models\BoatBooking;
use App\Models\Organization;
use App\Models\PassengerNoShowReport;
use App\Models\RentalRefundRequest;
use App\Models\User;
use App\Notifications\PassengerNoShowNotification;
use App\Notifications\TripConfirmationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Operator-reported passenger no-shows: validated by the backend, cancel the rental without admin approval,
 * never refund automatically, and stay open to a passenger dispute for 1 day. If the passenger does not
 * dispute in time, the no-show is auto-finalized. Disputed cases are resolved by the barangay admin.
 */
class PassengerNoShowService
{
    /** Calendar days (Manila) after the operator report during which the passenger may dispute. */
    public const DISPUTE_WINDOW_DAYS = 1;

    public function trips(): TripConfirmationService
    {
        return app(TripConfirmationService::class);
    }

    public function disputeDeadlineAt(PassengerNoShowReport $report): ?Carbon
    {
        return $report->disputeDeadlineAt();
    }

    public function disputeWindowOpen(PassengerNoShowReport $report): bool
    {
        $deadline = $report->disputeDeadlineAt();

        return $report->status === PassengerNoShowReport::STATUS_CONFIRMED
            && $deadline !== null
            && now('Asia/Manila')->lt($deadline);
    }

    public function graceMinutes(BoatBooking $booking): int
    {
        return Organization::noShowGraceFor((int) $booking->organization_id, 'passenger');
    }

    public function opensAt(BoatBooking $booking): ?Carbon
    {
        return $this->trips()->waitingPeriodEndsAt($booking);
    }

    public function blockedReason(BoatBooking $booking, User $operator, bool $wholeBooking = true): ?string
    {
        $booking->loadMissing(['boat.operator', 'passengers']);

        if (! $operator->isMobileOperator() || (int) $booking->boat?->operator?->user_id !== (int) $operator->id) {
            return 'Only the assigned boat operator can report a passenger no-show.';
        }

        if ($booking->passengerNoShowReport()->exists() || $booking->status === BoatBookingStatus::PassengerNoShow) {
            return 'This passenger was already reported as a no-show.';
        }

        if ($booking->status === BoatBookingStatus::Completed) {
            return 'Completed rentals cannot be reported as a passenger no-show.';
        }

        if (in_array($booking->status, [BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected], true)) {
            return 'Cancelled rentals cannot be reported as a passenger no-show.';
        }

        if ($booking->status !== BoatBookingStatus::Confirmed) {
            return 'Only confirmed rentals that have not started can be reported.';
        }

        if (now('Asia/Manila')->toDateString() !== $booking->booking_date?->toDateString()) {
            return 'A passenger no-show can only be reported on the trip date.';
        }

        $opensAt = $this->opensAt($booking);
        if (! $opensAt || now('Asia/Manila')->lt($opensAt)) {
            return 'You can report a passenger no-show from '.($opensAt?->format('g:i A') ?? 'the end of the waiting period')
                .' ('.$this->graceMinutes($booking).' minutes after the scheduled departure).';
        }

        if ($wholeBooking && $booking->passengers->contains(
            fn ($passenger) => $passenger->attendance_status === PassengerAttendanceStatus::Arrived,
        )) {
            return 'A passenger already checked in, so this trip has started.';
        }

        $openOperatorIncident = $booking->operatorNoShowReports()
            ->where(function ($query): void {
                $query->where('status', OperatorNoShowReportStatus::Pending)
                    ->orWhere(fn ($inner) => $inner->where('status', OperatorNoShowReportStatus::Verified)->whereNull('resolved_at'));
            })
            ->exists();

        if ($openOperatorIncident) {
            return 'The passenger reported the boat as a no-show. Wait for the barangay to review it first.';
        }

        return null;
    }

    public function canReport(BoatBooking $booking, User $operator): bool
    {
        return $this->blockedReason($booking, $operator) === null;
    }

    /**
     * @throws ValidationException
     */
    public function report(
        BoatBooking $booking,
        User $operator,
        bool $declarationConfirmed,
        ?string $remarks = null,
        ?string $evidencePhotoPath = null,
    ): BoatBooking {
        if (! $declarationConfirmed) {
            throw ValidationException::withMessages([
                'declaration_confirmed' => ['Confirm that the boat and operator were ready at the pickup location and the waiting period passed.'],
            ]);
        }

        if ($reason = $this->blockedReason($booking, $operator)) {
            throw ValidationException::withMessages(['booking' => [$reason]]);
        }

        $passengerIds = $booking->passengers
            ->filter(fn ($passenger) => $passenger->attendance_status !== PassengerAttendanceStatus::NoShow)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($passengerIds !== []) {
            return $this->trips()->markPassengersNoShow(
                $booking,
                $operator,
                $passengerIds,
                $remarks,
                true,
                $evidencePhotoPath,
            );
        }

        $this->finalize($booking, $operator, $remarks, true, $evidencePhotoPath);

        return $booking->fresh(['passengers']);
    }

    /**
     * Cancels the rental as a passenger no-show. Payments are kept; no refund is started.
     */
    public function finalize(
        BoatBooking $booking,
        User $operator,
        ?string $remarks,
        bool $declarationConfirmed,
        ?string $evidencePhotoPath = null,
    ): PassengerNoShowReport {
        $existing = $booking->passengerNoShowReport()->first();
        if ($existing instanceof PassengerNoShowReport) {
            if (filled($evidencePhotoPath) && ! filled($existing->evidence_photo_path)) {
                $existing->update(['evidence_photo_path' => $evidencePhotoPath]);
            }

            return $existing->fresh();
        }

        $booking->loadMissing('boat.operator');
        $paid = round(app(RentalRefundService::class)->paidTotal($booking) / 100, 2);
        $remarks = filled($remarks) ? trim((string) $remarks) : null;

        $report = DB::transaction(function () use ($booking, $operator, $remarks, $declarationConfirmed, $paid, $evidencePhotoPath): PassengerNoShowReport {
            $report = PassengerNoShowReport::query()->create([
                'organization_id' => $booking->organization_id,
                'boat_booking_id' => $booking->id,
                'reported_by_user_id' => $operator->id,
                'passenger_user_id' => $booking->user_id,
                'operator_id' => $booking->boat?->operator?->id,
                'boat_id' => $booking->boat_id,
                'scheduled_departure_at' => $this->trips()->scheduledDepartureAt($booking),
                'reported_at' => now(),
                'operator_remarks' => $remarks,
                'evidence_photo_path' => $evidencePhotoPath,
                'declaration_confirmed' => $declarationConfirmed,
                'cancellation_reason' => PassengerNoShowReport::CANCELLATION_REASON,
                'amount_paid' => $paid,
                'retained_amount' => $paid,
                'refund_eligible' => false,
                'status' => PassengerNoShowReport::STATUS_CONFIRMED,
            ]);

            $booking->update([
                'status' => BoatBookingStatus::PassengerNoShow,
                'cancellation_reason' => 'Passenger No-Show',
                'cancelled_by' => $operator->id,
                'cancelled_at' => now(),
                'remarks' => $remarks
                    ? trim(trim((string) $booking->remarks)."\nPassenger no-show: ".$remarks)
                    : $booking->remarks,
            ]);

            app(RentalCommissionService::class)->waiveUnpaidForNoShow($booking->fresh());

            return $report;
        });

        $fresh = $booking->fresh();

        $this->admins($booking)->each(fn (User $admin) => $this->safeNotify(
            $admin,
            new TripConfirmationNotification($fresh, 'passenger_no_show', 'Rental cancelled as a passenger no-show. No refund was issued.'),
        ));

        if ($booking->user instanceof User) {
            $this->safeNotify($booking->user, new TripConfirmationNotification($fresh, 'passenger_no_show'));
        }

        return $report;
    }

    public function canDispute(BoatBooking $booking, ?User $passenger): bool
    {
        $report = $booking->passengerNoShowReport;

        return $passenger !== null
            && (int) $booking->user_id === (int) $passenger->id
            && $report instanceof PassengerNoShowReport
            && $this->disputeWindowOpen($report);
    }

    /**
     * Auto-finalize confirmed passenger no-shows after the 1-day dispute window with no dispute filed.
     *
     * @return list<int>
     */
    public function expireUndisputedReports(?Carbon $now = null): array
    {
        $now = ($now ?? now('Asia/Manila'))->copy()->timezone('Asia/Manila');
        $cutoff = $now->copy()->subDays(self::DISPUTE_WINDOW_DAYS);

        $reports = PassengerNoShowReport::query()
            ->where('status', PassengerNoShowReport::STATUS_CONFIRMED)
            ->where('reported_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(100)
            ->get();

        $ids = [];

        foreach ($reports as $report) {
            $booking = BoatBooking::withoutGlobalScope('organization')
                ->with(['user', 'boat.operator.user'])
                ->find($report->boat_booking_id);
            if ($booking) {
                $report->setRelation('booking', $booking);
            }
            $this->autoUpholdExpired($report);
            $ids[] = (int) $report->id;
        }

        return $ids;
    }

    /**
     * @throws ValidationException
     */
    public function dispute(BoatBooking $booking, User $passenger, string $reason): PassengerNoShowReport
    {
        if (! $this->canDispute($booking, $passenger)) {
            $report = $booking->passengerNoShowReport;
            $message = $report instanceof PassengerNoShowReport
                && $report->status === PassengerNoShowReport::STATUS_CONFIRMED
                && ! $this->disputeWindowOpen($report)
                ? 'The 1-day dispute window has ended. This rental stays cancelled as a passenger no-show.'
                : 'This no-show cannot be disputed right now.';

            throw ValidationException::withMessages([
                'booking' => [$message],
            ]);
        }

        $report = $booking->passengerNoShowReport;
        $report->update([
            'status' => PassengerNoShowReport::STATUS_DISPUTED,
            'dispute_reason' => trim($reason),
            'disputed_at' => now(),
        ]);

        $booking->recordActivity('passenger_no_show_disputed', $passenger, $passenger->name);

        $this->admins($booking)->each(fn (User $admin) => $this->safeNotify(
            $admin,
            new PassengerNoShowNotification($booking, 'disputed_admin', trim($reason)),
        ));

        return $report->fresh();
    }

    /**
     * @param  'uphold'|'reverse'  $decision
     *
     * @throws ValidationException
     */
    public function resolve(PassengerNoShowReport $report, User $admin, string $decision, string $notes): PassengerNoShowReport
    {
        if (! in_array($report->status, [PassengerNoShowReport::STATUS_CONFIRMED, PassengerNoShowReport::STATUS_DISPUTED], true)) {
            throw ValidationException::withMessages([
                'report' => ['This passenger no-show was already reviewed.'],
            ]);
        }

        $booking = $report->booking()->withoutGlobalScope('organization')->firstOrFail();
        $booking->loadMissing(['user', 'boat.operator.user', 'passengers']);
        $notes = trim($notes);

        if ($decision === 'uphold') {
            $report->update([
                'status' => PassengerNoShowReport::STATUS_UPHELD,
                'resolution_notes' => $notes,
                'resolved_by_user_id' => $admin->id,
                'resolved_at' => now(),
            ]);
            $booking->recordActivity('passenger_no_show_upheld', $admin, $admin->name, ['note' => $notes]);

            $this->safeNotify($booking->user, new PassengerNoShowNotification($booking, 'upheld_passenger', $notes));
            $this->safeNotify($booking->boat?->operator?->user, new PassengerNoShowNotification($booking, 'upheld_operator'));

            return $report->fresh();
        }

        DB::transaction(function () use ($report, $booking, $admin, $notes): void {
            $report->update([
                'status' => PassengerNoShowReport::STATUS_REVERSED,
                'refund_eligible' => true,
                'resolution_notes' => $notes,
                'resolved_by_user_id' => $admin->id,
                'resolved_at' => now(),
            ]);

            $booking->passengers()->update([
                'attendance_status' => PassengerAttendanceStatus::NotYetArrived->value,
                'no_show_recorded_at' => null,
                'no_show_recorded_by_user_id' => null,
            ]);

            $booking->update([
                'status' => BoatBookingStatus::Cancelled,
                'cancellation_reason' => 'Passenger no-show reversed by barangay',
                'cancelled_by' => $admin->id,
                'cancelled_at' => now(),
            ]);

            $booking->recordActivity('passenger_no_show_reversed', $admin, $admin->name, ['note' => $notes]);
        });

        app(RentalRefundService::class)->openForCancellation($booking->fresh(), $admin, RentalRefundRequest::TYPE_ADMIN_CANCELLED);

        $this->safeNotify($booking->user, new PassengerNoShowNotification($booking, 'reversed_passenger', $notes));
        $this->safeNotify($booking->boat?->operator?->user, new PassengerNoShowNotification($booking, 'reversed_operator', $notes));

        return $report->fresh();
    }

    private function autoUpholdExpired(PassengerNoShowReport $report): void
    {
        if ($report->status !== PassengerNoShowReport::STATUS_CONFIRMED) {
            return;
        }

        $notes = 'Dispute window ended. No dispute was filed within 1 day. Rental remains cancelled as passenger no-show.';

        $report->update([
            'status' => PassengerNoShowReport::STATUS_UPHELD,
            'resolution_notes' => $notes,
            'resolved_by_user_id' => null,
            'resolved_at' => now(),
        ]);

        $booking = $report->booking;
        if (! $booking) {
            $booking = BoatBooking::withoutGlobalScope('organization')->find($report->boat_booking_id);
        }

        if ($booking) {
            if ($booking->status !== BoatBookingStatus::PassengerNoShow) {
                $booking->update([
                    'status' => BoatBookingStatus::PassengerNoShow,
                    'cancellation_reason' => 'Passenger No-Show',
                    'cancelled_at' => $booking->cancelled_at ?? now(),
                ]);
            } elseif ($booking->cancellation_reason !== 'Passenger No-Show') {
                $booking->update(['cancellation_reason' => 'Passenger No-Show']);
            }

            $booking->recordActivity(
                'passenger_no_show_upheld',
                null,
                'System',
                ['note' => $notes, 'auto_expired' => true],
            );

            $this->safeNotify($booking->user, new PassengerNoShowNotification($booking, 'upheld_passenger', $notes));
            $this->safeNotify($booking->boat?->operator?->user, new PassengerNoShowNotification($booking, 'upheld_operator'));
        }
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
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

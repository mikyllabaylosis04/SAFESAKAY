<?php

namespace App\Services;

use App\Enums\BoatBookingReplacementStatus;
use App\Enums\BoatBookingStatus;
use App\Enums\PassengerAttendanceStatus;
use App\Enums\TripConfirmationMethod;
use App\Enums\TripConfirmationStatus;
use App\Enums\TripConfirmedBy;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\BoatBookingReplacement;
use App\Models\BookingPassenger;
use App\Models\Operator;
use App\Models\User;
use App\Notifications\TripConfirmationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripConfirmationService
{
    public const PASSENGER_NO_SHOW_WAIT_MINUTES = 30;

    public function effectiveStatus(BoatBooking $booking): TripConfirmationStatus
    {
        if ($booking->trip_confirmation_status instanceof TripConfirmationStatus) {
            return $booking->trip_confirmation_status;
        }

        if ($booking->operator_availability_confirmed_at !== null) {
            return TripConfirmationStatus::Confirmed;
        }

        return $this->isSchedulable($booking)
            ? TripConfirmationStatus::AwaitingConfirmation
            : TripConfirmationStatus::AwaitingConfirmation;
    }

    public function isSchedulable(BoatBooking $booking): bool
    {
        return in_array($booking->status, [
            BoatBookingStatus::AwaitingPayment,
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
        ], true);
    }

    public function canAdminManage(BoatBooking $booking, User $admin): bool
    {
        return $admin->isSuperAdmin()
            || ((int) $admin->organization_id === (int) $booking->organization_id
                && in_array($admin->role, [UserRole::Administrator, UserRole::User], true));
    }

    public function canOperatorManage(BoatBooking $booking, User $operator): bool
    {
        if (! $operator->isMobileOperator()) {
            return false;
        }

        $booking->loadMissing('boat.operator');

        return (int) $booking->boat?->operator?->user_id === (int) $operator->id
            && $this->isSchedulable($booking);
    }

    public function canConfirm(BoatBooking $booking, User $actor): bool
    {
        if ($this->effectiveStatus($booking) === TripConfirmationStatus::Confirmed) {
            return false;
        }

        if ($this->effectiveStatus($booking) === TripConfirmationStatus::CannotOperate) {
            return false;
        }

        if ($actor->isMobileOperator()) {
            return $this->canOperatorManage($booking, $actor);
        }

        return $this->canAdminManage($booking, $actor);
    }

    public function canRecordCannotOperate(BoatBooking $booking, User $actor): bool
    {
        if ($this->effectiveStatus($booking) === TripConfirmationStatus::CannotOperate) {
            return false;
        }

        if ($actor->isMobileOperator()) {
            return $this->canOperatorManage($booking, $actor);
        }

        return $this->canAdminManage($booking, $actor);
    }

    public function confirmByOperator(BoatBooking $booking, User $operator): BoatBooking
    {
        if (! $this->canConfirm($booking, $operator) || ! $this->canOperatorManage($booking, $operator)) {
            throw ValidationException::withMessages([
                'booking' => ['You cannot confirm this trip.'],
            ]);
        }

        return $this->applyConfirmation(
            $booking,
            $operator,
            TripConfirmedBy::Operator,
            TripConfirmationMethod::MobileApp,
            null,
            'trip_confirmed_operator',
        );
    }

    public function canSendConfirmationRequest(BoatBooking $booking, User $admin): bool
    {
        if (! $this->canAdminManage($booking, $admin)) {
            return false;
        }

        if (! $this->isSchedulable($booking)) {
            return false;
        }

        return $this->effectiveStatus($booking) === TripConfirmationStatus::AwaitingConfirmation;
    }

    public function sendConfirmationRequest(BoatBooking $booking, User $admin): BoatBooking
    {
        if (! $this->canSendConfirmationRequest($booking, $admin)) {
            throw ValidationException::withMessages([
                'booking' => ['This trip is not waiting for a confirmation request.'],
            ]);
        }

        $booking->loadMissing(['boat.operator.user', 'user']);
        $destination = $booking->destination ?: $booking->package;

        $operatorUser = $booking->boat?->operator?->user;
        if ($operatorUser instanceof User) {
            $operatorUser->notify(new TripConfirmationNotification($booking, 'confirmation_request_operator'));
        }

        $booking->recordActivity('trip_confirmation_requested', $admin, $admin->name, [
            'booking_date' => $booking->booking_date->toDateString(),
            'start_time' => Carbon::parse($booking->start_time)->format('H:i'),
            'destination' => $destination,
        ]);

        return $booking->fresh();
    }

    public function confirmByAdminPhone(BoatBooking $booking, User $admin, ?string $remarks = null): BoatBooking
    {
        if (! $this->canConfirm($booking, $admin) || ! $this->canAdminManage($booking, $admin)) {
            throw ValidationException::withMessages([
                'booking' => ['This trip cannot be confirmed right now.'],
            ]);
        }

        return $this->applyConfirmation(
            $booking,
            $admin,
            TripConfirmedBy::Admin,
            TripConfirmationMethod::PhoneCall,
            $remarks,
            'trip_confirmed_admin_phone',
        );
    }

    public function cannotOperateByOperator(BoatBooking $booking, User $operator, ?string $reason = null): BoatBooking
    {
        if (! $this->canRecordCannotOperate($booking, $operator) || ! $this->canOperatorManage($booking, $operator)) {
            throw ValidationException::withMessages([
                'booking' => ['You cannot record unavailability for this trip.'],
            ]);
        }

        return $this->applyCannotOperate(
            $booking,
            $operator,
            TripConfirmationMethod::MobileApp,
            $reason,
            'trip_cannot_operate_operator',
        );
    }

    public function cannotOperateByAdminPhone(BoatBooking $booking, User $admin, ?string $reason = null): BoatBooking
    {
        if (! $this->canRecordCannotOperate($booking, $admin) || ! $this->canAdminManage($booking, $admin)) {
            throw ValidationException::withMessages([
                'booking' => ['This trip cannot be marked as cannot operate right now.'],
            ]);
        }

        return $this->applyCannotOperate(
            $booking,
            $admin,
            TripConfirmationMethod::PhoneCall,
            $reason,
            'trip_cannot_operate_admin_phone',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function replacementOptions(BoatBooking $booking): array
    {
        $booking->loadMissing('boat');
        $date = $booking->booking_date->toDateString();
        $time = Carbon::parse($booking->start_time)->format('H:i');

        return Boat::query()
            ->where('organization_id', $booking->organization_id)
            ->where('id', '!=', $booking->boat_id)
            ->orderBy('name')
            ->get()
            ->filter(function (Boat $boat) use ($booking, $date, $time) {
                if ($boat->capacity < $booking->passenger_count) {
                    return false;
                }

                return $boat->slotConflictMessage($date, $time, $booking->id) === null;
            })
            ->map(function (Boat $boat) {
                $boat->loadMissing('operator');

                return [
                    'id' => $boat->id,
                    'name' => $boat->name,
                    'code' => $boat->code,
                    'capacity' => $boat->capacity,
                    'operator_name' => $boat->operator?->full_name,
                    'operator_user_id' => $boat->operator?->user_id,
                ];
            })
            ->values()
            ->all();
    }

    public function proposeReplacement(BoatBooking $booking, User $admin, int $replacementBoatId, ?string $adminNotes = null): BoatBookingReplacement
    {
        if (! $this->canAdminManage($booking, $admin)) {
            throw ValidationException::withMessages(['booking' => ['Unauthorized.']]);
        }

        if ($this->effectiveStatus($booking) !== TripConfirmationStatus::CannotOperate) {
            throw ValidationException::withMessages([
                'booking' => ['Mark the trip as cannot operate before assigning a replacement.'],
            ]);
        }

        $open = $this->openReplacement($booking);
        if ($open && in_array($open->status, [
            BoatBookingReplacementStatus::PendingOperator,
            BoatBookingReplacementStatus::PendingAdminApproval,
        ], true)) {
            throw ValidationException::withMessages([
                'replacement_boat_id' => ['A replacement assignment is already in progress.'],
            ]);
        }

        $replacementBoat = Boat::query()->findOrFail($replacementBoatId);
        if ((int) $replacementBoat->organization_id !== (int) $booking->organization_id) {
            throw ValidationException::withMessages([
                'replacement_boat_id' => ['Replacement boat must belong to your organization.'],
            ]);
        }

        if ($replacementBoat->capacity < $booking->passenger_count) {
            throw ValidationException::withMessages([
                'replacement_boat_id' => ['Replacement boat does not have enough capacity.'],
            ]);
        }

        $date = $booking->booking_date->toDateString();
        $time = Carbon::parse($booking->start_time)->format('H:i');
        $conflict = $replacementBoat->slotConflictMessage($date, $time, $booking->id);
        if ($conflict) {
            throw ValidationException::withMessages(['replacement_boat_id' => [$conflict]]);
        }

        $booking->loadMissing('boat.operator');
        $replacementBoat->loadMissing('operator');

        $record = BoatBookingReplacement::query()->create([
            'organization_id' => $booking->organization_id,
            'boat_booking_id' => $booking->id,
            'original_boat_id' => $booking->boat_id,
            'original_operator_user_id' => $booking->boat?->operator?->user_id,
            'replacement_boat_id' => $replacementBoat->id,
            'replacement_operator_user_id' => $replacementBoat->operator?->user_id,
            'status' => BoatBookingReplacementStatus::PendingOperator,
            'reason' => $booking->operator_cannot_operate_reason,
            'initiated_confirmation_method' => $booking->trip_confirmation_method,
            'initiated_by_user_id' => $admin->id,
            'initiated_at' => now(),
            'admin_notes' => filled($adminNotes) ? trim($adminNotes) : null,
        ]);

        $booking->recordActivity('replacement_assignment_offered', $admin, $admin->name, [
            'replacement_boat_id' => $replacementBoat->id,
            'replacement_boat_name' => $replacementBoat->name,
            'original_boat_id' => $booking->boat_id,
            'original_boat_name' => $booking->boat?->name,
        ]);

        $replacementBoat->operator?->user?->notify(
            new TripConfirmationNotification($booking, 'replacement_pending'),
        );

        return $record;
    }

    public function replacementOperatorRespond(BoatBookingReplacement $replacement, User $operator, bool $accept, ?string $declineReason = null): BoatBookingReplacement
    {
        $replacement->loadMissing(['booking', 'replacementBoat.operator']);
        $booking = $replacement->booking;

        if ($replacement->status !== BoatBookingReplacementStatus::PendingOperator) {
            throw ValidationException::withMessages(['replacement' => ['This replacement request is no longer pending.']]);
        }

        if ((int) $replacement->replacementBoat?->operator?->user_id !== (int) $operator->id) {
            throw ValidationException::withMessages(['replacement' => ['You are not the assigned replacement operator.']]);
        }

        if ($accept) {
            $replacement->update([
                'status' => BoatBookingReplacementStatus::PendingAdminApproval,
                'operator_responded_at' => now(),
            ]);
            $booking?->recordActivity('replacement_operator_accepted', $operator, $operator->name, [
                'replacement_id' => $replacement->id,
            ]);
            $this->notifyOrganizationAdmins($booking, 'replacement_pending');
        } else {
            $replacement->update([
                'status' => BoatBookingReplacementStatus::Declined,
                'operator_responded_at' => now(),
                'operator_decline_reason' => filled($declineReason) ? trim($declineReason) : null,
            ]);
            $booking?->recordActivity('replacement_operator_declined', $operator, $operator->name, [
                'replacement_id' => $replacement->id,
                'reason' => $replacement->operator_decline_reason,
            ]);
            $this->notifyOrganizationAdmins($booking, 'cannot_operate_admin', 'Replacement operator declined.');
        }

        return $replacement->fresh();
    }

    public function approveReplacement(BoatBookingReplacement $replacement, User $admin): BoatBooking
    {
        $replacement->loadMissing(['booking.boat', 'replacementBoat.operator']);
        $booking = $replacement->booking;

        if (! $booking || ! $this->canAdminManage($booking, $admin)) {
            throw ValidationException::withMessages(['replacement' => ['Unauthorized.']]);
        }

        if ($replacement->status !== BoatBookingReplacementStatus::PendingAdminApproval) {
            throw ValidationException::withMessages(['replacement' => ['Replacement is not awaiting admin approval.']]);
        }

        $oldBoatName = $booking->boat?->name ?? 'Previous boat';
        $newBoat = $replacement->replacementBoat;
        if (! $newBoat) {
            throw ValidationException::withMessages(['replacement' => ['Replacement boat is missing.']]);
        }

        DB::transaction(function () use ($replacement, $booking, $admin, $newBoat, $oldBoatName): void {
            $booking->update(['boat_id' => $newBoat->id]);
            $replacement->update([
                'status' => BoatBookingReplacementStatus::Approved,
                'admin_approved_by_user_id' => $admin->id,
                'admin_approved_at' => now(),
            ]);
            $booking->recordActivity('replacement_admin_approved', $admin, $admin->name, [
                'original_boat' => $oldBoatName,
                'replacement_boat' => $newBoat->name,
                'replacement_id' => $replacement->id,
            ]);
        });

        $passenger = $booking->user;
        if ($passenger instanceof User) {
            $passenger->notify(new TripConfirmationNotification($booking->fresh(), 'replacement_approved'));
        }

        return $booking->fresh(['boat.operator', 'passengers', 'replacements']);
    }

    public function markNoReplacementAvailable(BoatBooking $booking, User $admin, ?string $note = null): BoatBooking
    {
        if (! $this->canAdminManage($booking, $admin)) {
            throw ValidationException::withMessages(['booking' => ['Unauthorized.']]);
        }

        $booking->recordActivity('replacement_not_available', $admin, $admin->name, [
            'note' => filled($note) ? trim($note) : null,
        ]);

        $passenger = $booking->user;
        if ($passenger instanceof User) {
            $passenger->notify(new TripConfirmationNotification($booking, 'no_replacement'));
        }

        return $booking;
    }

    public function confirmMeetingPointArrival(BoatBooking $booking, User $operator): BoatBooking
    {
        if (! $this->canOperatorManage($booking, $operator)) {
            throw ValidationException::withMessages(['booking' => ['You cannot confirm arrival for this trip.']]);
        }

        if (now('Asia/Manila')->toDateString() !== $booking->booking_date->toDateString()) {
            throw ValidationException::withMessages(['booking' => ['Arrival confirmation is only available on the trip date.']]);
        }

        if ($booking->operator_meeting_point_arrived_at !== null) {
            throw ValidationException::withMessages(['booking' => ['Arrival at the meeting point is already confirmed.']]);
        }

        if ($this->effectiveStatus($booking) !== TripConfirmationStatus::Confirmed) {
            throw ValidationException::withMessages(['booking' => ['Confirm trip availability before recording arrival.']]);
        }

        $booking->update([
            'operator_meeting_point_arrived_at' => now(),
            'operator_meeting_point_arrived_by_user_id' => $operator->id,
        ]);

        $booking->recordActivity('operator_meeting_point_arrived', $operator, $operator->name);

        return $booking->fresh(['passengers']);
    }

    public function checkInPassenger(BoatBooking $booking, User $operator, int $passengerId): BookingPassenger
    {
        if ($booking->operator_meeting_point_arrived_at === null) {
            throw ValidationException::withMessages(['booking' => ['Confirm your arrival at the meeting point first.']]);
        }

        if (! $this->canOperatorManage($booking, $operator)) {
            throw ValidationException::withMessages(['passenger' => ['Unauthorized.']]);
        }

        $passenger = $booking->passengers()->whereKey($passengerId)->firstOrFail();

        if ($passenger->attendance_status === PassengerAttendanceStatus::NoShow) {
            throw ValidationException::withMessages(['passenger' => ['This passenger is marked as no-show. Contact admin to correct attendance.']]);
        }

        $passenger->update([
            'attendance_status' => PassengerAttendanceStatus::Arrived,
            'checked_in_at' => now(),
        ]);

        $booking->recordActivity('passenger_checked_in', $operator, $operator->name, [
            'passenger_id' => $passenger->id,
            'passenger_name' => $passenger->full_name,
        ]);

        return $passenger->fresh();
    }

    /**
     * @param  list<int>  $passengerIds
     */
    public function markPassengersNoShow(
        BoatBooking $booking,
        User $operator,
        array $passengerIds,
        ?string $remarks = null,
        bool $declarationConfirmed = false,
        ?string $evidencePhotoPath = null,
    ): BoatBooking {

        if (! $this->canMarkPassengerNoShow($booking, $operator)) {
            throw ValidationException::withMessages([
                'booking' => [app(PassengerNoShowService::class)->blockedReason($booking, $operator)
                    ?? 'Passenger no-show cannot be recorded for this trip right now.'],
            ]);
        }

        $passengers = $booking->passengers()
            ->whereIn('id', $passengerIds)
            ->get();

        if ($passengers->isEmpty()) {
            throw ValidationException::withMessages(['passenger_ids' => ['Select at least one passenger.']]);
        }

        foreach ($passengers as $passenger) {
            if ($passenger->attendance_status === PassengerAttendanceStatus::Arrived) {
                throw ValidationException::withMessages([
                    'passenger_ids' => ["{$passenger->full_name} already checked in and cannot be marked as no-show."],
                ]);
            }
        }

        foreach ($passengers as $passenger) {
            $passenger->update([
                'attendance_status' => PassengerAttendanceStatus::NoShow,
                'no_show_recorded_at' => now(),
                'no_show_recorded_by_user_id' => $operator->id,
                'no_show_remarks' => filled($remarks) ? trim($remarks) : null,
            ]);

            $booking->recordActivity('passenger_no_show_recorded', $operator, $operator->name, [
                'passenger_id' => $passenger->id,
                'passenger_name' => $passenger->full_name,
                'scheduled_departure' => $this->scheduledDepartureAt($booking)?->toIso8601String(),
                'operator_arrival_at' => $booking->operator_meeting_point_arrived_at?->toIso8601String(),
            ]);
        }

        $booking->load('passengers');

        if ($this->allPassengersNoShow($booking)) {
            app(PassengerNoShowService::class)->finalize(
                $booking->fresh(),
                $operator,
                $remarks,
                $declarationConfirmed,
                $evidencePhotoPath,
            );

            return $booking->fresh(['passengers']);
        }

        $this->notifyOrganizationAdmins($booking, 'passenger_no_show', $passengers->pluck('full_name')->join(', '));

        return $booking->fresh(['passengers']);
    }

    public function adminCorrectPassengerAttendance(
        BoatBooking $booking,
        User $admin,
        int $passengerId,
        PassengerAttendanceStatus $status,
        ?string $note = null,
    ): BookingPassenger {
        if (! $this->canAdminManage($booking, $admin)) {
            throw ValidationException::withMessages(['passenger' => ['Unauthorized.']]);
        }

        $passenger = $booking->passengers()->whereKey($passengerId)->firstOrFail();

        $updates = [
            'attendance_status' => $status,
            'attendance_admin_note' => filled($note) ? trim($note) : null,
            'attendance_corrected_by_user_id' => $admin->id,
            'attendance_corrected_at' => now(),
        ];

        if ($status === PassengerAttendanceStatus::Arrived) {
            $updates['checked_in_at'] = $passenger->checked_in_at ?? now();
            $updates['no_show_recorded_at'] = null;
            $updates['no_show_recorded_by_user_id'] = null;
        }

        if ($status === PassengerAttendanceStatus::NotYetArrived) {
            $updates['checked_in_at'] = null;
            $updates['no_show_recorded_at'] = null;
            $updates['no_show_recorded_by_user_id'] = null;
        }

        $passenger->update($updates);

        $booking->recordActivity('passenger_attendance_corrected', $admin, $admin->name, [
            'passenger_id' => $passenger->id,
            'passenger_name' => $passenger->full_name,
            'attendance_status' => $status->value,
            'note' => $updates['attendance_admin_note'],
        ]);

        return $passenger->fresh();
    }

    public function canOperatorConfirmMeetingPoint(BoatBooking $booking, User $operator): bool
    {
        return $this->canOperatorManage($booking, $operator)
            && $this->effectiveStatus($booking) === TripConfirmationStatus::Confirmed
            && $booking->operator_meeting_point_arrived_at === null
            && now('Asia/Manila')->toDateString() === $booking->booking_date->toDateString();
    }

    public function canCheckInPassengers(BoatBooking $booking, User $operator): bool
    {
        return $this->canOperatorManage($booking, $operator)
            && $booking->operator_meeting_point_arrived_at !== null;
    }

    public function canMarkPassengerNoShow(BoatBooking $booking, User $operator): bool
    {
        if (! $this->canOperatorManage($booking, $operator)) {
            return false;
        }

        return app(PassengerNoShowService::class)->blockedReason($booking, $operator, false) === null;
    }

    public function waitingPeriodEndsAt(BoatBooking $booking): ?Carbon
    {
        $departure = $this->scheduledDepartureAt($booking);

        return $departure?->copy()->addMinutes(
            \App\Models\Organization::noShowGraceFor((int) $booking->organization_id, 'passenger'),
        );
    }

    public function scheduledDepartureAt(BoatBooking $booking): ?Carbon
    {
        if (! $booking->booking_date || ! $booking->start_time) {
            return null;
        }

        $time = Carbon::parse($booking->start_time)->format('H:i:s');

        return Carbon::parse(
            $booking->booking_date->toDateString().' '.$time,
            'Asia/Manila',
        );
    }

    public function openReplacement(BoatBooking $booking): ?BoatBookingReplacement
    {
        return $booking->replacements()
            ->whereIn('status', [
                BoatBookingReplacementStatus::PendingOperator,
                BoatBookingReplacementStatus::PendingAdminApproval,
                BoatBookingReplacementStatus::Declined,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function adminTripRow(BoatBooking $booking): array
    {
        $booking->loadMissing(['boat.operator', 'tripConfirmedBy', 'passengers', 'activities']);
        $status = $this->effectiveStatus($booking);
        $confirmedBy = $this->confirmedByLabel($booking);

        return [
            'trip_confirmation_status' => $status->value,
            'trip_confirmation_status_label' => $status->label(),
            'trip_confirmation_method' => $booking->trip_confirmation_method?->value,
            'trip_confirmation_method_label' => $this->tripConfirmationMethodDisplayLabel($booking),
            'trip_confirmed_at_label' => $booking->trip_confirmed_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'trip_confirmed_by_label' => $confirmedBy,
            'trip_confirmation_remarks' => $booking->trip_confirmation_remarks,
            'trip_confirmation_conflict' => (bool) $booking->trip_confirmation_conflict,
            'operator_cannot_operate_reason' => $booking->operator_cannot_operate_reason,
            'operator_contact_number' => $booking->boat?->operator?->contact_number,
            'operator_contact_number_label' => filled($booking->boat?->operator?->contact_number)
                ? $booking->boat->operator->contact_number
                : null,
            'can_admin_confirm_trip' => ($user = auth()->user()) instanceof User && $this->canConfirm($booking, $user),
            'can_admin_cannot_operate' => ($user ?? null) instanceof User && $this->canRecordCannotOperate($booking, $user),
            'can_send_confirmation_request' => ($user ?? null) instanceof User && $this->canSendConfirmationRequest($booking, $user),
            'show_trip_manage' => $this->isSchedulable($booking),
            'confirmation_record' => $this->confirmationRecordForAdmin($booking),
            'replacement' => $this->replacementPayload($booking),
            'attendance' => $this->attendancePayload($booking),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function operatorTripPayload(BoatBooking $booking, User $operator): array
    {
        return [
            'trip_confirmation_status' => $this->effectiveStatus($booking)->value,
            'trip_confirmation_status_label' => $this->effectiveStatus($booking)->label(),
            'can_confirm_trip' => $this->canConfirm($booking, $operator),
            'can_cannot_operate' => $this->canRecordCannotOperate($booking, $operator),
            'can_confirm_meeting_point_arrival' => $this->canOperatorConfirmMeetingPoint($booking, $operator),
            'can_check_in_passengers' => $this->canCheckInPassengers($booking, $operator),
            'can_mark_passenger_no_show' => $this->canMarkPassengerNoShow($booking, $operator),
            'waiting_period_ends_at_label' => $this->waitingPeriodEndsAt($booking)?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'operator_meeting_point_arrived_at_label' => $booking->operator_meeting_point_arrived_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'passengers' => $booking->passengers->map(fn (BookingPassenger $p) => $this->passengerAttendanceRecord($p))->values()->all(),
            'open_replacement' => $this->replacementOperatorPayload($booking, $operator),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replacementOperatorPayload(BoatBooking $booking, User $operator): ?array
    {
        $replacement = $booking->replacements()
            ->where('status', BoatBookingReplacementStatus::PendingOperator)
            ->where('replacement_operator_user_id', $operator->id)
            ->latest('id')
            ->first();

        if (! $replacement) {
            return null;
        }

        $replacement->loadMissing('replacementBoat');

        return [
            'id' => $replacement->id,
            'booking_id' => $booking->id,
            'booking_code' => $booking->code,
            'replacement_boat_name' => $replacement->replacementBoat?->name,
            'booking_date_label' => $booking->booking_date->format('M d, Y'),
            'start_time_label' => Carbon::parse($booking->start_time)->format('g:i A'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replacementPayload(BoatBooking $booking): ?array
    {
        $replacement = $this->openReplacement($booking);
        if (! $replacement) {
            return null;
        }

        $replacement->loadMissing(['originalBoat', 'replacementBoat.operator']);

        return [
            'id' => $replacement->id,
            'status' => $replacement->status->value,
            'status_label' => $replacement->status->label(),
            'original_boat_name' => $replacement->originalBoat?->name,
            'replacement_boat_name' => $replacement->replacementBoat?->name,
            'replacement_operator_name' => $replacement->replacementBoat?->operator?->full_name,
            'operator_decline_reason' => $replacement->operator_decline_reason,
            'can_approve' => $replacement->status === BoatBookingReplacementStatus::PendingAdminApproval,
            'replacement_options' => $this->replacementOptions($booking),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendancePayload(BoatBooking $booking): array
    {
        return [
            'operator_meeting_point_arrived_at_label' => $booking->operator_meeting_point_arrived_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'waiting_period_ends_at_label' => $this->waitingPeriodEndsAt($booking)?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'passengers' => $booking->passengers->map(fn (BookingPassenger $p) => $this->passengerAttendanceRecord($p))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function passengerAttendanceRecord(BookingPassenger $passenger): array
    {
        $status = $passenger->attendance_status instanceof PassengerAttendanceStatus
            ? $passenger->attendance_status
            : PassengerAttendanceStatus::tryFrom((string) $passenger->attendance_status) ?? PassengerAttendanceStatus::NotYetArrived;

        return [
            'id' => $passenger->id,
            'full_name' => $passenger->full_name,
            'attendance_status' => $status->value,
            'attendance_status_label' => $status->label(),
            'checked_in_at_label' => $passenger->checked_in_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'no_show_recorded_at_label' => $passenger->no_show_recorded_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
        ];
    }

    private function confirmedByLabel(BoatBooking $booking): ?string
    {
        return $this->confirmedByRole($booking)?->label();
    }

    private function confirmedByRole(BoatBooking $booking): ?TripConfirmedBy
    {
        if ($booking->trip_confirmation_method === TripConfirmationMethod::PhoneCall) {
            return TripConfirmedBy::Admin;
        }

        if ($booking->trip_confirmation_method === TripConfirmationMethod::MobileApp) {
            return TripConfirmedBy::Operator;
        }

        if ($booking->trip_confirmed_by_user_id === null) {
            return $booking->operator_availability_confirmed_at !== null
                ? TripConfirmedBy::Operator
                : null;
        }

        $user = $booking->relationLoaded('tripConfirmedBy')
            ? $booking->tripConfirmedBy
            : $booking->tripConfirmedBy()->first();

        if ($user?->isMobileOperator()) {
            return TripConfirmedBy::Operator;
        }

        return TripConfirmedBy::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmationRecordForAdmin(BoatBooking $booking): array
    {
        $status = $this->effectiveStatus($booking);
        $requestSentLabel = $this->activityOccurredAtLabel($booking, 'trip_confirmation_requested');
        $responseRecordedLabel = $booking->trip_confirmed_at?->timezone('Asia/Manila')->format('M d, Y · g:i A');

        $operatorResponse = match ($status) {
            TripConfirmationStatus::Confirmed => 'Available',
            TripConfirmationStatus::CannotOperate => 'Cannot Operate',
            default => null,
        };

        $remarks = $status === TripConfirmationStatus::CannotOperate
            ? ($booking->operator_cannot_operate_reason ?: $booking->trip_confirmation_remarks)
            : $booking->trip_confirmation_remarks;

        $requestSent = $this->hasConfirmationRequestSent($booking);

        return [
            'mode' => $status->value,
            'confirmation_request_sent_at_label' => $requestSentLabel,
            'confirmation_request_via_label' => $requestSent ? 'Mobile App' : null,
            'confirmed_by_label' => $this->confirmedByLabel($booking),
            'confirmation_type_label' => $booking->trip_confirmation_method?->label(),
            'confirmation_at_label' => $responseRecordedLabel,
            'operator_response_label' => $operatorResponse,
            'remarks' => filled($remarks) ? trim((string) $remarks) : null,
            'is_read_only' => in_array($status, [
                TripConfirmationStatus::Confirmed,
                TripConfirmationStatus::CannotOperate,
            ], true),
        ];
    }

    private function hasConfirmationRequestSent(BoatBooking $booking): bool
    {
        if ($booking->relationLoaded('activities')) {
            return $booking->activities->contains(
                fn ($activity) => $activity->event === 'trip_confirmation_requested',
            );
        }

        return $booking->activities()
            ->where('event', 'trip_confirmation_requested')
            ->exists();
    }

    public function tripConfirmationMethodDisplayLabel(BoatBooking $booking): ?string
    {
        $requestSent = $this->hasConfirmationRequestSent($booking);
        $method = $booking->trip_confirmation_method;

        if ($method === null) {
            return $requestSent ? 'App request sent' : null;
        }

        if ($requestSent && $method === TripConfirmationMethod::PhoneCall) {
            return 'App request · Phone Call';
        }

        return $method->label();
    }

    private function activityOccurredAtLabel(BoatBooking $booking, string $event): ?string
    {
        $activity = $booking->activities()
            ->where('event', $event)
            ->latest('id')
            ->first();

        return $activity?->created_at?->timezone('Asia/Manila')->format('M d, Y · g:i A');
    }

    private function applyConfirmation(
        BoatBooking $booking,
        User $actor,
        TripConfirmedBy $confirmedBy,
        TripConfirmationMethod $method,
        ?string $remarks,
        string $event,
    ): BoatBooking {
        $this->assertNoConflict($booking, TripConfirmationStatus::Confirmed);

        $now = now();
        $booking->update([
            'trip_confirmation_status' => TripConfirmationStatus::Confirmed,
            'trip_confirmed_at' => $now,
            'trip_confirmed_by_user_id' => $actor->id,
            'trip_confirmation_method' => $method,
            'trip_confirmation_remarks' => filled($remarks) ? trim($remarks) : null,
            'operator_availability_confirmed_at' => $booking->operator_availability_confirmed_at ?? $now,
            'operator_availability_confirmed_by' => $booking->operator_availability_confirmed_by ?? $actor->id,
        ]);

        $booking->recordActivity($event, $actor, $actor->name, [
            'confirmation_status' => TripConfirmationStatus::Confirmed->value,
            'confirmed_by' => $confirmedBy->value,
            'confirmation_method' => $method->value,
            'remarks' => filled($remarks) ? trim($remarks) : null,
        ]);

        $passenger = $booking->user;
        if ($passenger instanceof User) {
            $passenger->notify(new TripConfirmationNotification($booking->fresh(), 'trip_confirmed'));
        }

        return $booking->fresh();
    }

    private function applyCannotOperate(
        BoatBooking $booking,
        User $actor,
        TripConfirmationMethod $method,
        ?string $reason,
        string $event,
    ): BoatBooking {
        $this->assertNoConflict($booking, TripConfirmationStatus::CannotOperate);

        $booking->loadMissing('boat.operator');
        $originalBoatId = $booking->boat_id;
        $originalOperatorUserId = $booking->boat?->operator?->user_id;

        $booking->update([
            'trip_confirmation_status' => TripConfirmationStatus::CannotOperate,
            'trip_confirmed_at' => now(),
            'trip_confirmed_by_user_id' => $actor->id,
            'trip_confirmation_method' => $method,
            'operator_cannot_operate_reason' => filled($reason) ? trim($reason) : null,
        ]);

        $booking->recordActivity($event, $actor, $actor->name, [
            'confirmation_status' => TripConfirmationStatus::CannotOperate->value,
            'confirmation_method' => $method->value,
            'reason' => filled($reason) ? trim($reason) : null,
            'original_boat_id' => $originalBoatId,
            'original_operator_user_id' => $originalOperatorUserId,
        ]);

        $this->notifyOrganizationAdmins(
            $booking,
            'cannot_operate_admin',
            filled($reason) ? trim($reason) : 'Operator unavailable.',
        );

        return $booking->fresh();
    }

    private function assertNoConflict(BoatBooking $booking, TripConfirmationStatus $incoming): void
    {
        $current = $this->effectiveStatus($booking);

        if ($current === TripConfirmationStatus::AwaitingConfirmation) {
            return;
        }

        if ($current === $incoming) {
            throw ValidationException::withMessages([
                'booking' => ['This action was already recorded for this trip.'],
            ]);
        }

        $booking->update(['trip_confirmation_conflict' => true]);
        $booking->recordActivity('trip_confirmation_conflict', auth()->user(), auth()->user()?->name, [
            'previous_status' => $current->value,
            'incoming_status' => $incoming->value,
        ]);
    }

    private function allPassengersNoShow(BoatBooking $booking): bool
    {
        $booking->loadMissing('passengers');
        if ($booking->passengers->isEmpty()) {
            return true;
        }

        return $booking->passengers->every(
            fn (BookingPassenger $passenger) => $passenger->attendance_status === PassengerAttendanceStatus::NoShow,
        );
    }

    private function notifyOrganizationAdmins(BoatBooking $booking, string $event, ?string $detail = null): void
    {
        User::query()
            ->where('organization_id', $booking->organization_id)
            ->whereIn('role', [UserRole::Administrator, UserRole::User])
            ->where('is_active', true)
            ->get()
            ->each(function (User $admin) use ($booking, $event, $detail): void {
                try {
                    $admin->notify(new TripConfirmationNotification($booking, $event, $detail));
                } catch (\Throwable) {
                }
            });
    }
}

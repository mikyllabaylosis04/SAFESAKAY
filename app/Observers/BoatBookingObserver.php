<?php

namespace App\Observers;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\BoatBooking;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use App\Notifications\PaymentUpdateNotification;
use App\Services\BoatStatusSyncService;
use App\Services\RegularTripRotationService;
use App\Services\OperatorGcashPayoutService;
use App\Services\RentalCommissionService;

class BoatBookingObserver
{
    public function created(BoatBooking $booking): void
    {
        if ($booking->gcash_payee === null) {
            $booking->snapshotGcashPayee(true);
            $booking->saveQuietly();
        }

        $booking->loadMissing('user');

        if ($booking->user_id === null) {
            $remarks = (string) ($booking->remarks ?? '');
            $isCash = str_contains($remarks, '@walk-in:cash')
                || str_contains($remarks, 'Walk-in down payment received in cash');
            $event = $isCash ? 'walk_in_cash_created' : 'walk_in_gcash_created';
            $booking->recordActivity($event, auth()->user(), auth()->user()?->name);
        } else {
            $booking->recordActivity('submitted', $booking->user, $booking->customer_name);
        }
        $this->releaseRegularTrips($booking);
        $this->syncBoat($booking);
        $this->notifyUsers($booking);

        if ($booking->paymentStatus() === BookingPaymentStatus::Verified) {
            app(RentalCommissionService::class)->generateIfEligible($booking->fresh(['boat.operator']));
        }
    }

    public function updated(BoatBooking $booking): void
    {
        if ($booking->wasChanged('status') || $booking->wasChanged('booking_date') || $booking->wasChanged('boat_id')) {
            $this->releaseRegularTrips($booking);
            $this->restoreRegularTrips($booking);
            $this->syncBoat($booking);
        }

        if ($booking->wasChanged('status')) {
            if ($booking->status === BoatBookingStatus::AwaitingPayment && ! $booking->hasPaymentSubmission()) {
                $booking->snapshotPaymentRecipient();
                if ($booking->isDirty([
                    'gcash_payee',
                    'gcash_recipient_account_name',
                    'gcash_recipient_mobile',
                ])) {
                    $booking->saveQuietly();
                }
            }

            $this->recordStatusActivity($booking);
            $this->notifyUsers($booking);
        }

        if ($booking->wasChanged('gcash_reference')
            || $booking->wasChanged('payment_receipt_path')
            || $booking->wasChanged('payment_submitted_at')) {
            if ($booking->paymentStatus() === BookingPaymentStatus::ForVerification) {
                $booking->recordActivity('payment_submitted', $booking->user, $booking->customer_name);
            }
        }

        if ($booking->wasChanged('payment_status')
            || $booking->wasChanged('balance_status')) {
            $booking->loadMissing(['user', 'boat.operator.user']);
        }

        if ($booking->wasChanged('payment_status')
            && $booking->paymentStatus() === BookingPaymentStatus::ForVerification) {
            if ($booking->paysBarangayGcash()) {
                User::query()
                    ->where('organization_id', $booking->organization_id)
                    ->where('role', UserRole::Administrator)
                    ->get()
                    ->each(fn (User $admin) => $this->notifyPayment($booking, 'payment_submitted', $admin));

                $operatorUser = $booking->boat?->operator?->user;
                if ($operatorUser instanceof User) {
                    $this->notifyPayment($booking, 'payment_submitted_operator', $operatorUser);
                }
            } else {
                $this->notifyPayment($booking, 'payment_submitted', $booking->boat?->operator?->user);
            }
        }

        if ($booking->wasChanged('payment_status')
            && $booking->paymentStatus() === BookingPaymentStatus::Rejected) {
            $this->notifyPayment($booking, 'payment_rejected', $booking->user);
        }

        if ($booking->wasChanged('balance_status')
            && $booking->balanceStatus() === BookingBalanceStatus::Paid
            && $booking->hasOutstandingBalance()) {
            $this->notifyPayment($booking, 'balance_paid', $booking->user);
        }

        if ($booking->wasChanged('status')
            || $booking->wasChanged('payment_status')
            || $booking->wasChanged('balance_status')) {
            $booking->loadMissing('boat.operator');
            $commission = app(RentalCommissionService::class)->generateIfEligible($booking);

            if ($commission !== null) {
                app(OperatorGcashPayoutService::class)->releaseIfReady($commission);
            }
        }
    }

    private function recordStatusActivity(BoatBooking $booking): void
    {
        $previous = $booking->getOriginal('status');
        $previousStatus = $previous instanceof BoatBookingStatus
            ? $previous
            : BoatBookingStatus::tryFrom((string) $previous);

        $event = match ($booking->status) {
            BoatBookingStatus::AwaitingPayment => $previousStatus === BoatBookingStatus::Pending
                ? 'operator_accepted'
                : null,
            BoatBookingStatus::Confirmed => match ($previousStatus) {
                BoatBookingStatus::Pending => 'operator_accepted',
                BoatBookingStatus::AwaitingPayment => 'payment_verified',
                BoatBookingStatus::WeatherHold, BoatBookingStatus::ReschedulePending => 'reschedule_approved',
                default => null,
            },
            BoatBookingStatus::WeatherHold => match ($previousStatus) {
                BoatBookingStatus::ReschedulePending => 'reschedule_rejected',
                default => 'weather_hold',
            },
            BoatBookingStatus::ReschedulePending => 'reschedule_requested',
            BoatBookingStatus::Completed => 'completed',
            BoatBookingStatus::PassengerNoShow => 'passenger_no_show',
            BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected => 'cancelled',
            default => null,
        };

        if ($event === null) {
            return;
        }

        $actor = auth()->user();

        if ($event === 'operator_accepted') {
            $booking->loadMissing('boat.operator');
            if ($actor instanceof User && $actor->isAdministrator()) {
                $event = 'admin_approved';
                $actorName = $actor->name;
            } else {
                $actorName = $booking->boat?->operator?->full_name ?? $actor?->name;
            }
        } else {
            $actorName = $actor?->name;
        }

        $booking->recordActivity($event, $actor instanceof User ? $actor : null, $actorName);
    }

    public function deleted(BoatBooking $booking): void
    {
        $this->syncBoat($booking);
    }

    private function releaseRegularTrips(BoatBooking $booking): void
    {
        if (! in_array($booking->status, [
            BoatBookingStatus::Pending,
            BoatBookingStatus::AwaitingPayment,
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
        ], true)) {
            return;
        }

        $boat = $booking->boat ?? Boat::query()
            ->withoutGlobalScope('organization')
            ->find($booking->boat_id);

        if (! $boat instanceof Boat) {
            return;
        }

        app(RegularTripRotationService::class)->releaseBoatForPrivateBooking(
            $boat,
            $booking->booking_date->toDateString(),
        );
    }

    private function restoreRegularTrips(BoatBooking $booking): void
    {
        if (! in_array($booking->status, [
            BoatBookingStatus::Completed,
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
            BoatBookingStatus::PassengerNoShow,
        ], true)) {
            return;
        }

        $boat = $booking->boat ?? Boat::query()
            ->withoutGlobalScope('organization')
            ->find($booking->boat_id);

        if (! $boat instanceof Boat || $boat->organization_id === null) {
            return;
        }

        app(RegularTripRotationService::class)->restoreFinishedPrivateBookingTrips((int) $boat->organization_id);
    }

    private function syncBoat(BoatBooking $booking): void
    {
        $boat = $booking->boat ?? Boat::query()
            ->withoutGlobalScope('organization')
            ->find($booking->boat_id);

        if ($boat instanceof Boat) {
            app(BoatStatusSyncService::class)->sync($boat);
        }
    }

    private function notifyUsers(BoatBooking $booking): void
    {
        $booking->loadMissing(['user', 'boat.operator.user']);

        $this->notifyUser($booking->user, $booking);

        $operators = \App\Models\Operator::linkedUsersForBoat((int) $booking->boat_id);
        if ($operators->isEmpty()) {
            $linked = $booking->boat?->operator?->user;
            $operators = $linked instanceof User ? collect([$linked]) : collect();
        }

        $operators
            ->unique('id')
            ->each(function (User $operator) use ($booking): void {
                if ((int) $operator->id !== (int) $booking->user_id) {
                    $this->notifyUser($operator, $booking);
                }
            });
    }

    private function notifyUser(?User $user, BoatBooking $booking): void
    {
        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notify(new BookingStatusNotification($booking));
        } catch (\Throwable) {
            // Keep booking create/approve working if notifications are not migrated yet.
        }
    }

    private function notifyPayment(BoatBooking $booking, string $event, ?User $user): void
    {
        $booking->loadMissing(['user', 'boat.operator.user']);

        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notify(new PaymentUpdateNotification($booking, $event));
        } catch (\Throwable) {
            // Keep payment updates working if notifications are not migrated yet.
        }
    }
}

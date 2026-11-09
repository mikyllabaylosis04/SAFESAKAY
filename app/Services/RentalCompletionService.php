<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Models\BoatBooking;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RentalCompletionService
{
    public function blockedReason(BoatBooking $booking): ?string
    {
        if (! in_array($booking->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)) {
            return 'Only an approved trip can be marked completed.';
        }

        if ($booking->weather_review_required) {
            return 'This trip is under weather review. Wait for the barangay to clear it before completing.';
        }

        if ($booking->requiresDownPayment() && $booking->paymentStatus() !== BookingPaymentStatus::Verified) {
            return 'The down payment is not verified yet. The trip can be completed after payment is verified.';
        }

        return null;
    }

    public function complete(BoatBooking $booking, User $actor): BoatBooking
    {
        $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($booking->id);

        if ($reason = $this->blockedReason($record)) {
            throw ValidationException::withMessages(['booking' => $reason]);
        }

        if ($record->canRecordBalancePayment()) {
            $record->update([
                'balance_status' => BookingBalanceStatus::Paid,
                'balance_method' => 'cash',
                'balance_reference' => null,
                'balance_paid_at' => now(),
                'balance_recorded_by' => $actor->id,
            ]);

            $record->recordActivity('balance_paid_on_trip', $actor);
        }

        $record->update([
            'status' => BoatBookingStatus::Completed,
            'completed_at' => now(),
        ]);

        return $record->fresh();
    }
}

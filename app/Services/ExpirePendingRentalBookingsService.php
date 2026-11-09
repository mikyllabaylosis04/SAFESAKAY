<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Models\BoatBooking;
use Illuminate\Support\Carbon;

class ExpirePendingRentalBookingsService
{
    public const APPROVAL_DEADLINE_DAYS = 2;

    public const AUTO_CANCEL_REASON = 'Automatically cancelled: rental request was not approved within 2 days.';

    public const AUTO_PAYMENT_CANCEL_REASON = 'Automatically cancelled: payment was not completed within 2 days.';

    /**
     * @return list<int>
     */
    public function expire(Carbon $now): array
    {
        $cutoff = $now->copy()->subDays(self::APPROVAL_DEADLINE_DAYS);

        $expiredIds = [];

        $pending = BoatBooking::query()
            ->withoutGlobalScopes()
            ->where('status', BoatBookingStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        foreach ($pending as $booking) {
            $this->rejectBooking($booking, self::AUTO_CANCEL_REASON, $now);
            $expiredIds[] = $booking->id;
        }

        $awaitingPayment = BoatBooking::query()
            ->withoutGlobalScopes()
            ->where('status', BoatBookingStatus::AwaitingPayment)
            ->where('payment_status', '!=', BookingPaymentStatus::ForVerification)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        foreach ($awaitingPayment as $booking) {
            $this->rejectBooking($booking, self::AUTO_PAYMENT_CANCEL_REASON, $now);
            $expiredIds[] = $booking->id;
        }

        return $expiredIds;
    }

    public function syncExpiredRentalBookings(?Carbon $now = null): array
    {
        return $this->expire($now ?? now());
    }

    private function rejectBooking(BoatBooking $booking, string $reason, Carbon $now): void
    {
        $booking->update([
            'status' => BoatBookingStatus::Rejected,
            'cancellation_reason' => $reason,
            'cancelled_by' => null,
            'cancelled_at' => $now,
        ]);
    }
}

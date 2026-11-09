<?php

namespace App\Http\Controllers\Api;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\User;
use App\Support\RentalPaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PassengerPaymentController extends Controller
{
    private const BALANCE_DUE_STATUSES = [
        BoatBookingStatus::Confirmed,
        BoatBookingStatus::Ongoing,
        BoatBookingStatus::WeatherHold,
        BoatBookingStatus::ReschedulePending,
    ];

    public function __construct(private readonly RentalPaymentLedger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->passenger($request);

        $rows = [];
        $balanceDue = 0.0;
        $balanceDueCount = 0;

        foreach ($this->bookings($user) as $booking) {
            $due = $this->balanceDue($booking);
            if ($due > 0) {
                $balanceDue += $due;
                $balanceDueCount++;
            }

            foreach ($this->ledger->lines($booking) as $line) {
                $rows[] = [
                    ...$line,
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->code,
                    'booking_status' => $booking->status?->value,
                    'booking_status_label' => $booking->status?->label() ?? '',
                    'boat_name' => $booking->boat?->name,
                    'destination' => $booking->destination,
                    'rental_date_label' => $booking->booking_date?->timezone('Asia/Manila')->format('M j, Y'),
                    'rental_time_label' => $this->timeLabel($booking),
                    'rental_total' => round((float) ($booking->rental_price ?? 0), 2),
                    'rental_total_label' => RentalPaymentLedger::money((float) ($booking->rental_price ?? 0)),
                    'booking_balance_due' => $due,
                    'booking_balance_due_label' => $due > 0 ? RentalPaymentLedger::money($due) : null,
                ];
            }
        }

        usort($rows, fn (array $left, array $right) => strcmp((string) ($right['sort_at'] ?? ''), (string) ($left['sort_at'] ?? '')));

        $paid = round(collect($rows)->where('payment_status', 'verified')->sum('amount_paid'), 2);
        $refunded = round(collect($rows)->where('payment_status', 'refunded')->sum('amount_paid'), 2);
        $balanceDue = round($balanceDue, 2);

        return response()->json([
            'data' => [
                'summary' => [
                    'total_paid' => $paid,
                    'total_paid_label' => RentalPaymentLedger::money($paid),
                    'paid_count' => collect($rows)->where('payment_status', 'verified')->count(),
                    'balance_due' => $balanceDue,
                    'balance_due_label' => RentalPaymentLedger::money($balanceDue),
                    'balance_due_count' => $balanceDueCount,
                    'pending_count' => collect($rows)->where('payment_status', 'pending')->count(),
                    'refunded' => $refunded,
                    'refunded_label' => RentalPaymentLedger::money($refunded),
                ],
                'payments' => $rows,
            ],
        ]);
    }

    private function passenger(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isMobileOperator()) {
            abort(403, 'Only passengers can view their payment history.');
        }

        return $user;
    }

    /**
     * @return \Illuminate\Support\Collection<int, BoatBooking>
     */
    private function bookings(User $user)
    {
        return BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->with([
                'boat' => fn ($query) => $query->withoutGlobalScope('organization')->withTrashed(),
                'commission',
                'rentalPayments',
            ])
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();
    }

    private function balanceDue(BoatBooking $booking): float
    {
        if ($booking->paymentStatus() !== BookingPaymentStatus::Verified
            || $booking->balanceIsSettled()
            || ! in_array($booking->status, self::BALANCE_DUE_STATUSES, true)) {
            return 0.0;
        }

        return round(max((float) ($booking->remaining_balance ?? 0), 0), 2);
    }

    private function timeLabel(BoatBooking $booking): ?string
    {
        $time = $booking->start_time;

        return $time instanceof Carbon ? $time->timezone('Asia/Manila')->format('g:i A') : null;
    }
}

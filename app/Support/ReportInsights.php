<?php

namespace App\Support;

use App\Enums\BoatBookingStatus;
use App\Models\BoatBooking;
use App\Models\OperatorNoShowReport;
use App\Models\PassengerNoShowReport;
use App\Models\RentalCommission;
use App\Models\RentalFeedback;
use App\Models\RentalRefundRequest;
use Illuminate\Support\Carbon;

/**
 * Flat, date-stamped records behind the Rentals, Payments and Boats & Operators reports.
 * The page filters them by period so every card, table and export uses the same rows.
 */
final class ReportInsights
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function refunds(int $organizationId): array
    {
        return RentalRefundRequest::query()
            ->where('organization_id', $organizationId)
            ->with(['booking.boat', 'reviewer'])
            ->latest('id')
            ->get()
            ->map(fn (RentalRefundRequest $refund) => [
                'id' => $refund->id,
                'booking_id' => $refund->booking?->id,
                'reference' => $refund->reference ?? 'RF-'.$refund->id,
                'booking_code' => $refund->booking?->code,
                'passenger' => $refund->booking?->customer_name,
                'boat' => $refund->booking?->boat?->name,
                'type_label' => $refund->typeLabel(),
                'reason' => $refund->reasonLabel(),
                'status' => $refund->status,
                'status_label' => $refund->statusLabel(),
                'amount_paid' => round(((int) ($refund->amount_paid ?? $refund->requested_amount ?? 0)) / 100, 2),
                'refund_amount' => $refund->status === RentalRefundRequest::STATUS_REFUNDED ? round(((int) $refund->refund_amount) / 100, 2) : 0.0,
                'approved_amount' => round(((int) ($refund->refund_amount ?? 0)) / 100, 2),
                'date' => $refund->created_at?->timezone('Asia/Manila')->toDateString(),
                'requested_at_label' => self::label($refund->created_at),
                'refunded_at_label' => self::label($refund->refunded_at),
                'processed_by' => $refund->reviewer?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function payouts(int $organizationId): array
    {
        return RentalCommission::query()
            ->where('organization_id', $organizationId)
            ->with(['booking.boat', 'operator'])
            ->latest('id')
            ->get()
            ->filter(fn (RentalCommission $commission) => $commission->booking !== null)
            ->map(function (RentalCommission $commission): array {
                $status = $commission->commission_reversed_at !== null ? 'refunded' : $commission->operator_payout_status?->value;

                return [
                    'id' => $commission->id,
                    'booking_id' => $commission->booking?->id,
                    'booking_code' => $commission->booking?->code,
                    'date' => $commission->booking?->booking_date?->toDateString(),
                    'date_label' => $commission->booking?->booking_date?->format('M d, Y'),
                    'operator' => $commission->operator?->full_name ?? 'Unassigned',
                    'boat' => $commission->booking?->boat?->name,
                    'collected' => round((float) $commission->operator_net_amount + (float) $commission->commission_amount, 2),
                    'commission' => $commission->commission_reversed_at !== null ? 0.0 : round((float) $commission->commission_amount, 2),
                    'operator_share' => round((float) $commission->operator_net_amount, 2),
                    'payout_status' => $status,
                    'payout_status_label' => $status === 'refunded' ? 'Refunded' : ($commission->operator_payout_status?->label() ?? '—'),
                    'method_label' => $commission->operator_payout_at ? ($commission->operator_payout_method?->label() ?? 'Recorded') : null,
                    'paid_at_label' => $commission->operator_payout_at?->timezone('Asia/Manila')->format('M d, Y · g:i A'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Cancellations and no-shows, one row per rental.
     *
     * @return list<array<string, mixed>>
     */
    public static function incidents(int $organizationId): array
    {
        $operatorReports = OperatorNoShowReport::query()
            ->whereHas('booking', fn ($query) => $query->where('organization_id', $organizationId))
            ->latest('id')
            ->get()
            ->groupBy('boat_booking_id');

        $passengerReports = PassengerNoShowReport::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->keyBy('boat_booking_id');

        return BoatBooking::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($operatorReports): void {
                $query->whereIn('status', [BoatBookingStatus::Cancelled, BoatBookingStatus::Rejected, BoatBookingStatus::PassengerNoShow])
                    ->orWhereIn('id', $operatorReports->keys()->all());
            })
            ->with(['boat.operator', 'cancelledBy'])
            ->orderByDesc('booking_date')
            ->get()
            ->map(function (BoatBooking $booking) use ($operatorReports, $passengerReports): array {
                $operatorReport = $operatorReports->get($booking->id)?->first();
                $passengerReport = $passengerReports->get($booking->id);

                [$kind, $kindLabel, $outcome] = match (true) {
                    $passengerReport !== null || $booking->status === BoatBookingStatus::PassengerNoShow => [
                        'passenger_no_show',
                        'Passenger no-show',
                        $passengerReport?->statusLabel() ?? 'Cancelled, no refund',
                    ],
                    $operatorReport !== null => [
                        'operator_no_show',
                        'Boat / operator no-show',
                        $operatorReport->incidentDisplayStatus()->label(),
                    ],
                    $booking->status === BoatBookingStatus::Rejected => ['cancellation', 'Rejected request', 'Rejected'],
                    default => ['cancellation', 'Cancellation', 'Cancelled'],
                };

                return [
                    'id' => $booking->id,
                    'booking_code' => $booking->code,
                    'date' => $booking->booking_date?->toDateString(),
                    'date_label' => $booking->booking_date?->format('M d, Y'),
                    'kind' => $kind,
                    'kind_label' => $kindLabel,
                    'cancelled_by' => self::cancelledBy($booking, $kind),
                    'reason' => $booking->cancellation_reason ?: ($operatorReport?->reasonLabel() ?? '—'),
                    'boat' => $booking->boat?->name,
                    'operator' => $booking->boat?->operator?->full_name,
                    'passenger' => $booking->customer_name,
                    'outcome' => $outcome,
                    'amount' => round((float) ($booking->rental_price ?? 0), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One row per rental, used to rank operators for any period.
     *
     * @return list<array<string, mixed>>
     */
    public static function operatorActivity(int $organizationId): array
    {
        $ratings = RentalFeedback::query()
            ->where('organization_id', $organizationId)
            ->pluck('rating', 'boat_booking_id');

        $operatorNoShows = OperatorNoShowReport::query()
            ->whereHas('booking', fn ($query) => $query->where('organization_id', $organizationId))
            ->whereIn('status', ['verified'])
            ->pluck('boat_booking_id')
            ->flip();

        $shares = RentalCommission::query()
            ->where('organization_id', $organizationId)
            ->get(['boat_booking_id', 'operator_net_amount', 'commission_reversed_at'])
            ->keyBy('boat_booking_id');

        return BoatBooking::query()
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', [BoatBookingStatus::Pending, BoatBookingStatus::Rejected])
            ->with(['boat.operator'])
            ->get()
            ->filter(fn (BoatBooking $booking) => $booking->boat?->operator !== null)
            ->map(function (BoatBooking $booking) use ($ratings, $operatorNoShows, $shares): array {
                $share = $shares->get($booking->id);

                return [
                    'booking_id' => $booking->id,
                    'date' => $booking->booking_date?->toDateString(),
                    'operator_id' => $booking->boat->operator->id,
                    'operator' => $booking->boat->operator->full_name,
                    'boat' => $booking->boat->name,
                    'completed' => $booking->status === BoatBookingStatus::Completed,
                    'readiness_confirmed' => $booking->operator_availability_confirmed_at !== null,
                    'operator_no_show' => $operatorNoShows->has($booking->id),
                    'rating' => $ratings->get($booking->id),
                    'earnings' => $share && $share->commission_reversed_at === null ? round((float) $share->operator_net_amount, 2) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    private static function cancelledBy(BoatBooking $booking, string $kind): string
    {
        if ($kind === 'passenger_no_show') {
            return 'Operator (passenger no-show)';
        }

        if ($kind === 'operator_no_show') {
            return 'Passenger report';
        }

        $reason = strtolower((string) $booking->cancellation_reason);
        if (str_contains($reason, 'weather')) {
            return 'Weather';
        }

        $role = $booking->cancelledBy?->role?->value;

        return match (true) {
            (int) $booking->cancelled_by === (int) $booking->user_id && $booking->user_id !== null => 'Passenger',
            $role === 'operator' => 'Operator',
            in_array($role, ['administrator', 'user'], true) => 'Barangay admin',
            default => 'System',
        };
    }

    private static function label(?\DateTimeInterface $at): ?string
    {
        return $at ? Carbon::instance($at)->timezone('Asia/Manila')->format('M d, Y · g:i A') : null;
    }
}

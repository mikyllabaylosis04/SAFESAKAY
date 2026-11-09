<?php

namespace App\Http\Controllers\Api;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\OperatorPayoutStatus;
use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\BoatBookingActivity;
use App\Models\RentalCommission;
use App\Models\User;
use App\Services\RentalCommissionService;
use App\Support\RentalPaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OperatorTransactionController extends Controller
{
    public function __construct(private readonly RentalPaymentLedger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->operator($request);
        app(\App\Services\RentalCommissionService::class)->syncFullRentalPayments((int) $user->organization_id);
        $period = $this->period($request->query('period'));
        $rows = $this->rowsFor($user);

        return response()->json([
            'data' => [
                'period' => $period,
                'summary' => $this->summary($rows, $period),
                'transactions' => array_values(array_filter(
                    $rows,
                    fn (array $row) => $this->inPeriod($row, $period),
                )),
            ],
        ]);
    }

    public function show(Request $request, int $booking): JsonResponse
    {
        $user = $this->operator($request);
        app(\App\Services\RentalCommissionService::class)->syncFullRentalPayments((int) $user->organization_id);
        $record = $this->bookings($user)->firstWhere('id', $booking);

        if (! $record instanceof BoatBooking) {
            abort(404);
        }

        $rows = $this->rowsForBooking($record);
        $receipt = filled($record->payment_receipt_path)
            && $record->paymentStatus() === BookingPaymentStatus::Verified;

        return response()->json([
            'data' => [
                'booking' => $this->bookingPayload($record),
                'payments' => $rows,
                'earnings' => $this->earnings($record, $rows),
                'timeline' => $this->timeline($record),
                'receipt_available' => $receipt,
                'receipt_url' => $receipt ? '/api/bookings/'.$record->id.'/receipt' : null,
            ],
        ]);
    }

    private function operator(Request $request): User
    {
        $user = $request->user();

        if (! $user?->isMobileOperator()) {
            abort(403, 'Only boat operators can view these transactions.');
        }

        return $user;
    }

    private function period(mixed $value): string
    {
        return in_array($value, ['this_month', 'last_month', 'all'], true) ? $value : 'this_month';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function periodRange(string $period): ?array
    {
        $now = now('Asia/Manila');

        return match ($period) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'all' => null,
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function inPeriod(array $row, string $period): bool
    {
        $range = $this->periodRange($period);
        if ($range === null) {
            return true;
        }

        $at = $row['sort_at'] ?? null;
        if (! is_string($at) || $at === '') {
            return false;
        }

        $moment = Carbon::parse($at)->timezone('Asia/Manila');

        return $moment->betweenIncluded($range[0], $range[1]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(User $user): array
    {
        $rows = [];
        foreach ($this->bookings($user) as $booking) {
            foreach ($this->rowsForBooking($booking) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, function (array $left, array $right) {
            return strcmp((string) ($right['sort_at'] ?? ''), (string) ($left['sort_at'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return \Illuminate\Support\Collection<int, BoatBooking>
     */
    private function bookings(User $user)
    {
        return BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->with([
                'boat' => fn ($query) => $query->withoutGlobalScope('organization')->with('operator'),
                'commission',
                'rentalPayments',
                'activities',
                'user',
            ])
            ->whereHas('boat', function ($boat) use ($user) {
                $boat->withoutGlobalScope('organization')
                    ->when(
                        $user->organization_id,
                        fn ($query) => $query->where('organization_id', $user->organization_id),
                    )
                    ->whereHas('operator', fn ($operator) => $operator->where('user_id', $user->id));
            })
            ->when(
                $user->organization_id,
                fn ($query) => $query->where('organization_id', $user->organization_id),
            )
            ->latest('id')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForBooking(BoatBooking $booking): array
    {
        $lines = $this->paymentLines($booking);
        if ($lines === []) {
            $lines = [$this->placeholderLine($booking)];
        }

        $commission = $booking->commission;
        $reversed = $commission?->commission_reversed_at !== null;
        $recordedCommission = $reversed ? 0.0 : round((float) ($commission->commission_amount ?? $booking->commission_amount ?? 0), 2);
        $recordedNet = $reversed ? 0.0 : round((float) ($commission->operator_net_amount ?? $booking->operator_net_amount ?? 0), 2);
        $rate = round((float) ($commission->commission_percentage ?? $booking->commission_percentage ?? 0), 2);
        $payout = $this->payout($booking, $commission);
        $verifiedIndexes = [];

        foreach ($lines as $index => $line) {
            if ($line['payment_status'] === 'verified') {
                $verifiedIndexes[] = $index;
            }
        }

        $owner = null;
        if (! $reversed && $recordedCommission > 0 && $verifiedIndexes !== []) {
            $owner = $verifiedIndexes[0];
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            $carries = $owner === $index;
            $share = null;
            $deducted = null;
            if ($line['payment_status'] === 'verified' && ! $reversed) {
                $deducted = $carries ? $recordedCommission : 0.0;
                $share = round((float) $line['amount_paid'] - $deducted, 2);
            }

            $rows[] = [
                ...$line,
                'booking_id' => $booking->id,
                'booking_reference' => $booking->code,
                'booking_status' => $booking->status?->value,
                'booking_status_label' => $booking->status?->label() ?? '',
                'passenger_name' => $booking->customer_name ?: ($booking->user?->name ?? ''),
                'boat_name' => $booking->boat?->name,
                'rental_date' => $booking->booking_date?->toDateString(),
                'rental_date_label' => $booking->booking_date?->timezone('Asia/Manila')->format('M j, Y'),
                'rental_time_label' => $this->timeLabel($booking),
                'rental_total' => round((float) ($booking->rental_price ?? 0), 2),
                'rental_total_label' => $this->money((float) ($booking->rental_price ?? 0)),
                'remaining_balance' => round((float) ($booking->remaining_balance ?? 0), 2),
                'remaining_balance_label' => $this->money((float) ($booking->remaining_balance ?? 0)),
                'commission_rate' => $rate,
                'commission_rate_label' => $this->rateLabel($rate),
                'carries_commission' => $carries,
                'commission_deducted' => $deducted,
                'commission_deducted_label' => $deducted === null ? null : '−'.$this->money($deducted),
                'operator_share' => $share,
                'operator_share_label' => $share === null ? null : $this->money($share),
                'booking_commission' => $recordedCommission,
                'booking_operator_net' => $recordedNet,
                'commission_reversed' => $reversed,
                'earnings_basis' => $this->basis($booking, $commission),
                'payout_status' => $payout['status'],
                'payout_label' => $payout['label'],
                'payout_at' => $payout['paid_at'],
                'payout_at_label' => $payout['paid_at_label'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paymentLines(BoatBooking $booking): array
    {
        return $this->ledger->lines($booking);
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholderLine(BoatBooking $booking): array
    {
        $awaiting = in_array($booking->status, [
            BoatBookingStatus::AwaitingPayment,
            BoatBookingStatus::Pending,
            BoatBookingStatus::Confirmed,
        ], true) && $booking->paymentStatus() !== BookingPaymentStatus::Verified;

        return $this->line(
            $booking,
            'booking-'.$booking->id,
            $awaiting ? $this->expectedKind($booking) : 'downpayment',
            0,
            null,
            $awaiting ? 'awaiting' : 'pending',
            $booking->booking_date?->copy()->timezone('Asia/Manila'),
            null,
            null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function line(
        BoatBooking $booking,
        string $id,
        string $kind,
        float $amount,
        ?string $method,
        string $status,
        ?Carbon $at,
        ?string $transactionId,
        ?string $provider,
    ): array {
        return $this->ledger->line($booking, $id, $kind, $amount, $method, $status, $at, $transactionId, $provider);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, string $period): array
    {
        $payments = 0.0;
        $commission = 0.0;
        $payouts = 0.0;
        $paidOutCount = 0;
        $awaitingCount = 0;
        $processingCount = 0;
        $onHoldCount = 0;
        $failedCount = 0;
        $seen = [];

        foreach ($rows as $row) {
            if (! $this->inPeriod($row, $period) || $row['payment_status'] !== 'verified' || $row['commission_reversed'] === true) {
                continue;
            }

            $payments += (float) $row['amount_paid'];
            $bookingId = (int) $row['booking_id'];
            if (isset($seen[$bookingId])) {
                continue;
            }

            $seen[$bookingId] = true;
            $commission += (float) $row['booking_commission'];
            if ($row['payout_status'] === 'paid_out') {
                $paidAt = $row['payout_at'] ?? null;
                $payoutInPeriod = $period === 'all' || (is_string($paidAt) && $this->inPeriod(['sort_at' => $paidAt], $period));
                if ($payoutInPeriod) {
                    $payouts += (float) $row['booking_operator_net'];
                    $paidOutCount++;
                }
            }
            match ($row['payout_status']) {
                'ready_for_payout' => $awaitingCount++,
                'processing' => $processingCount++,
                'on_hold' => $onHoldCount++,
                'cancelled' => $failedCount++,
                default => null,
            };
        }

        $payments = round($payments, 2);
        $commission = round($commission, 2);
        $net = round($payments - $commission, 2);
        $payouts = round($payouts, 2);

        return [
            'period' => $period,
            'period_label' => match ($period) {
                'last_month' => 'Last Month',
                'all' => 'All Time',
                default => 'This Month',
            },
            'verified_payments' => $payments,
            'verified_payments_label' => $this->money($payments),
            'commission_deducted' => $commission,
            'commission_deducted_label' => '−'.$this->money($commission),
            'net_earnings' => $net,
            'net_earnings_label' => $this->money($net),
            'payouts_received' => $paidOutCount > 0 ? $payouts : null,
            'payouts_received_label' => $paidOutCount > 0 ? $this->money($payouts) : null,
            'payouts_received_count' => $paidOutCount,
            'awaiting_payout_count' => $awaitingCount,
            'processing_payout_count' => $processingCount,
            'on_hold_payout_count' => $onHoldCount,
            'failed_payout_count' => $failedCount,
            'formula' => 'Verified passenger payments minus the recorded commission.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function earnings(BoatBooking $booking, array $rows): array
    {
        $commission = $booking->commission;
        $reversed = $commission?->commission_reversed_at !== null;
        $verified = 0.0;
        foreach ($rows as $row) {
            if ($row['payment_status'] === 'verified') {
                $verified += (float) $row['amount_paid'];
            }
        }
        $verified = round($verified, 2);
        $rate = round((float) ($commission->commission_percentage ?? $booking->commission_percentage ?? 0), 2);
        $deducted = $reversed ? 0.0 : round((float) ($commission->commission_amount ?? $booking->commission_amount ?? 0), 2);
        $net = round($verified - $deducted, 2);
        $payout = $this->payout($booking, $commission);

        return [
            'rental_total' => round((float) ($booking->rental_price ?? 0), 2),
            'rental_total_label' => $this->money((float) ($booking->rental_price ?? 0)),
            'verified_paid' => $verified,
            'verified_paid_label' => $this->money($verified),
            'remaining_balance' => round((float) ($booking->remaining_balance ?? 0), 2),
            'remaining_balance_label' => $this->money((float) ($booking->remaining_balance ?? 0)),
            'commission_rate' => $rate,
            'commission_rate_label' => $this->rateLabel($rate),
            'commission_deducted' => $reversed ? 0 : $deducted,
            'commission_deducted_label' => '−'.$this->money($deducted),
            'operator_net' => $net,
            'operator_net_label' => $this->money($net),
            'recorded_operator_net_label' => $this->money((float) ($commission->operator_net_amount ?? $booking->operator_net_amount ?? 0)),
            'basis' => $this->basis($booking, $commission),
            'reversed' => $reversed,
            'payout_status' => $payout['status'],
            'payout_label' => $payout['label'],
            'payout_at_label' => $payout['paid_at_label'],
            'payout_reference' => $commission?->operator_payout_reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(BoatBooking $booking): array
    {
        $approved = $booking->activities->first(
            fn (BoatBookingActivity $activity) => in_array($activity->event, ['operator_accepted', 'admin_approved'], true),
        );

        return [
            'id' => $booking->id,
            'reference' => $booking->code,
            'status' => $booking->status?->value,
            'status_label' => $booking->status?->label() ?? '',
            'passenger_name' => $booking->customer_name ?: ($booking->user?->name ?? ''),
            'passenger_contact' => $booking->contact_number,
            'boat_name' => $booking->boat?->name,
            'operator_name' => $booking->boat?->operator?->full_name,
            'rental_type' => $booking->package,
            'origin' => $booking->pickup_point,
            'destination' => $booking->destination,
            'passenger_count' => $booking->passenger_count,
            'rental_date_label' => $booking->booking_date?->timezone('Asia/Manila')->format('M j, Y'),
            'departure_time_label' => $this->timeLabel($booking),
            'rental_price_label' => $this->money((float) ($booking->rental_price ?? 0)),
            'approved_at_label' => $approved?->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
        ];
    }

    /**
     * @return list<array{label: string, at_label: string}>
     */
    private function timeline(BoatBooking $booking): array
    {
        $steps = [];
        $push = function (string $label, ?Carbon $at) use (&$steps) {
            if (! $at instanceof Carbon) {
                return;
            }
            $steps[] = [
                'label' => $label,
                'at_label' => $at->timezone('Asia/Manila')->format('M j, Y g:i A'),
            ];
        };

        $activityAt = function (array $events) use ($booking): ?Carbon {
            $match = $booking->activities->first(
                fn (BoatBookingActivity $activity) => in_array($activity->event, $events, true),
            );

            return $match?->created_at;
        };

        $push('Rental Request Submitted', $activityAt(['submitted', 'walk_in_cash_created', 'walk_in_gcash_created']) ?? $booking->created_at);
        $push('Operator Approved Request', $activityAt(['operator_accepted', 'admin_approved']));
        $push('Passenger Payment Verified', $activityAt(['payment_verified', 'xendit_payment_verified', 'paymongo_payment_verified']) ?? $booking->payment_verified_at);

        if ($booking->payment_verified_at && in_array($booking->status, [
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
            BoatBookingStatus::Completed,
            BoatBookingStatus::PassengerNoShow,
        ], true)) {
            $push('Booking Confirmed', $booking->payment_verified_at);
        }

        $push('Remaining Balance Paid', $activityAt(['balance_paid']) ?? ($booking->balanceIsSettled() ? $booking->balance_paid_at : null));
        $push('Trip Completed', $activityAt(['completed']) ?? $booking->completed_at);

        $commission = $booking->commission;
        if ($commission?->operator_payout_at && $commission->operator_payout_status === OperatorPayoutStatus::PaidOut) {
            $push('Operator Payout Paid Out', $commission->operator_payout_at);
        }

        return $steps;
    }

    /**
     * @return array{status: ?string, label: ?string, paid_at: ?string, paid_at_label: ?string}
     */
    private function payout(BoatBooking $booking, ?RentalCommission $commission): array
    {
        $empty = ['status' => null, 'label' => null, 'paid_at' => null, 'paid_at_label' => null];
        if (! $commission instanceof RentalCommission || ! $booking->paysBarangayGcash()) {
            return $empty;
        }

        if ($commission->commission_reversed_at !== null || $commission->operator_payout_status === OperatorPayoutStatus::Cancelled) {
            return ['status' => 'cancelled', 'label' => 'Failed', 'paid_at' => null, 'paid_at_label' => null];
        }

        if ($commission->operator_payout_at !== null || $commission->operator_payout_status === OperatorPayoutStatus::PaidOut) {
            return [
                'status' => 'paid_out',
                'label' => 'Paid Out',
                'paid_at' => $commission->operator_payout_at?->toIso8601String(),
                'paid_at_label' => $commission->operator_payout_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
            ];
        }

        $reference = (string) $commission->operator_payout_reference;
        if (str_starts_with($reference, 'xendit:')) {
            return ['status' => 'processing', 'label' => 'Processing', 'paid_at' => null, 'paid_at_label' => null];
        }

        $status = $commission->operator_payout_status ?? OperatorPayoutStatus::OnHold;

        return match ($status) {
            OperatorPayoutStatus::ReadyForPayout => ['status' => 'ready_for_payout', 'label' => 'Awaiting Payout', 'paid_at' => null, 'paid_at_label' => null],
            OperatorPayoutStatus::PaidOut => ['status' => 'paid_out', 'label' => 'Paid Out', 'paid_at' => null, 'paid_at_label' => null],
            OperatorPayoutStatus::Cancelled => ['status' => 'cancelled', 'label' => 'Failed', 'paid_at' => null, 'paid_at_label' => null],
            default => ['status' => 'on_hold', 'label' => 'On Hold', 'paid_at' => null, 'paid_at_label' => null],
        };
    }

    private function commissionBase(BoatBooking $booking, ?RentalCommission $commission): string
    {
        if (! $commission instanceof RentalCommission) {
            return 'recorded';
        }

        $percent = (float) ($commission->commission_percentage ?? $booking->commission_percentage ?? 0);
        $stored = round((float) $commission->commission_amount, 2);
        $service = app(RentalCommissionService::class);
        $onFull = $service->commissionAmount((float) ($booking->rental_price ?? 0), $percent);
        $onDown = $service->commissionAmount((float) ($booking->down_payment_amount ?? 0), $percent);

        if (abs($stored - $onFull) < 0.02 && abs($onFull - $onDown) >= 0.02) {
            return 'full';
        }

        if (abs($stored - $onDown) < 0.02) {
            return 'downpayment';
        }

        return 'recorded';
    }

    private function basis(BoatBooking $booking, ?RentalCommission $commission): ?string
    {
        if (! $commission instanceof RentalCommission) {
            return null;
        }

        return match ($this->commissionBase($booking, $commission)) {
            'full' => 'Commission is a share of the full rental price, deducted from the first payment the barangay receives.',
            'downpayment' => 'Commission was recorded on the verified downpayment (older rental).',
            default => 'Commission is the amount recorded for this rental.',
        };
    }

    private function expectedKind(BoatBooking $booking): string
    {
        return $this->ledger->expectedKind($booking);
    }

    private function timeLabel(BoatBooking $booking): ?string
    {
        $time = $booking->start_time;
        if (! $time instanceof Carbon) {
            return null;
        }

        return $time->timezone('Asia/Manila')->format('g:i A');
    }

    private function money(float $amount): string
    {
        return '₱'.number_format(abs($amount), 2);
    }

    private function rateLabel(float $rate): string
    {
        $formatted = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return ($formatted === '' ? '0' : $formatted).'%';
    }
}

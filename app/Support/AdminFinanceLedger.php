<?php

namespace App\Support;

use App\Enums\CommissionSettlementStatus;
use App\Models\BoatBooking;
use App\Models\OrganizationRoute;
use App\Models\RentalCommission;
use App\Services\RentalCommissionService;
use Illuminate\Support\Collection;

/**
 * Single source for the admin Payments and My Commission pages. Payments come from the
 * booking payment ledger; commission comes from rental_commissions (same as Operator Payouts).
 */
class AdminFinanceLedger
{
    public function __construct(
        private readonly RentalPaymentLedger $ledger,
        private readonly RentalCommissionService $commissions,
    ) {}

    /**
     * @return Collection<int, BoatBooking>
     */
    public function rentals(int $organizationId): Collection
    {
        return BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('rental_price', '>', 0)
            ->with(['boat', 'commission', 'rentalPayments'])
            ->latest('id')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paymentRows(int $organizationId): array
    {
        $rows = [];

        foreach ($this->rentals($organizationId) as $booking) {
            $lines = $this->ledger->lines($booking);
            if ($lines === []) {
                continue;
            }

            $commission = $booking->commission;
            $reversed = $commission?->commission_reversed_at !== null;
            $totalCommission = $reversed ? 0.0 : (float) ($commission?->commission_amount ?? $this->commissions->fullRentalCommission($booking));
            $rate = (float) ($commission?->commission_percentage ?? $booking->commission_percentage ?? 0);
            $owner = null;

            foreach ($lines as $index => $line) {
                if ($line['payment_status'] === 'verified' && $this->receivedByBarangay($booking, $line)) {
                    $owner = $index;
                    break;
                }
            }

            $balanceAmount = round(max((float) $booking->rental_price - (float) ($booking->down_payment_amount ?? 0), 0), 2);
            $balancePaidOnline = collect($lines)->contains(
                fn (array $line) => $line['payment_type'] === 'balance' && $this->receivedByBarangay($booking, $line),
            );
            $balanceStatus = match (true) {
                $balanceAmount <= 0.05 => null,
                $balancePaidOnline => 'Paid online',
                $booking->balanceIsSettled() => 'Paid on trip',
                default => 'Due on trip',
            };

            foreach ($lines as $index => $line) {
                $amount = (float) $line['amount_paid'];
                $toBarangay = $this->receivedByBarangay($booking, $line);

                if ($line['payment_type'] === 'balance' && ! $toBarangay) {
                    continue;
                }
                $lineCommission = $owner === $index ? min($amount, $totalCommission) : 0.0;
                $type = match ($line['payment_type']) {
                    'full' => 'full_rental',
                    'balance' => 'balance',
                    default => 'downpayment',
                };

                $rows[] = [
                    'id' => $line['id'],
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->code,
                    'passenger_name' => $booking->customer_name,
                    'boat_name' => $booking->boat?->name,
                    'payment_type' => $type,
                    'amount_paid' => $amount,
                    'rental_total' => round((float) $booking->rental_price, 2),
                    'commission_amount' => $lineCommission,
                    'operator_share' => round($amount - $lineCommission, 2),
                    'status' => $line['payment_status'],
                    'is_verified' => $line['payment_status'] === 'verified',
                    'paid_at' => $line['paid_at'],
                    'verified_at' => $line['payment_status'] === 'verified' ? $line['paid_at'] : null,
                    'payment_method' => $line['payment_method_label'],
                    'transaction_id' => $line['transaction_id'],
                    'received_by' => $toBarangay ? 'Barangay' : 'Operator',
                    'received_by_label' => $toBarangay ? 'SafeSakay' : 'Boat operator',
                    'partially_refunded' => (bool) ($line['partially_refunded'] ?? false),
                    'booking_href' => '/bookings/requests/'.$booking->id,
                    'remaining_balance' => $type === 'downpayment' && ! $booking->balanceIsSettled()
                        ? round(max(0, (float) $booking->remaining_balance), 2)
                        : 0.0,
                    'balance_amount' => $type === 'downpayment' ? $balanceAmount : 0.0,
                    'balance_status' => $type === 'downpayment' ? $balanceStatus : null,
                    'balance_paid_at' => $type === 'downpayment' && $balanceStatus === 'Paid on trip'
                        ? $booking->balance_paid_at?->format('M d, Y · g:i A')
                        : null,
                    'commission_rate_label' => OrganizationRoute::formatPercent($rate),
                    'sort_at' => $line['sort_at'],
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) ($b['sort_at'] ?? ''), (string) ($a['sort_at'] ?? '')));

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    public function paymentStats(array $rows, int $organizationId): array
    {
        $received = collect($rows)->filter(fn (array $row) => $row['is_verified'] && $row['received_by'] === 'Barangay');

        return [
            'total_payments' => round($received->sum('amount_paid'), 2),
            'full_payments' => round($received->where('payment_type', 'full_rental')->sum('amount_paid'), 2),
            'downpayments' => round($received->where('payment_type', 'downpayment')->sum('amount_paid'), 2),
            'total_commission' => $this->commissionTotal($organizationId),
        ];
    }

    public function commissionTotal(int $organizationId): float
    {
        return round((float) RentalCommission::query()
            ->where('organization_id', $organizationId)
            ->whereNull('commission_reversed_at')
            ->sum('commission_amount'), 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commissionRows(int $organizationId): array
    {
        return RentalCommission::query()
            ->where('organization_id', $organizationId)
            ->with(['booking.rentalPayments', 'booking.boat'])
            ->latest('id')
            ->get()
            ->map(function (RentalCommission $commission) {
                $booking = $commission->booking;
                $status = $this->commissionStatus($commission);
                $reference = $booking?->rentalPayments
                    ->firstWhere('status', 'paid')?->provider_payment_id
                    ?? $booking?->gcash_reference
                    ?? $commission->gcash_reference;

                return [
                    'id' => $commission->id,
                    'commission_id' => 'COMM-'.str_pad((string) $commission->id, 5, '0', STR_PAD_LEFT),
                    'transaction_id' => $reference,
                    'rental_reference' => $booking?->code ?? '—',
                    'customer_name' => $booking?->customer_name ?? '—',
                    'boat_name' => $booking?->boat?->name,
                    'total_rental_payment_label' => OrganizationRoute::formatPeso($commission->rental_amount),
                    'commission_rate_label' => OrganizationRoute::formatPercent((float) $commission->commission_percentage),
                    'commission_amount_raw' => (float) $commission->commission_amount,
                    'commission_amount_label' => OrganizationRoute::formatPeso($commission->commission_amount),
                    'operator_share_label' => OrganizationRoute::formatPeso($commission->operator_net_amount),
                    'earned_at_label' => ($commission->payment_verified_at ?? $commission->created_at)
                        ?->timezone('Asia/Manila')
                        ->format('M d, Y · g:i A'),
                    'status' => $status,
                    'status_label' => match ($status) {
                        'paid' => $commission->requiresOperatorRemittance() ? 'Received' : 'Collected',
                        'pending' => $commission->settlement_status === CommissionSettlementStatus::Overdue ? 'Overdue' : 'Awaiting Operator',
                        default => 'Reversed',
                    },
                    'collection_label' => $commission->requiresOperatorRemittance()
                        ? 'Operator remits (older rental paid to operator)'
                        : 'Deducted from passenger payment to barangay',
                    'can_mark_paid' => $status === 'pending' && ($commission->canRecordCashPayment() || $commission->canVerify()),
                    'booking_href' => $booking ? '/bookings/requests/'.$booking->id : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function commissionStats(array $rows): array
    {
        $rows = collect($rows);

        return [
            'total_commission' => OrganizationRoute::formatPeso($rows->whereIn('status', ['paid', 'pending'])->sum('commission_amount_raw')),
            'pending_commission' => OrganizationRoute::formatPeso($rows->where('status', 'pending')->sum('commission_amount_raw')),
            'paid_commission' => OrganizationRoute::formatPeso($rows->where('status', 'paid')->sum('commission_amount_raw')),
            'reversed_commission' => OrganizationRoute::formatPeso($rows->where('status', 'reversed')->sum('commission_amount_raw')),
            'transaction_count' => $rows->count(),
        ];
    }

    private function commissionStatus(RentalCommission $commission): string
    {
        if ($commission->commission_reversed_at !== null || $commission->settlement_status === CommissionSettlementStatus::Waived) {
            return 'reversed';
        }

        if (! $commission->requiresOperatorRemittance() || $commission->settlement_status->isReceived()) {
            return 'paid';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function receivedByBarangay(BoatBooking $booking, array $line): bool
    {
        if (! $booking->paysBarangayGcash()) {
            return false;
        }

        if ($line['payment_type'] === 'balance') {
            return $line['provider'] === 'xendit';
        }

        return true;
    }
}

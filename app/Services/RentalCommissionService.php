<?php

namespace App\Services;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingBalanceStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\CommissionSettlementMethod;
use App\Enums\CommissionSettlementStatus;
use App\Enums\OperatorNoShowPassengerChoice;
use App\Enums\OperatorNoShowRefundStatus;
use App\Enums\OperatorNoShowReportStatus;
use App\Enums\OperatorPayoutStatus;
use App\Enums\UserRole;
use App\Models\BoatBooking;
use App\Models\OperatorNoShowReport;
use App\Models\Organization;
use App\Models\OrganizationCommissionSetting;
use App\Models\OrganizationRoute;
use App\Models\RentalCommission;
use App\Models\RentalCommissionEvent;
use App\Models\User;
use App\Notifications\CommissionSettlementNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RentalCommissionService
{
    /**
     * @return array{
     *     enabled: bool,
     *     percentage: float,
     *     amount: float,
     *     operator_net: float,
     *     percentage_label: string,
     *     amount_label: string,
     *     operator_net_label: string
     * }
     */
    public function breakdownForOrganization(?int $organizationId, float $rentalPrice): array
    {
        $settings = $this->settings($organizationId);
        $price = round(max(0, $rentalPrice), 2);
        $enabled = (bool) ($settings?->commission_enabled) && $settings->percentageAmount() > 0;
        $percent = $enabled ? $settings->percentageAmount() : 0.0;
        $amount = $enabled ? $this->commissionAmount($price, $percent) : 0.0;
        $net = round($price - $amount, 2);

        return [
            'enabled' => $enabled,
            'percentage' => $percent,
            'amount' => $amount,
            'operator_net' => $net,
            'percentage_label' => $percent > 0 ? OrganizationRoute::formatPercent($percent) : '0%',
            'amount_label' => OrganizationRoute::formatPeso($amount),
            'operator_net_label' => OrganizationRoute::formatPeso($net),
        ];
    }

    /**
     * @return array{
     *     commission_enabled: bool,
     *     commission_percentage: string|null,
     *     commission_amount: string|null,
     *     operator_net_amount: string|null
     * }
     */
    /**
     * Commission is always a percentage of the full rental price. The operator net is what the
     * barangay pays out from the amount it collects up front (the down payment, or the full price).
     */
    public function snapshotForBooking(?int $organizationId, ?float $rentalPrice, ?float $downPaymentAmount = null): array
    {
        $price = $rentalPrice === null ? 0.0 : (float) $rentalPrice;
        $down = $downPaymentAmount !== null && $downPaymentAmount > 0 ? (float) $downPaymentAmount : null;
        $collected = $down ?? $price;
        $breakdown = $this->breakdownForOrganization($organizationId, $price);

        if (! $breakdown['enabled'] || $price <= 0) {
            return [
                'commission_enabled' => false,
                'commission_percentage' => null,
                'commission_amount' => null,
                'operator_net_amount' => $collected > 0 ? number_format($collected, 2, '.', '') : null,
            ];
        }

        $amount = min($breakdown['amount'], $collected);

        return [
            'commission_enabled' => true,
            'commission_percentage' => number_format($breakdown['percentage'], 2, '.', ''),
            'commission_amount' => number_format($amount, 2, '.', ''),
            'operator_net_amount' => number_format(max(0, round($collected - $amount, 2)), 2, '.', ''),
        ];
    }

    public function fullRentalCommission(BoatBooking $booking): float
    {
        if (! $booking->commission_enabled) {
            return 0.0;
        }

        $percent = (float) ($booking->commission_percentage ?? $this->settings($booking->organization_id)?->percentageAmount() ?? 0);

        return $percent > 0 ? $this->commissionAmount((float) ($booking->rental_price ?? 0), $percent) : 0.0;
    }

    /**
     * Amount of passenger money the barangay holds for this rental (down payment plus any
     * balance paid online). Balance paid straight to the operator is not included.
     */
    public function barangayCollectedAmount(BoatBooking $booking): float
    {
        $rental = round((float) ($booking->rental_price ?? 0), 2);
        $down = round((float) ($booking->down_payment_amount ?? 0), 2);

        if (! $booking->paysBarangayGcash()) {
            return $booking->balanceIsSettled() ? $rental : $down;
        }

        if ($this->paidFullRentalOnline($booking)) {
            $refunded = (int) $booking->rentalPayments()->where('status', 'paid')->sum('refunded_amount');

            return round(max(0, $rental - $refunded / 100), 2);
        }

        $collected = $down;
        if ($down <= 0 || $rental - $down < 0.05) {
            $collected = $rental;
        }

        $onlineBalance = (int) $booking->rentalPayments()
            ->where('payment_type', 'full_payment')
            ->where('status', 'paid')
            ->sum('amount');
        $collected += $onlineBalance / 100;

        return round(min($rental > 0 ? $rental : $collected, $collected), 2);
    }

    public function paidFullRentalOnline(BoatBooking $booking): bool
    {
        return $booking->rentalPayments()
            ->where('payment_type', 'full_rental')
            ->where('status', 'paid')
            ->exists();
    }

    /**
     * Repairs rentals paid in full online before the balance was cleared: the booking keeps no
     * balance and the commission counts the whole rental as collected by the barangay.
     */
    public function syncFullRentalPayments(?int $organizationId = null): void
    {
        BoatBooking::query()
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('remaining_balance', '>', 0)
            ->whereHas('rentalPayments', fn ($query) => $query->where('payment_type', 'full_rental')->where('status', 'paid'))
            ->with(['commission', 'boat.operator'])
            ->each(function (BoatBooking $booking): void {
                $booking->update([
                    'remaining_balance' => '0.00',
                    'balance_status' => BookingBalanceStatus::Paid,
                ]);

                $commission = $booking->commission;
                if (! $commission instanceof RentalCommission || $commission->operator_payout_at !== null || $commission->commission_reversed_at !== null) {
                    return;
                }

                $this->refreshBookingCommissionFromDownpayment($booking->fresh());
                $fresh = $booking->fresh();

                $commission->update([
                    'verified_downpayment' => number_format($this->barangayCollectedAmount($fresh), 2, '.', ''),
                    'commission_amount' => $fresh->commission_amount,
                    'operator_net_amount' => $fresh->operator_net_amount,
                ]);
            });
    }

    public function generateIfEligible(BoatBooking $booking): ?RentalCommission
    {
        $booking->refresh();

        if (! $this->shouldGenerate($booking)) {
            return $booking->commission;
        }

        $booking->loadMissing('boat.operator');

        $verifiedAmount = $this->paidFullRentalOnline($booking) ? $booking->rental_price : $booking->down_payment_amount;
        $downpayment = (float) ($verifiedAmount ?? 0);
        $this->refreshBookingCommissionFromDownpayment($booking);

        $collectedWithRental = $booking->paysBarangayGcash();
        $verifiedAt = $booking->payment_verified_at ?? now();
        $dueAt = $collectedWithRental
            ? null
            : $this->settlementDueAt($booking->organization_id, $verifiedAt);

        $payoutStatus = $collectedWithRental
            ? $this->resolveOperatorPayoutStatus($booking)
            : OperatorPayoutStatus::OnHold;

        $commission = RentalCommission::query()->firstOrCreate(
            ['boat_booking_id' => $booking->id],
            [
                'organization_id' => $booking->organization_id,
                'operator_id' => $booking->boat?->operator?->id,
                'rental_amount' => $booking->rental_price,
                'verified_downpayment' => $downpayment > 0 ? $verifiedAmount : null,
                'commission_percentage' => $booking->commission_percentage,
                'commission_amount' => $booking->commission_amount,
                'operator_net_amount' => $booking->operator_net_amount,
                'payment_verified_at' => $verifiedAt,
                'settlement_due_at' => $dueAt,
                'settlement_status' => $collectedWithRental
                    ? CommissionSettlementStatus::CollectedWithRental
                    : CommissionSettlementStatus::PendingSettlement,
                'operator_payout_status' => $payoutStatus,
                'gcash_reference' => $collectedWithRental ? $booking->gcash_reference : null,
                'verified_by' => $collectedWithRental ? $booking->payment_verified_by : null,
                'verified_at' => $collectedWithRental ? now() : null,
            ],
        );

        if ($commission->wasRecentlyCreated) {
            $this->recordEvent($commission, null, 'created', [
                'status' => $commission->settlement_status->value,
            ]);
            if ($collectedWithRental) {
                $this->recordEvent($commission, null, 'downpayment_verified', [
                    'amount' => $downpayment,
                ]);
                $this->recordEvent($commission, null, 'commission_calculated', [
                    'amount' => (float) $booking->commission_amount,
                ]);
            }
        } else {
            $updates = [];
            if ($downpayment > 0) {
                $updates['verified_downpayment'] = $verifiedAmount;
                $updates['commission_amount'] = $booking->commission_amount;
                $updates['operator_net_amount'] = $booking->operator_net_amount;
            }
            if (! $collectedWithRental && $commission->settlement_due_at === null) {
                $updates['payment_verified_at'] = $verifiedAt;
                $updates['settlement_due_at'] = $dueAt;
            }
            if ($collectedWithRental && $commission->operator_payout_at === null) {
                $updates['operator_payout_status'] = $this->resolveOperatorPayoutStatus($booking, $commission);
            }
            if ($updates !== []) {
                $commission->update($updates);
            }
        }

        return $commission->fresh();
    }

    public function backfillMissingCommissions(?int $organizationId = null): void
    {
        $query = BoatBooking::query()
            ->whereDoesntHave('commission')
            ->where('commission_enabled', true)
            ->where('payment_status', BookingPaymentStatus::Verified);

        if ($organizationId !== null && $organizationId > 0) {
            $query->where('organization_id', $organizationId);
        }

        $query->with(['boat.operator'])->orderBy('id')->each(function (BoatBooking $booking): void {
            $this->generateIfEligible($booking);
        });
    }

    public function syncOperatorAfterBoatChange(BoatBooking $booking, ?User $actor = null): void
    {
        $booking->loadMissing(['boat.operator', 'commission']);
        $commission = $booking->commission;

        if (! $commission instanceof RentalCommission) {
            return;
        }

        $newOperatorId = $booking->boat?->operator?->id;
        if ($newOperatorId === null || (int) $commission->operator_id === (int) $newOperatorId) {
            return;
        }

        $previousOperatorId = $commission->operator_id;

        $commission->update(['operator_id' => $newOperatorId]);

        $this->recordEvent($commission->fresh(), $actor, 'operator_reassigned_after_reschedule', [
            'from_operator_id' => $previousOperatorId,
            'to_operator_id' => $newOperatorId,
            'boat_id' => $booking->boat_id,
        ]);
    }

    public function reconcileOperatorPayoutStatus(RentalCommission $commission): void
    {
        if (! $commission->usesOperatorSharePayout()) {
            return;
        }

        $commission->loadMissing('booking');

        if ($commission->operator_payout_at !== null) {
            if ($commission->operator_payout_status !== OperatorPayoutStatus::PaidOut) {
                $commission->update(['operator_payout_status' => OperatorPayoutStatus::PaidOut]);
            }

            return;
        }

        $correct = $this->effectiveOperatorPayoutStatus($commission);

        if ($commission->operator_payout_status !== $correct) {
            $commission->update(['operator_payout_status' => $correct]);
        }
    }

    public function effectiveOperatorPayoutStatus(RentalCommission $commission): OperatorPayoutStatus
    {
        if ($commission->operator_payout_at !== null) {
            return OperatorPayoutStatus::PaidOut;
        }

        if ($commission->commission_reversed_at !== null) {
            return OperatorPayoutStatus::Cancelled;
        }

        $booking = $commission->relationLoaded('booking') ? $commission->booking : $commission->booking()->first();

        return $this->resolveOperatorPayoutStatus($booking, $commission);
    }

    /** @deprecated Use reconcileOperatorPayoutStatus() */
    public function syncStoredOperatorPayoutStatus(RentalCommission $commission): void
    {
        $this->reconcileOperatorPayoutStatus($commission);
    }

    public function refreshBookingCommissionFromDownpayment(BoatBooking $booking): void
    {
        if (! $booking->commission_enabled) {
            return;
        }

        $collected = $this->barangayCollectedAmount($booking);
        if ($collected <= 0 || (float) ($booking->rental_price ?? 0) <= 0) {
            return;
        }

        $percent = (float) ($booking->commission_percentage ?? $this->settings($booking->organization_id)?->percentageAmount() ?? 0);
        if ($percent <= 0) {
            return;
        }

        $amount = min($this->fullRentalCommission($booking), $collected);
        $net = round($collected - $amount, 2);

        $booking->update([
            'commission_percentage' => number_format($percent, 2, '.', ''),
            'commission_amount' => number_format($amount, 2, '.', ''),
            'operator_net_amount' => number_format($net, 2, '.', ''),
        ]);
    }

    public function resolveOperatorPayoutStatus(?BoatBooking $booking, ?RentalCommission $commission = null): OperatorPayoutStatus
    {
        if (! $booking instanceof BoatBooking) {
            return OperatorPayoutStatus::OnHold;
        }

        $commission ??= $booking->relationLoaded('commission') ? $booking->commission : $booking->commission()->first();

        if ($commission instanceof RentalCommission && $commission->operator_payout_at !== null) {
            return OperatorPayoutStatus::PaidOut;
        }

        if ($commission instanceof RentalCommission && $commission->commission_reversed_at !== null) {
            return OperatorPayoutStatus::Cancelled;
        }

        if (in_array($booking->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
            BoatBookingStatus::PassengerNoShow,
        ], true)) {
            return OperatorPayoutStatus::Cancelled;
        }

        if ($this->blocksOperatorPayout($booking)) {
            if ($commission instanceof RentalCommission && $commission->operator_payout_at !== null) {
                return OperatorPayoutStatus::PaidOut;
            }

            return OperatorPayoutStatus::OnHold;
        }

        if ($booking->status === BoatBookingStatus::Completed
            && $booking->paymentStatus() === BookingPaymentStatus::Verified) {
            return OperatorPayoutStatus::ReadyForPayout;
        }

        return OperatorPayoutStatus::OnHold;
    }

    public function blocksOperatorPayout(BoatBooking $booking): bool
    {
        $report = $booking->operatorNoShowReports()
            ->where('status', OperatorNoShowReportStatus::Verified)
            ->latest('id')
            ->first();

        if (! $report instanceof OperatorNoShowReport) {
            return false;
        }

        if ($report->resolved_at !== null && $report->refund_status === OperatorNoShowRefundStatus::Refunded) {
            return false;
        }

        if ($report->passenger_choice === OperatorNoShowPassengerChoice::Refund) {
            return true;
        }

        return $report->resolved_at === null;
    }

    public function recordOperatorPayout(
        RentalCommission $commission,
        User $admin,
        CommissionSettlementMethod $method,
        ?string $reference,
        ?string $remarks,
    ): RentalCommission {
        abort_unless($admin->role === UserRole::Administrator, 403);

        if ((int) $commission->organization_id !== (int) $admin->organization_id) {
            abort(403);
        }

        if (! $commission->canRecordOperatorPayout()) {
            throw ValidationException::withMessages([
                'commission' => ['This operator share cannot be released right now.'],
            ]);
        }

        return DB::transaction(function () use ($commission, $admin, $method, $reference, $remarks): RentalCommission {
            $locked = RentalCommission::query()->lockForUpdate()->findOrFail($commission->id);

            if ($locked->operator_payout_at !== null
                || $locked->operator_payout_status === OperatorPayoutStatus::PaidOut) {
                throw ValidationException::withMessages([
                    'commission' => ['This operator share has already been paid out.'],
                ]);
            }

            $transactionId = filled($reference)
                ? trim((string) $reference)
                : $this->nextOperatorPayoutTransactionId($locked->organization_id);

            $locked->update([
                'operator_payout_status' => OperatorPayoutStatus::PaidOut,
                'operator_payout_method' => $method,
                'operator_payout_at' => now(),
                'operator_payout_by' => $admin->id,
                'operator_payout_reference' => $transactionId,
                'operator_payout_remarks' => filled($remarks) ? trim($remarks) : null,
            ]);

            $this->recordEvent($locked, $admin, 'operator_payout_recorded', [
                'method' => $method->value,
                'amount' => (float) $locked->operator_net_amount,
                'reference' => $transactionId,
            ]);

            $operatorUser = $locked->operator?->user;
            if ($operatorUser instanceof User) {
                try {
                    $operatorUser->notify(new CommissionSettlementNotification($locked->fresh(), 'operator_payout_recorded'));
                } catch (\Throwable) {
                }
            }

            return $locked->fresh(['booking.boat', 'operator', 'payoutRecorder']);
        });
    }

    public function markAutomaticGcashPayout(RentalCommission $commission, string $payoutId, float $amount): RentalCommission
    {
        return DB::transaction(function () use ($commission, $payoutId, $amount): RentalCommission {
            $locked = RentalCommission::query()->lockForUpdate()->findOrFail($commission->id);

            if ($locked->operator_payout_at !== null) {
                return $locked;
            }

            $reference = 'xendit:'.$payoutId;

            $locked->update([
                'operator_payout_status' => OperatorPayoutStatus::PaidOut,
                'operator_payout_method' => CommissionSettlementMethod::Gcash,
                'operator_payout_at' => now(),
                'operator_payout_by' => null,
                'operator_payout_reference' => $reference,
                'operator_payout_remarks' => 'Automatic Xendit GCash payout after trip completion.',
            ]);

            $this->recordEvent($locked, null, 'operator_payout_recorded', [
                'method' => CommissionSettlementMethod::Gcash->value,
                'amount' => $amount,
                'reference' => $reference,
                'automatic' => true,
            ]);

            $operatorUser = $locked->operator?->user;

            if ($operatorUser instanceof User) {
                try {
                    $operatorUser->notify(new CommissionSettlementNotification($locked->fresh(), 'operator_payout_recorded'));
                } catch (\Throwable) {
                }
            }

            return $locked->fresh();
        });
    }

    public function reverseCommissionForRefund(RentalCommission $commission, ?User $admin = null): RentalCommission
    {
        if ($commission->commission_reversed_at !== null) {
            return $commission;
        }

        if ($commission->operator_payout_at !== null) {
            $this->recordEvent($commission, $admin, 'payout_review_required', [
                'reason' => 'Refund completed after operator share was already released. Review manually.',
                'operator_share' => (float) $commission->operator_net_amount,
            ]);

            $commission->update([
                'commission_reversed_at' => now(),
                'settlement_status' => $commission->settlement_status === CommissionSettlementStatus::CollectedWithRental
                    ? CommissionSettlementStatus::Waived
                    : $commission->settlement_status,
            ]);

            $this->recordEvent($commission, $admin, 'commission_reversed', [
                'amount' => (float) $commission->commission_amount,
            ]);

            return $commission->fresh();
        }

        $commission->update([
            'commission_reversed_at' => now(),
            'operator_payout_status' => OperatorPayoutStatus::Cancelled,
            'settlement_status' => $commission->settlement_status === CommissionSettlementStatus::CollectedWithRental
                ? CommissionSettlementStatus::Waived
                : $commission->settlement_status,
        ]);

        $this->recordEvent($commission, $admin, 'commission_reversed', [
            'amount' => (float) $commission->commission_amount,
        ]);

        return $commission->fresh();
    }

    public function waiveUnpaidForNoShow(BoatBooking $booking): ?RentalCommission
    {
        $booking->loadMissing('commission');
        $commission = $booking->commission;

        if (! $commission instanceof RentalCommission) {
            return null;
        }

        if ($commission->settlement_status->isReceived()
            || $commission->settlement_status === CommissionSettlementStatus::Waived) {
            return $commission;
        }

        $commission->update([
            'settlement_status' => CommissionSettlementStatus::Waived,
            'settlement_due_at' => null,
            'operator_payout_status' => OperatorPayoutStatus::Cancelled,
            'rejection_reason' => 'Passenger did not show. Operator commission remittance is waived.',
        ]);

        $this->recordEvent($commission->fresh(), null, 'waived_passenger_no_show', [
            'status' => CommissionSettlementStatus::Waived->value,
        ]);

        return $commission->fresh();
    }

    public function submitSettlement(
        RentalCommission $commission,
        User $operatorUser,
        string $reference,
        UploadedFile $proof,
    ): RentalCommission {
        $commission->loadMissing('operator');

        if ((int) $commission->operator?->user_id !== (int) $operatorUser->id) {
            abort(403, 'This commission is not assigned to your boat.');
        }

        $commission->loadMissing('booking');

        if ($commission->booking?->paysBarangayGcash()) {
            throw ValidationException::withMessages([
                'receipt' => ['This commission was already collected with the passenger payment to barangay GCash.'],
            ]);
        }

        if (! $commission->canSubmitGcashSettlement()) {
            throw ValidationException::withMessages([
                'receipt' => ['This commission cannot be submitted right now.'],
            ]);
        }

        $organization = Organization::query()->find($commission->organization_id);

        if (! filled($organization?->gcash_qr_path) && ! filled($organization?->gcash_account_name)) {
            throw ValidationException::withMessages([
                'receipt' => ['The barangay has not set its official GCash account yet.'],
            ]);
        }

        if ($commission->settlement_proof_path) {
            Storage::disk('public')->delete($commission->settlement_proof_path);
        }

        $path = $proof->store('commission-proofs/'.$commission->organization_id, 'public');

        $commission->update([
            'gcash_reference' => $reference,
            'settlement_proof_path' => $path,
            'settlement_submitted_at' => now(),
            'settlement_method' => CommissionSettlementMethod::Gcash,
            'cash_intended_at' => null,
            'settlement_status' => CommissionSettlementStatus::AwaitingVerification,
            'rejection_reason' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->recordEvent($commission, $operatorUser, 'gcash_submitted', [
            'gcash_reference' => $reference,
        ]);

        return $commission->fresh(['booking.boat', 'operator']);
    }

    public function declareCashSettlement(RentalCommission $commission, User $operatorUser): RentalCommission
    {
        $commission->loadMissing('operator');

        if ((int) $commission->operator?->user_id !== (int) $operatorUser->id) {
            abort(403, 'This commission is not assigned to your boat.');
        }

        if (! $commission->canDeclareCashSettlement()) {
            throw ValidationException::withMessages([
                'method' => ['Cash settlement cannot be selected for this commission right now.'],
            ]);
        }

        $commission->update([
            'settlement_method' => CommissionSettlementMethod::Cash,
            'cash_intended_at' => now(),
        ]);

        $this->recordEvent($commission, $operatorUser, 'cash_intent');

        return $commission->fresh(['booking.boat', 'operator']);
    }

    public function recordCashPayment(
        RentalCommission $commission,
        User $admin,
        Carbon $receivedAt,
        ?string $remarks = null,
    ): RentalCommission {
        abort_unless($admin->role === UserRole::Administrator, 403);

        if ((int) $commission->organization_id !== (int) $admin->organization_id) {
            abort(403);
        }

        if (! $commission->canRecordCashPayment()) {
            throw ValidationException::withMessages([
                'commission' => ['This commission cannot be recorded as cash right now.'],
            ]);
        }

        if ($commission->settlement_status->isReceived()) {
            throw ValidationException::withMessages([
                'commission' => ['This commission has already been paid.'],
            ]);
        }

        $receiptNumber = $this->nextCashReceiptNumber($commission->organization_id);

        $commission->update([
            'settlement_method' => CommissionSettlementMethod::Cash,
            'settlement_status' => CommissionSettlementStatus::Paid,
            'cash_receipt_number' => $receiptNumber,
            'cash_received_at' => $receivedAt->toDateString(),
            'cash_remarks' => filled($remarks) ? trim($remarks) : null,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->recordEvent($commission, $admin, 'cash_recorded', [
            'receipt_number' => $receiptNumber,
            'received_at' => $receivedAt->toDateString(),
        ]);

        $operatorUser = $commission->operator?->user;

        if ($operatorUser instanceof User) {
            try {
                $operatorUser->notify(new CommissionSettlementNotification($commission->fresh(), 'cash_recorded'));
            } catch (\Throwable) {
                //
            }
        }

        return $commission->fresh(['booking.boat', 'operator', 'verifier']);
    }

    public function verify(RentalCommission $commission, User $admin): RentalCommission
    {
        if (! $commission->canVerify()) {
            throw ValidationException::withMessages([
                'commission' => ['Upload of a screenshot is not enough. Submit a receipt first, then verify it against the barangay GCash account.'],
            ]);
        }

        if ($commission->settlement_status->isReceived()) {
            throw ValidationException::withMessages([
                'commission' => ['This commission has already been paid.'],
            ]);
        }

        $commission->update([
            'settlement_status' => CommissionSettlementStatus::Paid,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        $this->recordEvent($commission, $admin, 'gcash_verified');

        $operatorUser = $commission->operator?->user;

        if ($operatorUser instanceof User) {
            try {
                $operatorUser->notify(new CommissionSettlementNotification($commission->fresh(), 'gcash_verified'));
            } catch (\Throwable) {
                //
            }
        }

        return $commission->fresh(['booking.boat', 'operator', 'verifier']);
    }

    public function reject(RentalCommission $commission, User $admin, string $reason): RentalCommission
    {
        if ($commission->settlement_status !== CommissionSettlementStatus::AwaitingVerification) {
            throw ValidationException::withMessages([
                'reason' => ['Only submitted settlements can be rejected.'],
            ]);
        }

        $commission->update([
            'settlement_status' => CommissionSettlementStatus::PendingSettlement,
            'settlement_proof_path' => null,
            'settlement_submitted_at' => null,
            'gcash_reference' => null,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ]);

        if ($commission->settlement_due_at !== null && now()->gt($commission->settlement_due_at)) {
            $commission->update([
                'settlement_status' => CommissionSettlementStatus::Overdue,
                'marked_overdue_at' => $commission->marked_overdue_at ?? now(),
            ]);
        }

        $this->recordEvent($commission, $admin, 'gcash_rejected', ['reason' => $reason]);

        $operatorUser = $commission->operator?->user;

        if ($operatorUser instanceof User) {
            try {
                $operatorUser->notify(new CommissionSettlementNotification($commission->fresh(), 'gcash_rejected'));
            } catch (\Throwable) {
                //
            }
        }

        return $commission->fresh(['booking.boat', 'operator', 'verifier']);
    }

    public function sendAdminFollowUp(RentalCommission $commission, User $admin): void
    {
        abort_unless($admin->role === UserRole::Administrator, 403);

        if ((int) $commission->organization_id !== (int) $admin->organization_id) {
            abort(403);
        }

        if (! in_array($commission->settlement_status, [
            CommissionSettlementStatus::Overdue,
            CommissionSettlementStatus::PendingSettlement,
        ], true)) {
            throw ValidationException::withMessages([
                'commission' => ['Follow-up notices are only for unsettled commissions.'],
            ]);
        }

        $operatorUser = $commission->operator?->user;

        if (! $operatorUser instanceof User) {
            throw ValidationException::withMessages([
                'commission' => ['This commission has no linked operator account.'],
            ]);
        }

        try {
            $operatorUser->notify(new CommissionSettlementNotification($commission->fresh(), 'admin_follow_up'));
        } catch (\Throwable) {
            //
        }

        $this->recordEvent($commission, $admin, 'admin_follow_up');
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function recordEvent(RentalCommission $commission, ?User $actor, string $event, ?array $meta = null): void
    {
        RentalCommissionEvent::query()->create([
            'organization_id' => $commission->organization_id,
            'rental_commission_id' => $commission->id,
            'user_id' => $actor?->id,
            'event' => $event,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    public function settlementDueAt(?int $organizationId, Carbon $verifiedAt): Carbon
    {
        $settings = $this->settings($organizationId);
        $days = $settings?->settlementDueDays() ?? 3;

        return $verifiedAt->copy()->addDays($days)->endOfDay();
    }

    public function settings(?int $organizationId): ?OrganizationCommissionSetting
    {
        if (! $organizationId) {
            return null;
        }

        return OrganizationCommissionSetting::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->first();
    }

    public function commissionAmount(float $rentalPrice, float $percent): float
    {
        $price = number_format(max(0, $rentalPrice), 2, '.', '');
        $rate = number_format(max(0, min(100, $percent)), 2, '.', '');

        if (function_exists('bcmul') && function_exists('bcdiv')) {
            return (float) bcmul($price, bcdiv($rate, '100', 8), 2);
        }

        return round(((float) $price) * ((float) $rate) / 100, 2);
    }

    private function shouldGenerate(BoatBooking $booking): bool
    {
        if (! $booking->isExclusiveRental()) {
            return false;
        }

        if (in_array($booking->status, [
            BoatBookingStatus::Cancelled,
            BoatBookingStatus::Rejected,
            BoatBookingStatus::PassengerNoShow,
        ], true)) {
            return false;
        }

        if ($booking->paymentStatus() !== BookingPaymentStatus::Verified) {
            return false;
        }

        if (! $booking->commission_enabled) {
            return false;
        }

        if ((float) $booking->commission_amount <= 0) {
            return false;
        }

        return true;
    }

    private function nextCashReceiptNumber(int $organizationId): string
    {
        $year = now()->format('Y');
        $prefix = "CS-{$organizationId}-{$year}-";

        $latest = RentalCommission::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('cash_receipt_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('cash_receipt_number');

        $sequence = 1;

        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function nextOperatorPayoutTransactionId(int $organizationId): string
    {
        $year = now()->format('Y');
        $prefix = "OP-{$organizationId}-{$year}-";

        $latest = RentalCommission::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('operator_payout_reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('operator_payout_reference');

        $sequence = 1;

        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}

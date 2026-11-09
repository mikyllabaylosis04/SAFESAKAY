<?php

namespace App\Models;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Enums\CommissionSettlementMethod;
use App\Enums\CommissionSettlementStatus;
use App\Enums\OperatorPayoutStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $boat_booking_id
 * @property int|null $operator_id
 * @property string $rental_amount
 * @property string $commission_percentage
 * @property string $commission_amount
 * @property string $operator_net_amount
 * @property Carbon|null $payment_verified_at
 * @property Carbon|null $settlement_due_at
 * @property CommissionSettlementStatus $settlement_status
 * @property CommissionSettlementMethod|null $settlement_method
 * @property string|null $gcash_reference
 * @property string|null $settlement_proof_path
 * @property Carbon|null $settlement_submitted_at
 * @property Carbon|null $cash_intended_at
 * @property string|null $cash_receipt_number
 * @property Carbon|null $cash_received_at
 * @property string|null $cash_remarks
 * @property Carbon|null $settlement_reminder_day_1_sent_at
 * @property Carbon|null $settlement_reminder_day_2_sent_at
 * @property Carbon|null $marked_overdue_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $verified_downpayment
 * @property OperatorPayoutStatus $operator_payout_status
 * @property CommissionSettlementMethod|null $operator_payout_method
 * @property Carbon|null $operator_payout_at
 * @property int|null $operator_payout_by
 * @property string|null $operator_payout_reference
 * @property string|null $operator_payout_remarks
 * @property Carbon|null $commission_reversed_at
 * @property string|null $rejection_reason
 */
class RentalCommission extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'boat_booking_id',
        'operator_id',
        'rental_amount',
        'commission_percentage',
        'commission_amount',
        'operator_net_amount',
        'verified_downpayment',
        'operator_payout_status',
        'operator_payout_method',
        'operator_payout_at',
        'operator_payout_by',
        'operator_payout_reference',
        'operator_payout_remarks',
        'commission_reversed_at',
        'payment_verified_at',
        'settlement_due_at',
        'settlement_status',
        'settlement_method',
        'gcash_reference',
        'settlement_proof_path',
        'settlement_submitted_at',
        'cash_intended_at',
        'cash_receipt_number',
        'cash_received_at',
        'cash_remarks',
        'settlement_reminder_day_1_sent_at',
        'settlement_reminder_day_2_sent_at',
        'marked_overdue_at',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operator_payout_status' => OperatorPayoutStatus::class,
            'operator_payout_method' => CommissionSettlementMethod::class,
            'operator_payout_at' => 'datetime',
            'commission_reversed_at' => 'datetime',
            'payment_verified_at' => 'datetime',
            'settlement_due_at' => 'datetime',
            'settlement_status' => CommissionSettlementStatus::class,
            'settlement_method' => CommissionSettlementMethod::class,
            'settlement_submitted_at' => 'datetime',
            'cash_intended_at' => 'datetime',
            'cash_received_at' => 'date',
            'settlement_reminder_day_1_sent_at' => 'datetime',
            'settlement_reminder_day_2_sent_at' => 'datetime',
            'marked_overdue_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BoatBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(BoatBooking::class, 'boat_booking_id');
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return HasMany<RentalCommissionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RentalCommissionEvent::class);
    }

    public function requiresOperatorRemittance(): bool
    {
        if (in_array($this->settlement_status, [
            CommissionSettlementStatus::CollectedWithRental,
            CommissionSettlementStatus::Waived,
        ], true)) {
            return false;
        }

        $this->loadMissing('booking');

        return $this->booking?->paysOperatorGcash() ?? false;
    }

    public function canSubmitGcashSettlement(): bool
    {
        if (! $this->requiresOperatorRemittance()) {
            return false;
        }

        if ($this->settlement_status->isReceived()) {
            return false;
        }

        if ($this->settlement_status === CommissionSettlementStatus::AwaitingVerification) {
            return false;
        }

        return in_array($this->settlement_status, [
            CommissionSettlementStatus::PendingSettlement,
            CommissionSettlementStatus::Overdue,
            CommissionSettlementStatus::Rejected,
        ], true);
    }

    public function canDeclareCashSettlement(): bool
    {
        return $this->canSubmitGcashSettlement();
    }

    public function canRecordCashPayment(): bool
    {
        if (! $this->requiresOperatorRemittance()) {
            return false;
        }

        if ($this->settlement_status->isReceived()) {
            return false;
        }

        if ($this->settlement_status === CommissionSettlementStatus::AwaitingVerification) {
            return false;
        }

        return in_array($this->settlement_status, [
            CommissionSettlementStatus::PendingSettlement,
            CommissionSettlementStatus::Overdue,
            CommissionSettlementStatus::Rejected,
        ], true) || (
            $this->settlement_method === CommissionSettlementMethod::Cash
            && filled($this->cash_intended_at)
        );
    }

    public function payoutRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_payout_by');
    }

    public function usesOperatorSharePayout(): bool
    {
        $this->loadMissing('booking');

        return ! $this->requiresOperatorRemittance()
            && $this->booking?->paysBarangayGcash() === true;
    }

    public function canRecordOperatorPayout(): bool
    {
        if (! $this->usesOperatorSharePayout()) {
            return false;
        }

        if ($this->operator_payout_status === OperatorPayoutStatus::PaidOut) {
            return false;
        }

        if ($this->operator_payout_status === OperatorPayoutStatus::Cancelled) {
            return false;
        }

        if ($this->commission_reversed_at !== null) {
            return false;
        }

        $this->loadMissing('booking');

        if (! $this->booking instanceof BoatBooking
            || $this->booking->status !== BoatBookingStatus::Completed
            || $this->booking->paymentStatus() !== BookingPaymentStatus::Verified) {
            return false;
        }

        if ($this->operator_payout_at !== null) {
            return false;
        }

        if (is_string($this->operator_payout_reference) && str_starts_with($this->operator_payout_reference, 'xendit:')) {
            return false;
        }

        if ($this->booking && app(\App\Services\RentalCommissionService::class)->blocksOperatorPayout($this->booking)) {
            return false;
        }

        $status = app(\App\Services\RentalCommissionService::class)->effectiveOperatorPayoutStatus($this);

        return $status === OperatorPayoutStatus::ReadyForPayout;
    }

    /** @deprecated Use canSubmitGcashSettlement() */
    public function canSubmitSettlement(): bool
    {
        return $this->canSubmitGcashSettlement();
    }

    public function canVerify(): bool
    {
        return $this->settlement_status === CommissionSettlementStatus::AwaitingVerification
            && filled($this->settlement_proof_path)
            && $this->settlement_method !== CommissionSettlementMethod::Cash;
    }

    public function hasOnTimeGcashSubmissionPendingVerification(): bool
    {
        if ($this->settlement_status !== CommissionSettlementStatus::AwaitingVerification) {
            return false;
        }

        if ($this->settlement_submitted_at === null || $this->settlement_due_at === null) {
            return true;
        }

        return $this->settlement_submitted_at->lte($this->settlement_due_at);
    }

    public function daysPending(): ?int
    {
        if (! $this->requiresOperatorRemittance()) {
            return null;
        }

        if ($this->payment_verified_at === null) {
            return null;
        }

        if ($this->settlement_status->isReceived()
            || $this->settlement_status === CommissionSettlementStatus::Waived) {
            return null;
        }

        return max(0, $this->payment_verified_at->startOfDay()->diffInDays(now()->startOfDay()));
    }

    public function daysRemaining(): ?int
    {
        if (! $this->requiresOperatorRemittance() || $this->settlement_due_at === null) {
            return null;
        }

        if ($this->settlement_status->isReceived()
            || $this->settlement_status === CommissionSettlementStatus::Waived) {
            return null;
        }

        if ($this->hasOnTimeGcashSubmissionPendingVerification()) {
            return null;
        }

        $due = $this->settlement_due_at->startOfDay();
        $today = now()->startOfDay();

        return (int) $today->diffInDays($due, false);
    }
}

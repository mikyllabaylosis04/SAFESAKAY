<?php

namespace App\Support;

use App\Enums\CommissionSettlementStatus;
use App\Enums\OperatorPayoutStatus;
use App\Models\OrganizationRoute;
use App\Models\RentalCommission;
use App\Models\RentalCommissionEvent;
use App\Models\User;

class RentalCommissionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(RentalCommission $commission): array
    {
        $commission->loadMissing([
            'booking.boat',
            'operator',
            'verifier',
            'payoutRecorder',
            'events.actor',
        ]);

        $booking = $commission->booking;
        $payoutStatus = self::resolvePayoutStatus($commission);
        $isRefunded = $commission->commission_reversed_at !== null;
        $displayPayoutStatus = $isRefunded ? 'refunded' : $payoutStatus->value;
        $displayPayoutLabel = match (true) {
            $isRefunded => 'Refunded',
            $payoutStatus === OperatorPayoutStatus::PaidOut => 'Paid Out',
            default => $payoutStatus->label(),
        };
        $displayPayoutBadge = match (true) {
            $isRefunded => 'bg-violet-600 text-white dark:bg-violet-600',
            $payoutStatus === OperatorPayoutStatus::PaidOut => 'bg-emerald-600 text-white dark:bg-emerald-600',
            $payoutStatus === OperatorPayoutStatus::ReadyForPayout => 'bg-amber-400 text-amber-950 dark:bg-amber-500 dark:text-amber-950',
            default => $payoutStatus->badgeClass(),
        };
        $downRaw = (float) ($commission->verified_downpayment ?? $booking?->down_payment_amount ?? 0);
        $operatorNetRaw = (float) $commission->operator_net_amount;
        $commissionRaw = (float) $commission->commission_amount;
        $remainingBalance = max(0, (float) ($booking?->rental_price ?? 0) - $downRaw);
        $payoutReviewRequired = $commission->events->contains(fn (RentalCommissionEvent $event) => $event->event === 'payout_review_required');
        $payoutInconsistency = $commission->usesOperatorSharePayout()
            && $commission->operator_payout_status === OperatorPayoutStatus::PaidOut
            && $commission->operator_payout_at === null;

        return [
            'id' => $commission->id,
            'code' => $booking?->code,
            'boat_id' => $booking?->boat_id,
            'boat_name' => $booking?->boat?->name,
            'boat_picture_url' => $booking?->boat?->pictureUrl(),
            'operator_id' => $commission->operator_id,
            'operator_name' => $commission->operator?->full_name,
            'operator_contact' => $commission->operator?->contact_number,
            'operator_exclusive_restricted' => $commission->operator?->isExclusiveRentalRestricted() ?? false,
            'rental_date' => $booking?->booking_date?->toDateString(),
            'rental_date_label' => $booking?->booking_date?->format('M d, Y'),
            'trip_status' => $booking?->status?->value,
            'trip_status_label' => $booking?->status?->label() ?? '—',
            'rental_amount' => OrganizationRoute::formatPeso($commission->rental_amount),
            'rental_amount_raw' => (float) $commission->rental_amount,
            'verified_downpayment' => OrganizationRoute::formatPeso($downRaw),
            'verified_downpayment_raw' => $downRaw,
            'remaining_balance' => OrganizationRoute::formatPeso($remainingBalance),
            'remaining_balance_raw' => $remainingBalance,
            'commission_percentage' => OrganizationRoute::formatPercent((float) $commission->commission_percentage),
            'commission_amount' => OrganizationRoute::formatPeso($commission->commission_amount),
            'commission_amount_raw' => $commissionRaw,
            'operator_net' => OrganizationRoute::formatPeso($commission->operator_net_amount),
            'operator_net_raw' => $operatorNetRaw,
            'barangay_collected' => OrganizationRoute::formatPeso($operatorNetRaw + $commissionRaw),
            'barangay_collected_raw' => round($operatorNetRaw + $commissionRaw, 2),
            'operator_share_formula' => OrganizationRoute::formatPeso($operatorNetRaw + $commissionRaw)
                .' − '.OrganizationRoute::formatPeso($commissionRaw)
                .' = '.OrganizationRoute::formatPeso($operatorNetRaw),
            'operator_share_calc_line' => OrganizationRoute::formatPeso($operatorNetRaw + $commissionRaw)
                .' collected by barangay − '.OrganizationRoute::formatPeso($commissionRaw).' commission',
            'operator_gcash' => $commission->operator?->approvedGcashAccount()?->only(['account_name', 'mobile_number']),
            'trip_status_badge_class' => self::tripStatusBadgeClass($booking?->status?->value),
            'payout_hold_message' => self::payoutHoldMessage($booking, $payoutStatus),
            'payout_hold_reason' => $isRefunded ? null : self::payoutHoldReason($booking, $payoutStatus),
            'payout_ready_message' => $payoutStatus === OperatorPayoutStatus::ReadyForPayout && ! $isRefunded
                ? 'Down payment was collected at the barangay desk. Confirm release of the operator share when the trip is completed.'
                : null,
            'payout_released_message' => $payoutStatus === OperatorPayoutStatus::PaidOut && ! $isRefunded
                ? 'Payout Successful — operator share has been released and recorded.'
                : null,
            'payout_inconsistency' => $payoutInconsistency,
            'payout_review_required' => $payoutReviewRequired,
            'status' => $commission->settlement_status->value,
            'status_label' => $commission->settlement_status->label(),
            'payout_status' => $displayPayoutStatus,
            'payout_status_label' => $displayPayoutLabel,
            'payout_status_badge_class' => $displayPayoutBadge,
            'rental_type_label' => self::rentalTypeLabel($booking),
            'payment_verification_status_label' => $booking?->paymentStatus()?->label() ?? '—',
            'downpayment_payment_method_label' => self::downPaymentMethodLabel($booking),
            'transaction_id' => $commission->operator_payout_reference,
            'refund_amount_label' => $isRefunded ? OrganizationRoute::formatPeso($downRaw) : null,
            'refund_reason' => $booking?->cancellation_reason,
            'cancelled_at_label' => $booking?->cancelled_at?->format('M d, Y · g:i A'),
            'commission_reversed_amount_label' => $isRefunded
                ? OrganizationRoute::formatPeso($commissionRaw)
                : null,
            'final_commission_amount_label' => OrganizationRoute::formatPeso($isRefunded ? 0 : $commissionRaw),
            'original_booking_status_label' => $booking?->status?->label(),
            'payment_method' => $commission->operator_payout_method?->value ?? $commission->settlement_method?->value,
            'payment_method_label' => $commission->operator_payout_method?->label() ?? $commission->settlement_method?->label(),
            'payout_at_label' => $commission->operator_payout_at?->format('M d, Y · g:i A'),
            'payout_recorded_by' => self::formatPayoutRecorder($commission->payoutRecorder),
            'payout_remarks' => $commission->operator_payout_remarks,
            'payout_reference' => $commission->operator_payout_reference,
            'commission_reversed' => $commission->commission_reversed_at !== null,
            'commission_reversed_at_label' => $commission->commission_reversed_at?->format('M d, Y · g:i A'),
            'refund_processed_by' => self::refundProcessedBy($commission),
            'payment_verified_at_label' => $commission->payment_verified_at?->format('M d, Y · g:i A'),
            'settlement_due_label' => $commission->settlement_due_at?->format('M d, Y'),
            'days_remaining_label' => self::daysRemainingLabel($commission->daysRemaining(), $commission),
            'days_pending' => $commission->daysPending(),
            'cash_intended' => $commission->cash_intended_at !== null,
            'cash_receipt_number' => $commission->cash_receipt_number,
            'cash_received_at_label' => $commission->cash_received_at?->format('M d, Y'),
            'cash_remarks' => $commission->cash_remarks,
            'gcash_reference' => $commission->gcash_reference,
            'proof_url' => filled($commission->settlement_proof_path)
                ? '/commissions/'.$commission->id.'/proof'
                : null,
            'submitted_at_label' => $commission->settlement_submitted_at?->format('M d, Y · g:i A'),
            'verified_by' => $commission->verifier?->name,
            'verified_at_label' => $commission->verified_at?->format('M d, Y · g:i A'),
            'rejection_reason' => $commission->rejection_reason,
            'can_verify' => $commission->canVerify(),
            'can_record_cash' => $commission->canRecordCashPayment(),
            'can_record_operator_payout' => $commission->canRecordOperatorPayout(),
            'can_send_follow_up' => $commission->requiresOperatorRemittance()
                && in_array($commission->settlement_status->value, ['pending_settlement', 'overdue'], true),
            'requires_operator_remittance' => $commission->requiresOperatorRemittance(),
            'uses_operator_share_payout' => $commission->usesOperatorSharePayout(),
            'booking_href' => $booking ? '/bookings/requests/'.$booking->id : null,
            'history' => self::historyTimeline($commission),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOperator(RentalCommission $commission, ?string $proofUrlPrefix = '/api/commissions'): array
    {
        $booking = $commission->booking;
        $daysRemaining = $commission->daysRemaining();
        $payoutStatus = self::resolvePayoutStatus($commission);
        $tripStatus = $booking?->status?->value;

        return [
            'id' => $commission->id,
            'code' => $booking?->code,
            'boat_id' => $booking?->boat_id,
            'boat_name' => $booking?->boat?->name,
            'rental_date' => $booking?->booking_date?->toDateString(),
            'rental_date_label' => $booking?->booking_date?->format('M d, Y'),
            'rental_amount' => (float) $commission->rental_amount,
            'rental_amount_label' => OrganizationRoute::formatPeso($commission->rental_amount),
            'commission_percentage' => (float) $commission->commission_percentage,
            'commission_percentage_label' => OrganizationRoute::formatPercent((float) $commission->commission_percentage),
            'commission_amount' => (float) $commission->commission_amount,
            'commission_amount_label' => OrganizationRoute::formatPeso($commission->commission_amount),
            'operator_net_amount' => (float) $commission->operator_net_amount,
            'operator_net_amount_label' => OrganizationRoute::formatPeso($commission->operator_net_amount),
            'status' => $commission->settlement_status->value,
            'status_label' => $commission->settlement_status->isReceived()
                || $commission->operator_payout_status === OperatorPayoutStatus::PaidOut
                ? 'Paid Out'
                : $commission->settlement_status->label(),
            'paid_out' => $commission->settlement_status->isReceived()
                || $commission->operator_payout_status === OperatorPayoutStatus::PaidOut,
            'paid_at_label' => $commission->verified_at?->copy()->timezone('Asia/Manila')->format('M d, Y · g:i A')
                ?? $commission->operator_payout_at?->copy()->timezone('Asia/Manila')->format('M d, Y · g:i A')
                ?? $commission->payment_verified_at?->copy()->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'payment_method' => $commission->settlement_method?->value,
            'payment_method_label' => $commission->settlement_method?->label(),
            'settlement_due_at' => $commission->settlement_due_at?->toIso8601String(),
            'settlement_due_label' => $commission->settlement_due_at?->format('M d, Y'),
            'days_remaining' => $daysRemaining,
            'days_remaining_label' => self::daysRemainingLabel($daysRemaining, $commission),
            'days_pending' => $commission->daysPending(),
            'cash_intended' => $commission->cash_intended_at !== null,
            'gcash_reference' => $commission->gcash_reference,
            'gcash_reference_number' => $commission->gcash_reference,
            'rejection_reason' => $commission->rejection_reason,
            'can_submit_gcash' => $commission->canSubmitGcashSettlement(),
            'can_submit' => $commission->canSubmitGcashSettlement(),
            'can_declare_cash' => $commission->canDeclareCashSettlement(),
            'proof_url' => filled($commission->settlement_proof_path)
                ? $proofUrlPrefix.'/'.$commission->id.'/proof'
                : null,
            'trip_status' => $tripStatus,
            'trip_status_label' => $booking?->status?->label() ?? null,
            'payment_status' => $booking?->paymentStatus()?->value,
            'payment_status_label' => $booking?->paymentStatus()?->label(),
            'operator_payout_status' => $payoutStatus->value,
            'operator_payout_status_label' => $payoutStatus->label(),
            'operator_payout_pending' => $payoutStatus === OperatorPayoutStatus::ReadyForPayout,
            'operator_payout_released' => $payoutStatus === OperatorPayoutStatus::PaidOut,
            'operator_payout_reference' => $commission->operator_payout_reference,
            'operator_payout_at_label' => $commission->operator_payout_at
                ?->timezone('Asia/Manila')
                ->format('M d, Y · g:i A'),
            'payout_hold_message' => self::payoutHoldMessage($booking, $payoutStatus),
        ];
    }

    private static function resolvePayoutStatus(RentalCommission $commission): OperatorPayoutStatus
    {
        if ($commission->usesOperatorSharePayout()) {
            return app(\App\Services\RentalCommissionService::class)->effectiveOperatorPayoutStatus($commission);
        }

        return self::legacyPayoutStatus($commission);
    }

    private static function payoutHoldReason(?\App\Models\BoatBooking $booking, OperatorPayoutStatus $status): ?string
    {
        if ($status !== OperatorPayoutStatus::OnHold || ! $booking) {
            return null;
        }

        if (app(\App\Services\RentalCommissionService::class)->blocksOperatorPayout($booking)) {
            return 'No-show report under review';
        }

        if ($booking->weather_review_required) {
            return 'Weather review in progress';
        }

        $tripDate = $booking->booking_date?->format('M d, Y');

        return match ($booking->status) {
            \App\Enums\BoatBookingStatus::WeatherHold => 'Weather hold · needs a new date',
            \App\Enums\BoatBookingStatus::ReschedulePending => 'Waiting for reschedule approval',
            \App\Enums\BoatBookingStatus::Pending => 'Waiting for rental approval',
            \App\Enums\BoatBookingStatus::AwaitingPayment => 'Down payment not verified',
            \App\Enums\BoatBookingStatus::Confirmed, \App\Enums\BoatBookingStatus::Ongoing => $booking->booking_date?->isAfter(now('Asia/Manila')->endOfDay())
                ? 'Trip scheduled '.$tripDate
                : 'Trip not marked completed',
            default => 'Trip not completed',
        };
    }

    private static function payoutHoldMessage(?\App\Models\BoatBooking $booking, OperatorPayoutStatus $status): ?string
    {
        if ($status === OperatorPayoutStatus::PaidOut || $status === OperatorPayoutStatus::Cancelled) {
            return null;
        }

        if ($booking?->status?->value === 'weather_hold') {
            return 'Operator payout is on hold until the trip is successfully completed.';
        }

        if ($status === OperatorPayoutStatus::OnHold) {
            return 'Operator payout stays on hold until the trip is completed and any open incidents are resolved.';
        }

        return null;
    }

    private static function tripStatusBadgeClass(?string $status): string
    {
        return match ($status) {
            'completed' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
            'weather_hold', 'reschedule_pending' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100',
            'cancelled', 'rejected', 'passenger_no_show' => 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200',
            default => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
        };
    }

    private static function refundProcessedBy(RentalCommission $commission): ?string
    {
        $event = $commission->events->first(fn (RentalCommissionEvent $event) => $event->event === 'commission_reversed');

        return $event?->actor instanceof User ? $event->actor->name : null;
    }

    private static function legacyPayoutStatus(RentalCommission $commission): OperatorPayoutStatus
    {
        if ($commission->commission_reversed_at !== null
            || $commission->settlement_status === CommissionSettlementStatus::Waived) {
            return OperatorPayoutStatus::Cancelled;
        }

        if ($commission->settlement_status->isReceived()) {
            return OperatorPayoutStatus::PaidOut;
        }

        if (in_array($commission->settlement_status, [
            CommissionSettlementStatus::PendingSettlement,
            CommissionSettlementStatus::AwaitingVerification,
            CommissionSettlementStatus::Overdue,
        ], true)) {
            return OperatorPayoutStatus::OnHold;
        }

        return OperatorPayoutStatus::OnHold;
    }

    /**
     * @return list<array{title: string, detail: string, at_label: string|null, actor: string|null, footnote: string|null}>
     */
    private static function historyTimeline(RentalCommission $commission): array
    {
        $entries = [];

        if ($commission->payment_verified_at) {
            $verified = (float) ($commission->verified_downpayment ?? 0);
            $paidInFull = $verified > 0 && $verified + 0.005 >= (float) $commission->rental_amount;

            $entries[] = [
                'title' => $paidInFull ? 'Full payment verified' : 'Downpayment verified',
                'detail' => OrganizationRoute::formatPeso($commission->verified_downpayment ?? $commission->booking?->down_payment_amount ?? 0),
                'at_label' => $commission->payment_verified_at->format('M d, Y · g:i A'),
                'actor' => null,
                'footnote' => null,
            ];
        }

        $commissionAt = $commission->payment_verified_at ?? $commission->created_at;

        $entries[] = [
            'title' => 'Commission calculated',
            'detail' => OrganizationRoute::formatPeso($commission->commission_amount),
            'at_label' => $commissionAt?->format('M d, Y · g:i A'),
            'actor' => null,
            'footnote' => null,
        ];

        $booking = $commission->booking;

        if ($booking?->balance_paid_at && $booking->hasOutstandingBalance()) {
            $entries[] = [
                'title' => $booking->balance_method === 'gcash' ? 'Balance paid to operator (GCash)' : 'Balance paid to operator (cash)',
                'detail' => OrganizationRoute::formatPeso($booking->remaining_balance),
                'at_label' => $booking->balance_paid_at->format('M d, Y · g:i A'),
                'actor' => null,
                'footnote' => null,
            ];
        }

        if ($booking?->completed_at) {
            $entries[] = [
                'title' => 'Trip completed',
                'detail' => 'Operator share is ready for payout.',
                'at_label' => $booking->completed_at->format('M d, Y · g:i A'),
                'actor' => null,
                'footnote' => null,
            ];
        }

        foreach ($commission->events->sortBy('created_at') as $event) {
            if ($event->event === 'operator_payout_recorded') {
                continue;
            }
            $mapped = self::mapHistoryEvent($event);
            if ($mapped !== null) {
                $entries[] = $mapped;
            }
        }

        if ($commission->commission_reversed_at) {
            $entries[] = [
                'title' => 'Refund adjustment',
                'detail' => 'Commission reversed · '.OrganizationRoute::formatPeso($commission->commission_amount),
                'at_label' => $commission->commission_reversed_at->format('M d, Y · g:i A'),
                'actor' => null,
                'footnote' => null,
            ];
        }

        if ($commission->operator_payout_at) {
            $detail = OrganizationRoute::formatPeso($commission->operator_net_amount);
            if ($commission->operator_payout_method) {
                $detail .= ' · '.$commission->operator_payout_method->label();
            }

            $footnote = $commission->operator_payout_remarks;
            if ($commission->operator_payout_reference) {
                $refLine = 'Ref: '.$commission->operator_payout_reference;
                $footnote = $footnote ? $footnote."\n".$refLine : $refLine;
            }

            $entries[] = [
                'title' => 'Operator payout recorded',
                'detail' => $detail,
                'at_label' => $commission->operator_payout_at->format('M d, Y · g:i A'),
                'actor' => null,
                'footnote' => $footnote ?: null,
            ];
        }

        return $entries;
    }

    private static function formatPayoutRecorder(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $suffix = $user->isAdministrator() || $user->isSuperAdmin() ? ' (Admin)' : '';

        return $user->name.$suffix;
    }

    private static function rentalTypeLabel(?\App\Models\BoatBooking $booking): string
    {
        if ($booking === null) {
            return 'Private Rental';
        }

        if ($booking->user_id === null) {
            return 'Walk-in Rental';
        }

        return 'Private Rental (App)';
    }

    private static function downPaymentMethodLabel(?\App\Models\BoatBooking $booking): string
    {
        if ($booking === null) {
            return '—';
        }

        $remarks = (string) ($booking->remarks ?? '');

        if (str_contains($remarks, '@walk-in:cash')
            || str_contains($remarks, 'Walk-in down payment received in cash')) {
            return 'Cash (Barangay Desk)';
        }

        if (str_contains($remarks, '@walk-in:gcash')
            || str_contains($remarks, 'Walk-in GCash payment submitted')) {
            return 'GCash (Walk-in)';
        }

        if ($booking->paysBarangayGcash()) {
            return 'GCash (Barangay)';
        }

        return 'GCash or Cash';
    }

    /**
     * @return array{title: string, detail: string, at_label: string|null, actor: string|null, footnote: string|null}|null
     */
    private static function mapHistoryEvent(RentalCommissionEvent $event): ?array
    {
        $actor = $event->actor instanceof User ? $event->actor->name : null;
        $at = $event->created_at?->format('M d, Y · g:i A');

        return match ($event->event) {
            'payout_review_required' => [
                'title' => 'Admin review required',
                'detail' => (string) ($event->meta['reason'] ?? 'Operator share was released before refund was completed.'),
                'at_label' => $at,
                'actor' => $actor,
                'footnote' => null,
            ],
            'operator_payout_recorded' => null,
            'commission_reversed' => [
                'title' => 'Refund adjustment',
                'detail' => isset($event->meta['amount'])
                    ? OrganizationRoute::formatPeso($event->meta['amount'])
                    : 'Commission reversed',
                'at_label' => $at,
                'actor' => $actor,
                'footnote' => null,
            ],
            'gcash_verified', 'cash_recorded' => [
                'title' => 'Commission settlement recorded',
                'detail' => $event->event === 'gcash_verified' ? 'GCash remittance verified' : 'Cash remittance recorded',
                'at_label' => $at,
                'actor' => $actor,
                'footnote' => null,
            ],
            default => null,
        };
    }

    private static function daysRemainingLabel(?int $daysRemaining, RentalCommission $commission): ?string
    {
        if (! $commission->requiresOperatorRemittance()) {
            return null;
        }

        if ($commission->settlement_status->isReceived()
            || $commission->settlement_status->value === 'waived') {
            return null;
        }

        if ($commission->hasOnTimeGcashSubmissionPendingVerification()) {
            return 'Submitted — awaiting verification';
        }

        if ($daysRemaining === null) {
            return null;
        }

        if ($daysRemaining > 0) {
            return $daysRemaining === 1 ? '1 day left' : "{$daysRemaining} days left";
        }

        if ($daysRemaining === 0) {
            return 'Due today';
        }

        $overdue = abs($daysRemaining);

        return $overdue === 1 ? '1 day overdue' : "{$overdue} days overdue";
    }
}

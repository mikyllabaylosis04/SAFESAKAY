<?php

namespace App\Services;

use App\Enums\OperatorPayoutStatus;
use App\Models\OperatorGcashAccount;
use App\Models\RentalCommission;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OperatorGcashPayoutService
{
    public function __construct(
        private readonly RentalCommissionService $commissions,
        private readonly XenditService $xendit,
    ) {}

    public function releaseIfReady(RentalCommission $commission): void
    {
        if (! config('services.xendit.enabled', false) || ! config('services.xendit.operator_payouts', false)) {
            return;
        }

        $commission->loadMissing(['booking', 'operator.gcashAccounts']);

        if ($commission->operator_payout_at !== null
            || $commission->operator_payout_status === OperatorPayoutStatus::PaidOut) {
            return;
        }

        if ($this->hasPendingXenditPayout($commission)) {
            try {
                $this->syncExisting($commission);
            } catch (Throwable $exception) {
                $this->logPayoutFailure($commission, $exception);
            }

            return;
        }

        if (! $commission->canRecordOperatorPayout()) {
            return;
        }

        $amount = round((float) $commission->operator_net_amount, 2);

        if ($amount <= 0) {
            return;
        }

        try {
            if ($this->hasPendingXenditPayout($commission)) {
                $this->syncExisting($commission);

                return;
            }

            $account = $commission->operator?->approvedGcashAccount();

            if (! $account instanceof OperatorGcashAccount) {
                $this->logPayoutFailure($commission, new RuntimeException('Operator has no approved GCash account.'));

                return;
            }

            $referenceId = 'SS-PAYOUT-'.$commission->id;
            $payout = $this->xendit->createGcashPayout(
                $referenceId,
                $amount,
                $account->mobile_number,
                $account->account_name,
                'SafeSakay operator share '.$referenceId,
            );

            $payoutId = (string) $payout['id'];
            $commission->update([
                'operator_payout_reference' => 'xendit:'.$payoutId,
            ]);
            $commission->refresh();

            $this->applyStatus($commission, strtoupper((string) ($payout['status'] ?? '')), $payoutId, $amount);
        } catch (Throwable $exception) {
            $this->logPayoutFailure($commission, $exception);
        }
    }

    private function logPayoutFailure(RentalCommission $commission, Throwable $exception): void
    {
        Log::warning('xendit.payout.failed', [
            'operation' => 'create_gcash_payout',
            'booking_reference' => 'SS-PAYOUT-'.$commission->id,
            'message' => mb_substr($exception->getMessage(), 0, 300),
            'http_status' => $exception instanceof XenditGatewayException ? $exception->httpStatus() : 0,
            'error_code' => $exception instanceof XenditGatewayException ? $exception->errorCode() : null,
        ]);
    }

    public function syncByPayoutId(string $payoutId, string $status): void
    {
        $commission = RentalCommission::query()
            ->where('operator_payout_reference', 'xendit:'.$payoutId)
            ->first();

        if (! $commission instanceof RentalCommission || $commission->operator_payout_at !== null) {
            return;
        }

        $this->applyStatus(
            $commission,
            strtoupper($status),
            $payoutId,
            round((float) $commission->operator_net_amount, 2),
        );
    }

    private function syncExisting(RentalCommission $commission): void
    {
        $payoutId = substr((string) $commission->operator_payout_reference, strlen('xendit:'));
        $payout = $this->xendit->getPayout($payoutId);
        $this->applyStatus(
            $commission,
            strtoupper((string) ($payout['status'] ?? '')),
            $payoutId,
            round((float) $commission->operator_net_amount, 2),
        );
    }

    private function applyStatus(RentalCommission $commission, string $status, string $payoutId, float $amount): void
    {
        if (in_array($status, ['SUCCEEDED', 'COMPLETED'], true)) {
            $this->commissions->markAutomaticGcashPayout($commission, $payoutId, $amount);

            return;
        }

        if (in_array($status, ['FAILED', 'CANCELLED', 'CANCELED', 'REVERSED'], true)) {
            $commission->update([
                'operator_payout_reference' => null,
                'operator_payout_remarks' => 'Xendit GCash payout '.$status.'. It can be sent again.',
            ]);
            $this->commissions->recordEvent($commission, null, 'payout_failed', [
                'payout_id' => $payoutId,
                'status' => $status,
            ]);
        }
    }

    private function hasPendingXenditPayout(RentalCommission $commission): bool
    {
        return $commission->operator_payout_at === null
            && is_string($commission->operator_payout_reference)
            && str_starts_with($commission->operator_payout_reference, 'xendit:');
    }
}

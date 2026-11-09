<?php

namespace App\Services;

use App\Enums\CommissionSettlementStatus;
use App\Enums\UserRole;
use App\Models\RentalCommission;
use App\Models\User;
use App\Notifications\CommissionSettlementNotification;
use Illuminate\Support\Carbon;

class CommissionSettlementReminderService
{
    public function process(): int
    {
        $processed = 0;

        RentalCommission::query()
            ->withoutGlobalScope('organization')
            ->with(['booking', 'operator.user'])
            ->whereIn('settlement_status', [
                CommissionSettlementStatus::PendingSettlement,
                CommissionSettlementStatus::Overdue,
                CommissionSettlementStatus::Rejected,
                CommissionSettlementStatus::AwaitingVerification,
            ])
            ->whereNotNull('settlement_due_at')
            ->chunkById(100, function ($commissions) use (&$processed): void {
                foreach ($commissions as $commission) {
                    if (! $commission->requiresOperatorRemittance()) {
                        continue;
                    }

                    if ($commission->hasOnTimeGcashSubmissionPendingVerification()) {
                        continue;
                    }

                    if ($commission->settlement_status === CommissionSettlementStatus::AwaitingVerification) {
                        continue;
                    }

                    $processed += $this->processOne($commission);
                }
            });

        return $processed;
    }

    private function processOne(RentalCommission $commission): int
    {
        $verifiedDay = $commission->payment_verified_at?->startOfDay();

        if ($verifiedDay === null) {
            return 0;
        }

        $today = now()->startOfDay();
        $daysSinceVerified = (int) $verifiedDay->diffInDays($today);
        $actions = 0;

        if ($daysSinceVerified >= 1 && $commission->settlement_reminder_day_1_sent_at === null) {
            $this->notifyOperator($commission, 'reminder_day_1');
            $commission->update(['settlement_reminder_day_1_sent_at' => now()]);
            app(RentalCommissionService::class)->recordEvent($commission, null, 'reminder_day_1');
            $actions++;
        }

        if ($daysSinceVerified >= 2 && $commission->settlement_reminder_day_2_sent_at === null) {
            $this->notifyOperator($commission, 'reminder_day_2');
            $commission->update(['settlement_reminder_day_2_sent_at' => now()]);
            app(RentalCommissionService::class)->recordEvent($commission, null, 'reminder_day_2');
            $actions++;
        }

        if ($commission->settlement_due_at !== null
            && now()->gt($commission->settlement_due_at)
            && $commission->settlement_status !== CommissionSettlementStatus::Overdue) {
            $commission->update([
                'settlement_status' => CommissionSettlementStatus::Overdue,
                'marked_overdue_at' => now(),
            ]);
            $this->notifyOperator($commission, 'overdue_operator');
            $this->notifyAdmins($commission, 'overdue_admin');
            app(RentalCommissionService::class)->recordEvent($commission, null, 'marked_overdue');
            $actions++;
        }

        return $actions;
    }

    private function notifyOperator(RentalCommission $commission, string $event): void
    {
        $user = $commission->operator?->user;

        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notify(new CommissionSettlementNotification($commission->fresh(), $event));
        } catch (\Throwable) {
            // Keep scheduler running if notifications table is missing.
        }
    }

    private function notifyAdmins(RentalCommission $commission, string $event): void
    {
        User::query()
            ->where('organization_id', $commission->organization_id)
            ->where('role', UserRole::Administrator)
            ->get()
            ->each(function (User $admin) use ($commission, $event): void {
                try {
                    $admin->notify(new CommissionSettlementNotification($commission->fresh(), $event));
                } catch (\Throwable) {
                    //
                }
            });
    }
}

<?php

namespace App\Services;

use App\Models\BoatBooking;
use App\Models\Operator;
use App\Models\OperatorNoShowReport;
use App\Models\OperatorNoShowViolation;
use App\Models\User;
use App\Notifications\OperatorNoShowDecisionNotification;
use Illuminate\Support\Facades\DB;

class OperatorNoShowViolationService
{
    public const MAX_STRIKES = 3;

    public function confirmedStrikeCount(Operator $operator): int
    {
        return (int) OperatorNoShowViolation::query()
            ->where('operator_id', $operator->id)
            ->count();
    }

    public function recordOnVerifiedReport(OperatorNoShowReport $report, User $admin): ?OperatorNoShowViolation
    {
        $report->loadMissing(['booking.boat.operator']);
        $booking = $report->booking;
        $operator = $booking?->boat?->operator;

        if (! $booking instanceof BoatBooking || ! $operator instanceof Operator) {
            return null;
        }

        return DB::transaction(function () use ($report, $booking, $operator, $admin) {
            $existing = OperatorNoShowViolation::query()
                ->where('operator_no_show_report_id', $report->id)
                ->first();

            if ($existing instanceof OperatorNoShowViolation) {
                return $existing;
            }

            $strike = $this->confirmedStrikeCount($operator) + 1;

            $violation = OperatorNoShowViolation::query()->create([
                'organization_id' => $report->organization_id,
                'operator_id' => $operator->id,
                'operator_no_show_report_id' => $report->id,
                'boat_booking_id' => $booking->id,
                'strike_number' => min($strike, self::MAX_STRIKES),
                'incident_date' => $report->snapshot_booking_date ?? $booking->booking_date,
                'booking_code' => $booking->code ?? ('#'.$booking->id),
                'reason' => 'Confirmed operator no-show',
                'recorded_by_user_id' => $admin->id,
            ]);

            $booking->recordActivity(
                'operator_no_show_violation_recorded',
                $admin,
                sprintf('%d/%d violation — %s', $violation->strike_number, self::MAX_STRIKES, $operator->full_name),
            );

            $this->notifyOperatorStrike($booking, $operator, $violation->strike_number);

            if ($violation->strike_number >= self::MAX_STRIKES && ! $operator->isAccountSuspended()) {
                $operator->suspendAccount('Multiple violations');
                $this->notifyOperatorSuspended($booking, $operator);
            }

            return $violation;
        });
    }

    private function notifyOperatorStrike(BoatBooking $booking, Operator $operator, int $strike): void
    {
        $user = $operator->user;
        if (! $user instanceof User) {
            return;
        }

        $detail = match ($strike) {
            1 => '1st violation — warning recorded.',
            2 => '2nd violation — final warning.',
            default => '3rd violation — account suspended for 7 days.',
        };

        try {
            $user->notify(new OperatorNoShowDecisionNotification($booking, 'operator_no_show_violation_recorded', $detail));
        } catch (\Throwable) {
        }
    }

    private function notifyOperatorSuspended(BoatBooking $booking, Operator $operator): void
    {
        $user = $operator->user;
        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notify(new OperatorNoShowDecisionNotification(
                $booking,
                'operator_no_show_rental_suspended',
                'Your account is suspended for 7 days. You cannot sign in until the suspension ends.',
            ));
        } catch (\Throwable) {
        }
    }
}

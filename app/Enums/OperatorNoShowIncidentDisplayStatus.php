<?php

namespace App\Enums;

enum OperatorNoShowIncidentDisplayStatus: string
{
    case AwaitingPassengerDecision = 'awaiting_passenger_decision';
    case ResolutionProcessing = 'resolution_processing';
    case AwaitingOperatorConfirmation = 'awaiting_operator_confirmation';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case AwaitingResolution = 'awaiting_resolution';
    case Rescheduling = 'rescheduling';
    case RefundProcessing = 'refund_processing';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Delayed = 'delayed';
    case ReplacementSearch = 'replacement_search';

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending Verification',
            self::Verified => 'Verified',
            self::AwaitingPassengerDecision => 'Awaiting Passenger Decision',
            self::AwaitingResolution => 'Awaiting Resolution',
            self::ResolutionProcessing => 'Resolution Processing',
            self::AwaitingOperatorConfirmation => 'Awaiting Operator',
            self::Rescheduling => 'Rescheduling',
            self::RefundProcessing => 'Refund Processing',
            self::Resolved => 'Resolved',
            self::Rejected => 'Rejected',
            self::Delayed => 'Delayed',
            self::ReplacementSearch => 'Finding Replacement',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PendingVerification => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
            self::Verified => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
            self::AwaitingPassengerDecision => 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200',
            self::AwaitingResolution => 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-200',
            self::ResolutionProcessing => 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-200',
            self::AwaitingOperatorConfirmation => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100',
            self::Rescheduling => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
            self::RefundProcessing => 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-200',
            self::Resolved => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
            self::Rejected => 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300',
            self::Delayed => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
            self::ReplacementSearch => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
        };
    }
}

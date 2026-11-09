<?php

namespace App\Console\Commands;

use App\Services\CommissionSettlementReminderService;
use Illuminate\Console\Command;

class ProcessCommissionSettlementReminders extends Command
{
    protected $signature = 'commissions:process-settlement-reminders';

    protected $description = 'Send commission settlement reminders and mark overdue operator remittances.';

    public function handle(CommissionSettlementReminderService $reminders): int
    {
        $count = $reminders->process();

        $this->info("Processed {$count} commission settlement reminder action(s).");

        return self::SUCCESS;
    }
}

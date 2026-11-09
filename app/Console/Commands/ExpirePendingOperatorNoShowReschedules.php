<?php

namespace App\Console\Commands;

use App\Services\OperatorNoShowService;
use Illuminate\Console\Command;

class ExpirePendingOperatorNoShowReschedules extends Command
{
    protected $signature = 'no-show:expire-pending-reschedules';

    protected $description = 'Decline operator no-show reschedule requests when the replacement operator did not confirm within 30 minutes.';

    public function handle(OperatorNoShowService $service): int
    {
        $ids = $service->expireStalePendingOperatorReschedules(now());

        $count = count($ids);
        $this->info($count === 0
            ? 'No stale operator reschedule confirmations to expire.'
            : "Auto-declined {$count} reschedule request(s): ".implode(', ', $ids));

        return self::SUCCESS;
    }
}

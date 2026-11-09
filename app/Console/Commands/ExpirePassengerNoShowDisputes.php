<?php

namespace App\Console\Commands;

use App\Services\PassengerNoShowService;
use Illuminate\Console\Command;

class ExpirePassengerNoShowDisputes extends Command
{
    protected $signature = 'passenger-no-show:expire-disputes';

    protected $description = 'Finalize undisputed passenger no-shows after the 1-day dispute window.';

    public function handle(PassengerNoShowService $service): int
    {
        $ids = $service->expireUndisputedReports();

        $count = count($ids);
        $this->info($count === 0
            ? 'No expired passenger no-show disputes to finalize.'
            : "Auto-finalized {$count} passenger no-show report(s): ".implode(', ', $ids));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\ExpirePendingRentalBookingsService;
use Illuminate\Console\Command;

class ExpirePendingRentalBookings extends Command
{
    protected $signature = 'rentals:expire-pending';

    protected $description = 'Reject pending rentals not approved within 2 days, and awaiting-payment rentals with no payment within 2 days.';

    public function handle(ExpirePendingRentalBookingsService $service): int
    {
        $ids = $service->expire(now());

        $count = count($ids);
        $this->info($count === 0
            ? 'No pending rentals to expire.'
            : "Expired {$count} pending rental(s): ".implode(', ', $ids));

        return self::SUCCESS;
    }
}

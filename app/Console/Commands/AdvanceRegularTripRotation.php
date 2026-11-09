<?php

namespace App\Console\Commands;

use App\Services\BoatStatusSyncService;
use App\Services\RegularTripRotationService;
use Illuminate\Console\Command;

class AdvanceRegularTripRotation extends Command
{
    protected $signature = 'rotation:advance {--days=7}';

    protected $description = 'Create the next days of regular trip rotation from the last setup';

    public function handle(RegularTripRotationService $rotation, BoatStatusSyncService $boatStatus): int
    {
        $created = $rotation->syncUpcoming(daysAhead: (int) $this->option('days'));
        $boatStatus->syncOrganization(null);

        $this->info("Created {$created} automatic rotation trip".($created === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}

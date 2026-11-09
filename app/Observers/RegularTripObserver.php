<?php

namespace App\Observers;

use App\Models\Boat;
use App\Models\RegularTrip;
use App\Services\BoatStatusSyncService;

class RegularTripObserver
{
    public function saved(RegularTrip $trip): void
    {
        $this->sync($trip);
    }

    public function deleted(RegularTrip $trip): void
    {
        $this->sync($trip);
    }

    private function sync(RegularTrip $trip): void
    {
        $boat = $trip->relationLoaded('boat')
            ? $trip->boat
            : Boat::query()->withoutGlobalScope('organization')->find($trip->boat_id);

        if ($boat instanceof Boat) {
            app(BoatStatusSyncService::class)->sync($boat);
        }
    }
}

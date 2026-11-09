<?php

namespace Tests\Concerns;

use App\Enums\RegularTripStatus;
use App\Models\RegularTrip;
use App\Models\User;
use App\Services\RegularTripRotationService;

trait FinishesRegularTripCycles
{
    protected function finishRegularTripCycle(User $user, RegularTrip $trip, bool $returnToQueue = true): RegularTrip
    {
        app(RegularTripRotationService::class)->ensureReturnTrip($trip);

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $trip), [
                'status' => RegularTripStatus::OnTrip->value,
                'action' => 'arrive',
            ])
            ->assertRedirect();

        $return = RegularTrip::query()
            ->where('return_of_id', $trip->id)
            ->first();

        $this->assertNotNull($return, 'Expected a linked return trip.');

        $this->actingAs($user)
            ->patch(route('regular-trips.status.update', $return), [
                'status' => RegularTripStatus::Completed->value,
                'action' => 'complete',
                'return_to_queue' => $returnToQueue,
            ])
            ->assertRedirect();

        return $return->fresh();
    }
}

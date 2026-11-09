<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTripPassengerRequest;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use App\Services\RegularTripRotationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TripPassengerController extends Controller
{
    public function store(StoreTripPassengerRequest $request, RegularTrip $regularTrip): RedirectResponse
    {
        $data = $request->validated();
        $data['regular_trip_id'] = $regularTrip->id;
        $data['sort_order'] = ((int) $regularTrip->passengers()->max('sort_order')) + 1;
        $data['contact_number'] = trim((string) ($data['contact_number'] ?? ''));
        $data['remarks'] = $data['remarks'] ?? null;

        app(RegularTripRotationService::class)->beginBoarding($regularTrip);

        TripPassenger::query()->create($data);

        $regularTrip->syncPassengerCount();
        app(RegularTripRotationService::class)->refreshBoarding($regularTrip->fresh());

        if ($request->input('finish') === '1' && ! $regularTrip->isOutbound()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Passengers have been recorded.']);

            return to_route('regular-trips.show', [
                'regularTrip' => $regularTrip,
                'duty' => 1,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Passenger added to the trip manifest.']);

        if ($request->input('from') === 'rotation') {
            return to_route('schedules.regular-trip-rotation');
        }

        return to_route('regular-trips.show', $regularTrip);
    }

    public function update(
        StoreTripPassengerRequest $request,
        RegularTrip $regularTrip,
        TripPassenger $tripPassenger,
    ): RedirectResponse {
        $this->ensurePassengerBelongsToTrip($regularTrip, $tripPassenger);

        $data = $request->validated();
        $data['contact_number'] = trim((string) ($data['contact_number'] ?? ''));
        $data['remarks'] = $data['remarks'] ?? null;

        $tripPassenger->update($data);
        $regularTrip->syncPassengerCount();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Passenger details updated.']);

        return to_route('regular-trips.show', $regularTrip);
    }

    public function destroy(RegularTrip $regularTrip, TripPassenger $tripPassenger): RedirectResponse
    {
        $this->ensurePassengerBelongsToTrip($regularTrip, $tripPassenger);

        $tripPassenger->delete();
        $regularTrip->syncPassengerCount();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Passenger removed from the trip manifest.']);

        return to_route('regular-trips.show', $regularTrip);
    }

    private function ensurePassengerBelongsToTrip(RegularTrip $trip, TripPassenger $passenger): void
    {
        abort_unless((int) $passenger->regular_trip_id === (int) $trip->id, 404);
    }
}

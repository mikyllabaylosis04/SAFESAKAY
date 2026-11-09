<?php

namespace App\Http\Controllers\Api;

use App\Enums\PassengerFareType;
use App\Enums\RegularTripStatus;
use App\Http\Controllers\Api\Concerns\ReturnsMobileUser;
use App\Http\Controllers\Controller;
use App\Models\RegularTrip;
use App\Models\TripPassenger;
use App\Services\RegularTripRotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TripController extends Controller
{
    use ReturnsMobileUser;

    public function index(RegularTripRotationService $rotation): JsonResponse
    {
        $user = auth()->user();

        if ($user?->organization_id) {
            $rotation->syncUpcoming($user->organization_id);
            $rotation->refreshBoardingForDay($user->organization_id);
        }

        $query = RegularTrip::query()
            ->with(['boat.pictures', 'passengers'])
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::OnTrip,
                RegularTripStatus::Delayed,
            ])
            ->orderBy('trip_date')
            ->orderBy('rotation_order')
            ->orderBy('departure_time');

        $operatorBoatId = null;

        if ($user?->isMobileOperator()) {
            $user->loadMissing('operatorProfiles.boat');
            $operatorBoatId = $user->operatorProfiles->first()?->boat?->id;

            if ($operatorBoatId) {
                $query->where('boat_id', $operatorBoatId);
            } else {
                $query->whereRaw('0 = 1');
            }
        }

        $trips = $query->limit(20)->get();

        $payload = [
            'data' => $trips
                ->map(fn (RegularTrip $trip) => $trip->toMobileAssignment())
                ->values(),
        ];

        if ($user?->isMobileOperator()) {
            $payload['today'] = $this->operatorRotation($user);
        }

        return response()->json($payload);
    }

    public function update(Request $request, int $trip, RegularTripRotationService $rotation): JsonResponse
    {
        $record = $this->operatorTrip($request, $trip);

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['depart', 'arrive', 'complete'])],
            'return_to_queue' => ['sometimes', 'boolean'],
        ]);

        try {
            $updated = match ($data['action']) {
                'depart' => $record->isOutbound()
                    ? $rotation->depart($record)
                    : $rotation->departReturn($record),
                'arrive' => $rotation->arriveBackAtOrigin($record),
                default => $rotation->completeReturn($record, $request->boolean('return_to_queue', true)),
            };
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return response()->json([
            'data' => $updated->fresh(['boat', 'passengers'])->toMobileAssignment(),
        ]);
    }

    public function storePassenger(Request $request, int $trip): JsonResponse
    {
        $record = $this->operatorTrip($request, $trip);

        if (! $record->canEncodePassengers()) {
            throw ValidationException::withMessages([
                'full_name' => ['Passenger encoding is not open for this trip yet.'],
            ]);
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'fare_type' => ['nullable', Rule::enum(PassengerFareType::class)],
            'gender' => ['required', 'string', 'in:Male,Female,Prefer not to say'],
            'contact_number' => ['required', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        if ($record->isAtCapacity()) {
            throw ValidationException::withMessages([
                'full_name' => ['Boat capacity has been reached for this trip.'],
            ]);
        }

        $age = (int) $data['age'];
        $fareType = PassengerFareType::tryFrom((string) ($data['fare_type'] ?? ''))
            ?? PassengerFareType::fromAge($age);

        app(RegularTripRotationService::class)->beginBoarding($record);

        TripPassenger::query()->create([
            'organization_id' => $record->organization_id,
            'regular_trip_id' => $record->id,
            'full_name' => $data['full_name'],
            'age' => $age,
            'fare_type' => $fareType,
            'gender' => $data['gender'],
            'contact_number' => $data['contact_number'],
            'remarks' => $data['remarks'] ?? null,
            'sort_order' => ((int) $record->passengers()->max('sort_order')) + 1,
        ]);

        $record->syncPassengerCount();
        app(RegularTripRotationService::class)->refreshBoarding($record->fresh());

        return response()->json([
            'data' => $record->fresh(['boat', 'passengers'])->toMobileAssignment(),
        ], 201);
    }

    private function operatorTrip(Request $request, int $tripId): RegularTrip
    {
        $user = $request->user();

        if (! $user?->isMobileOperator()) {
            abort(403, 'Only boat operators can update regular trips.');
        }

        $user->loadMissing('operatorProfiles.boat');
        $boatId = $user->operatorProfiles->first()?->boat?->id;

        $record = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->with(['boat.operator', 'passengers'])
            ->findOrFail($tripId);

        if (! $boatId || (int) $record->boat_id !== (int) $boatId) {
            abort(403, 'This trip is not assigned to your boat.');
        }

        return $record;
    }
}

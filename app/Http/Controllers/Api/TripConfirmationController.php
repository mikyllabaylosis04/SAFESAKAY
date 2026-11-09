<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ReturnsMobileUser;
use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\BoatBookingReplacement;
use App\Models\User;
use App\Services\TripConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripConfirmationController extends Controller
{
    use ReturnsMobileUser;

    public function scheduledTrips(Request $request, TripConfirmationService $trips): JsonResponse
    {
        $user = $request->user();
        if (! $user->isMobileOperator()) {
            abort(403);
        }

        $today = now('Asia/Manila')->toDateString();
        $boatIds = \App\Models\Operator::query()
            ->where('user_id', $user->id)
            ->pluck('boat_id');

        $bookings = BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->with(['boat.operator', 'passengers'])
            ->whereIn('boat_id', $boatIds)
            ->whereDate('booking_date', '>=', $today)
            ->whereIn('status', ['confirmed', 'awaiting_payment', 'ongoing'])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (BoatBooking $booking) => $this->row($booking, $trips, $user));

        return response()->json(['data' => $bookings]);
    }

    public function confirmTrip(Request $request, int $booking, TripConfirmationService $trips): JsonResponse
    {
        $user = $request->user();
        $record = $this->assignedBooking($user, $booking);
        $updated = $trips->confirmByOperator($record, $user);

        return response()->json(['data' => $this->row($updated, $trips, $user)]);
    }

    public function cannotOperate(Request $request, int $booking, TripConfirmationService $trips): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $request->user();
        $record = $this->assignedBooking($user, $booking);
        $updated = $trips->cannotOperateByOperator($record, $user, $data['reason'] ?? null);

        return response()->json(['data' => $this->row($updated, $trips, $user)]);
    }

    public function confirmArrival(Request $request, int $booking, TripConfirmationService $trips): JsonResponse
    {
        $user = $request->user();
        $record = $this->assignedBooking($user, $booking);
        $updated = $trips->confirmMeetingPointArrival($record, $user);

        return response()->json(['data' => $this->row($updated, $trips, $user)]);
    }

    public function checkInPassenger(Request $request, int $booking, TripConfirmationService $trips): JsonResponse
    {
        $data = $request->validate(['passenger_id' => ['required', 'integer']]);
        $user = $request->user();
        $record = $this->assignedBooking($user, $booking);
        $trips->checkInPassenger($record, $user, (int) $data['passenger_id']);
        $record = $record->fresh(['passengers', 'boat.operator']);

        return response()->json(['data' => $this->row($record, $trips, $user)]);
    }

    public function markPassengersNoShow(Request $request, int $booking, TripConfirmationService $trips): JsonResponse
    {
        $data = $request->validate([
            'passenger_ids' => ['required', 'array', 'min:1'],
            'passenger_ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $record = $this->assignedBooking($user, $booking);
        $updated = $trips->markPassengersNoShow(
            $record,
            $user,
            array_map('intval', $data['passenger_ids']),
            $data['remarks'] ?? null,
        );

        return response()->json(['data' => $this->row($updated, $trips, $user)]);
    }

    public function respondReplacement(Request $request, BoatBookingReplacement $replacement, TripConfirmationService $trips): JsonResponse
    {
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'decline_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $trips->replacementOperatorRespond(
            $replacement,
            $request->user(),
            (bool) $data['accept'],
            $data['decline_reason'] ?? null,
        );

        return response()->json(['data' => ['ok' => true]]);
    }

    private function assignedBooking($user, int $bookingId): BoatBooking
    {
        $record = BoatBooking::query()
            ->with(['boat.operator', 'passengers'])
            ->findOrFail($bookingId);

        $this->assertAssignedOperator($user, $record);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(BoatBooking $booking, TripConfirmationService $trips, $user): array
    {
        $time = \Carbon\Carbon::parse($booking->start_time);

        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'booking_date' => $booking->booking_date->toDateString(),
            'booking_date_label' => $booking->booking_date->format('M d, Y'),
            'start_time_label' => $time->format('g:i A'),
            'destination' => $booking->destination ?: $booking->package,
            'boat_name' => $booking->boat?->name,
            'passenger_count' => $booking->passenger_count,
            ...$trips->operatorTripPayload($booking, $user),
        ];
    }

    private function assertAssignedOperator(User $user, BoatBooking $booking): void
    {
        if (! $user->isMobileOperator()) {
            abort(403);
        }

        $booking->loadMissing('boat.operator');

        if ((int) $booking->boat?->operator?->user_id !== (int) $user->id) {
            abort(403);
        }
    }
}

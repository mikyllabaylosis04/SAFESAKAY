<?php

namespace App\Http\Controllers;

use App\Enums\PassengerAttendanceStatus;
use App\Models\BoatBooking;
use App\Models\BoatBookingReplacement;
use App\Services\TripConfirmationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TripConfirmationController extends Controller
{
    public function sendRequest(BoatBooking $boatBooking, TripConfirmationService $trips): RedirectResponse
    {
        $trips->sendConfirmationRequest($boatBooking, request()->user());

        return back()->with('success', 'Confirmation request sent to the operator.');
    }

    public function confirmTrip(Request $request, BoatBooking $boatBooking, TripConfirmationService $trips): RedirectResponse
    {
        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $trips->confirmByAdminPhone($boatBooking, $request->user(), $data['remarks'] ?? null);

        return back()->with('success', 'Trip confirmed by phone.');
    }

    public function cannotOperate(Request $request, BoatBooking $boatBooking, TripConfirmationService $trips): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $trips->cannotOperateByAdminPhone($boatBooking, $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'Recorded that the operator cannot operate this trip.');
    }

    public function proposeReplacement(Request $request, BoatBooking $boatBooking, TripConfirmationService $trips): RedirectResponse
    {
        $data = $request->validate([
            'replacement_boat_id' => ['required', 'integer', 'exists:boats,id'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $trips->proposeReplacement(
            $boatBooking,
            $request->user(),
            (int) $data['replacement_boat_id'],
            $data['admin_notes'] ?? null,
        );

        return back()->with('success', 'Replacement operator notified.');
    }

    public function approveReplacement(Request $request, BoatBookingReplacement $replacement, TripConfirmationService $trips): RedirectResponse
    {
        $trips->approveReplacement($replacement, $request->user());

        return back()->with('success', 'Replacement assignment approved.');
    }

    public function noReplacement(Request $request, BoatBooking $boatBooking, TripConfirmationService $trips): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $trips->markNoReplacementAvailable($boatBooking, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Passenger notified that no replacement is available.');
    }

    public function correctAttendance(Request $request, BoatBooking $boatBooking, TripConfirmationService $trips): RedirectResponse
    {
        $data = $request->validate([
            'passenger_id' => ['required', 'integer'],
            'attendance_status' => ['required', Rule::enum(PassengerAttendanceStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $trips->adminCorrectPassengerAttendance(
            $boatBooking,
            $request->user(),
            (int) $data['passenger_id'],
            PassengerAttendanceStatus::from($data['attendance_status']),
            $data['note'] ?? null,
        );

        return back()->with('success', 'Passenger attendance updated.');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ReturnsMobileUser;
use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\OperatorNoShowReport;
use App\Services\OperatorNoShowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperatorNoShowController extends Controller
{
    use ReturnsMobileUser;

    public function show(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $record = $this->passengerBooking($request, $booking);

        return response()->json([
            'data' => $noShow->mobileIncidentPayload($record, $request->user()),
            'incident_information' => \App\Support\RentalIncidentPresenter::forBooking(
                $record,
                $request->user(),
            ),
        ]);
    }

    public function rescheduleBoats(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $record = $this->passengerBooking($request, $booking);
        $date = $request->validate(['date' => ['nullable', 'date', 'after_or_equal:today']])['date'] ?? null;

        return response()->json([
            'data' => $noShow->rescheduleBoatOptions($record, $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null),
        ]);
    }

    public function submitRefundRequest(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $record = $this->passengerBooking($request, $booking);

        $data = $request->validate([
            'gcash_account_name' => ['required', 'string', 'min:2', 'max:120'],
            'gcash_mobile' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'confirmed' => ['accepted'],
            'qr' => ['nullable', 'image', 'max:5120'],
        ]);

        $report = $noShow->passengerSubmitRefundRequest(
            $record,
            $request->user(),
            $data['gcash_account_name'],
            $data['gcash_mobile'],
            $request->file('qr'),
        );

        return response()->json([
            'data' => $noShow->mobileIncidentPayload($record->fresh(), $request->user()),
            'report_id' => $report->id,
        ]);
    }

    public function refundReceipt(Request $request, int $booking): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->user();
        $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($booking);

        if ($user->isMobileOperator()) {
            abort(403);
        }

        if ((int) $record->user_id !== (int) $user->id && ! $user->isAdministrator()) {
            abort(403);
        }

        $report = $record->operatorNoShowReports()->latest('id')->first();
        if (! $report instanceof OperatorNoShowReport
            || ! filled($report->refund_receipt_path)
            || $report->refund_status !== \App\Enums\OperatorNoShowRefundStatus::Refunded) {
            abort(404);
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->response($report->refund_receipt_path);
    }

    public function submitRescheduleRequest(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $record = $this->passengerBooking($request, $booking);

        $data = $request->validate([
            'boat_id' => ['required', 'integer', 'exists:boats,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'string', 'max:8'],
        ]);

        $report = $noShow->passengerSubmitRescheduleRequest(
            $record,
            $request->user(),
            (int) $data['boat_id'],
            $data['booking_date'],
            $data['start_time'],
        );

        return response()->json([
            'data' => $noShow->mobileIncidentPayload($record->fresh(), $request->user()),
            'report_id' => $report->id,
        ]);
    }

    public function respondReschedule(Request $request, int $booking, OperatorNoShowService $noShow): JsonResponse
    {
        $user = $request->user();
        $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($booking);
        $this->assertBookingOrganizationOperational($record);

        if (! $noShow->canOperatorRespondReschedule($record, $user)) {
            abort(403, 'Only the operator assigned to the selected replacement boat can respond to this reschedule request.');
        }

        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $noShow->operatorRespondReschedule(
            $record,
            $user,
            $data['action'] === 'approve',
            $data['note'] ?? null,
        );

        return app(BookingController::class)->show($request, $booking);
    }

    private function passengerBooking(Request $request, int $bookingId): BoatBooking
    {
        $user = $request->user();
        $record = BoatBooking::withoutGlobalScope('organization')->findOrFail($bookingId);

        if ((int) $record->user_id !== (int) $user->id) {
            abort(403, 'You can only manage your own rental.');
        }

        $this->assertBookingOrganizationOperational($record);

        return $record;
    }

    private function assertBookingOrganizationOperational(BoatBooking $booking): void
    {
        $booking->loadMissing('organization');
        \App\Models\Organization::abortInactive($booking->organization);
    }
}

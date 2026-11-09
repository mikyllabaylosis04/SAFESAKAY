<?php

namespace App\Http\Controllers\Api;

use App\Enums\BoatBookingStatus;
use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Models\RentalFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FeedbackController extends Controller
{
    public function show(Request $request, int $booking): JsonResponse
    {
        $record = $this->ownedBooking($request, $booking);

        return response()->json([
            'data' => $record->feedback?->toPayload(),
        ]);
    }

    public function store(Request $request, int $booking): JsonResponse
    {
        $record = $this->ownedBooking($request, $booking);

        if ($record->status !== BoatBookingStatus::Completed) {
            throw ValidationException::withMessages([
                'rating' => ['You can rate this trip after it is completed.'],
            ]);
        }

        if ($record->feedback()->exists()) {
            throw ValidationException::withMessages([
                'rating' => ['You already rated this trip.'],
            ]);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'tags' => ['nullable', 'array', 'max:8'],
            'tags.*' => ['string', 'max:40'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $comment = trim((string) ($data['comment'] ?? ''));

        $feedback = RentalFeedback::query()->create([
            'organization_id' => $record->organization_id,
            'boat_booking_id' => $record->id,
            'boat_id' => $record->boat_id,
            'user_id' => $request->user()->id,
            'rating' => $data['rating'],
            'tags' => array_values(array_unique($data['tags'] ?? [])),
            'comment' => $comment === '' ? null : $comment,
        ]);

        return response()->json([
            'message' => 'Thank you for rating your trip.',
            'data' => $feedback->toPayload(),
        ], 201);
    }

    private function ownedBooking(Request $request, int $booking): BoatBooking
    {
        $record = BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->with('feedback')
            ->find($booking);

        if (! $record instanceof BoatBooking || (int) $record->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        return $record;
    }
}

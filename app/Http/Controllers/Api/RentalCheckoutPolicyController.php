<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoatBooking;
use App\Support\RentalCheckoutPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RentalCheckoutPolicyController extends Controller
{
    public function accept(Request $request, int $booking): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->isMobileOperator()) {
            abort(403, 'Only passengers can accept checkout policies.');
        }

        $data = $request->validate([
            'policy_version' => ['required', 'string', 'max:32'],
        ]);

        if ($data['policy_version'] !== RentalCheckoutPolicy::VERSION) {
            return response()->json([
                'message' => 'This policy version is outdated. Open the full policy again and accept the current version.',
            ], 422);
        }

        $record = BoatBooking::withoutGlobalScope('organization')
            ->whereKey($booking)
            ->firstOrFail();

        if ((int) $record->user_id !== (int) $user->id) {
            abort(403, 'You cannot accept policies for this booking.');
        }

        $acceptance = RentalCheckoutPolicy::recordAcceptance(
            $record,
            $user,
            $request->ip(),
        );

        return response()->json([
            'accepted' => true,
            'policy_version' => RentalCheckoutPolicy::VERSION,
            'accepted_at' => $acceptance->accepted_at?->toIso8601String(),
            'accepted_at_label' => $acceptance->accepted_at
                ?->timezone('Asia/Manila')
                ->format('M d, Y · g:i A'),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\BoatStatus;
use App\Enums\SafetyEquipment;
use App\Enums\UserRole;
use App\Http\Controllers\Api\Concerns\ReturnsMobileUser;
use App\Http\Controllers\Controller;
use App\Models\Boat;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\BoatStatusReportNotification;
use App\Services\BoatStatusSyncService;
use App\Services\WeatherSuspensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BoatController extends Controller
{
    use ReturnsMobileUser;

    public function index(): JsonResponse
    {
        $user = auth()->user();

        if (! $user?->organization_id && ! $user?->isMobileOperator()) {
            return response()->json(['data' => []]);
        }

        $query = Boat::query()
            ->withoutGlobalScope('organization')
            ->with(['pictures', 'operator', 'organization.routes', 'rentalDestinations'])
            ->withAvg('feedback', 'rating')
            ->withCount('feedback')
            ->where('is_active', true);

        if ($user->isMobileOperator()) {
            $query->whereHas('operator', fn ($operator) => $operator->where('user_id', $user->id));
        } elseif ($user->organization_id) {
            $query->where('organization_id', $user->organization_id)
                ->whereHas('organization', function ($organization) {
                    $organization->where('is_active', true)
                        ->whereNotNull('approved_at');
                });
        }

        $boats = $query
            ->orderBy('name')
            ->get()
            ->map(fn (Boat $boat) => $this->transform($boat, $user));

        return response()->json([
            'data' => $boats,
            'status_options' => $user->isMobileOperator() ? BoatStatus::adminOptions() : [],
        ]);
    }

    public function updateStatus(Request $request, int $boat, BoatStatusSyncService $sync): JsonResponse
    {
        $user = $request->user();

        if (! $user?->isMobileOperator()) {
            abort(403, 'Only boat operators can update boat status.');
        }

        $record = $this->operatorBoat($user, $boat);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(BoatStatus::class)->only(BoatStatus::adminManaged())],
        ]);

        $operational = BoatStatus::from($validated['status']);

        if (
            ! $record->registration_status->allowsAvailability()
            && $operational === BoatStatus::Available
        ) {
            return response()->json([
                'message' => 'Only a registered boat can be available.',
            ], 422);
        }

        $record = $sync->applyManagedStatus($record, $operational)
            ->load(['pictures', 'operator', 'organization.routes']);

        User::query()
            ->where('organization_id', $record->organization_id)
            ->whereIn('role', [UserRole::Administrator, UserRole::User])
            ->where('is_active', true)
            ->get()
            ->each(fn (User $admin) => $admin->notify(
                new BoatStatusReportNotification($record, $user, $operational)
            ));

        return response()->json([
            'data' => $this->transform($record, $user),
            'message' => match ($operational) {
                BoatStatus::Maintenance => 'Boat marked under maintenance.',
                BoatStatus::Unavailable => 'Boat marked unavailable.',
                default => 'Boat is available again.',
            },
        ]);
    }

    public function picture(Boat $boat, Request $request): StreamedResponse
    {
        $boat->loadMissing('pictures');
        $index = max(0, (int) $request->query('index', 0));
        $path = $boat->pictures->values()->get($index)?->path;

        if (! $path && $index === 0) {
            $path = $boat->picture_path;
        }

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $lastModified = Storage::disk('public')->lastModified($path);

        return Storage::disk('public')->response($path, headers: [
            'Cache-Control' => 'public, max-age=3600',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified).' GMT',
        ]);
    }

    public function operatorPicture(Boat $boat): StreamedResponse
    {
        $boat->loadMissing('operator');
        $path = $boat->operator?->picture_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $lastModified = Storage::disk('public')->lastModified($path);

        return Storage::disk('public')->response($path, headers: [
            'Cache-Control' => 'public, max-age=3600',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified).' GMT',
        ]);
    }

    private function operatorBoat(User $user, int $boatId): Boat
    {
        $boat = Boat::query()
            ->withoutGlobalScope('organization')
            ->with(['pictures', 'operator', 'organization.routes', 'rentalDestinations'])
            ->findOrFail($boatId);

        if ((int) $boat->operator?->user_id !== (int) $user->id) {
            abort(403, 'You can only update the status of your assigned boat.');
        }

        return $boat;
    }

    public function availability(Request $request, int $boat): JsonResponse
    {
        $date = $request->validate([
            'date' => ['required', 'date'],
        ])['date'];

        $record = Boat::query()
            ->withoutGlobalScope('organization')
            ->with('organization')
            ->findOrFail($boat);

        Organization::abortInactive($record->organization);

        $slots = Boat::bookingTimeSlots();
        $times = array_map(function (string $slot) use ($record, $date) {
            $reason = $record->slotConflictMessage($date, $slot);

            return [
                'value' => $slot,
                'label' => Carbon::parse($slot)->format('g:i A'),
                'available' => $reason === null,
                'reason' => $reason,
            ];
        }, $slots);

        $dayBooked = $record->hasCommittedPrivateBookingOn($date);
        $dayReason = $dayBooked
            ? 'This boat is already booked that day.'
            : $record->slotConflictMessage($date);

        $weather = app(WeatherSuspensionService::class);
        $orgId = (int) $record->organization_id;
        $onDate = Carbon::parse($date)->toDateString();
        $weatherBlock = $weather->rentalBlockMessage($orgId, $onDate);
        $watch = $weatherBlock ? null : $weather->watchCovering($orgId, $onDate);
        $weatherTone = $weatherBlock ? 'red' : ($watch ? 'yellow' : null);

        if ($weatherBlock) {
            $dayReason = $weatherBlock;
        }

        $openTimes = array_values(array_filter($times, fn (array $slot) => $slot['available']));

        return response()->json([
            'date' => $onDate,
            'day_available' => $dayReason === null && $openTimes !== [] && $weatherTone !== 'red',
            'day_booked' => $dayBooked && $weatherTone !== 'red',
            'day_reason' => $dayReason ?? ($openTimes === [] ? 'This boat is unavailable at all listed times on this date.' : null),
            'weather_tone' => $weatherTone,
            'weather_can_book' => $weatherTone === null,
            'times' => $times,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Boat $boat, User $user): array
    {
        $safety = SafetyEquipment::summarize($boat->safety_equipment, $boat->life_jacket_quantity);

        return [
            'id' => $boat->id,
            'name' => $boat->name,
            'code' => $boat->code,
            'capacity' => $boat->capacity,
            'type' => $boat->type ?: 'Pump Boat',
            'status' => $boat->status->value,
            'status_label' => $boat->status->label(),
            'life_jacket_quantity' => $boat->life_jacket_quantity,
            'license_no' => $boat->license_no,
            'registration_date_label' => $boat->registration_date?->format('M d, Y'),
            'safety_equipment' => $safety ? explode("\n", $safety) : [],
            'picture_url' => $this->boatPictureUrl($boat),
            'picture_urls' => $this->boatPictureUrls($boat),
            'listed_for_rental' => $boat->isListedForPrivateBooking(),
            'open_for_booking' => $boat->isListedForPrivateBooking(),
            'open_for_booking_today' => $boat->isOpenForPrivateBookingOn(),
            'unavailable_today' => $boat->isListedForPrivateBooking() && ! $boat->isOpenForPrivateBookingOn(),
            'availability_note' => $boat->status === BoatStatus::OnTrip && $boat->isListedForPrivateBooking()
                ? 'On a trip today. Other dates are still open for booking.'
                : null,
            'rating_average' => $boat->feedback_avg_rating !== null ? round((float) $boat->feedback_avg_rating, 1) : null,
            'rating_count' => (int) ($boat->feedback_count ?? 0),
            'operator_name' => $boat->operator?->full_name,
            'operator_user_id' => $boat->operator?->user_id,
            'home_port' => $boat->organization?->homePort() ?? 'Lakandula',
            'rental_destinations' => $boat->rentalDestinations
                ->filter(fn ($route) => $route->isExclusiveBoatRental() && $route->is_active)
                ->sortBy('destination')
                ->map(function ($route) use ($boat) {
                    $destination = $route->toRentalDestination(
                        $boat->organization?->rentalAreaLabel() ?? 'Siargao Islands',
                        $this->boatPictureUrl($boat),
                    );
                    $commission = app(\App\Services\RentalCommissionService::class)
                        ->breakdownForOrganization(
                            $boat->organization_id,
                            (float) ($destination['rental_price'] ?? 0),
                        );

                    return [
                        ...$destination,
                        'commission_enabled' => $commission['enabled'],
                        'commission_percentage' => $commission['percentage'],
                        'commission_percentage_label' => $commission['percentage_label'],
                        'commission_amount' => $commission['amount'],
                        'commission_amount_label' => $commission['amount_label'],
                        'operator_net_amount' => $commission['operator_net'],
                        'operator_net_amount_label' => $commission['operator_net_label'],
                        'commission_included_in_total' => true,
                    ];
                })
                ->values()
                ->all(),
            'can_update_status' => $user->isMobileOperator()
                && (int) $boat->operator?->user_id === (int) $user->id,
            'today_rotation' => $user->isMobileOperator()
                && (int) $boat->operator?->user_id === (int) $user->id
                    ? $this->operatorRotation($user)
                    : null,
        ];
    }
}

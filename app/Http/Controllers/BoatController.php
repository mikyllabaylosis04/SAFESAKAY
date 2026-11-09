<?php

namespace App\Http\Controllers;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\OrganizationRouteType;
use App\Enums\SafetyEquipment;
use App\Enums\UserRole;
use App\Http\Requests\StoreBoatRequest;
use App\Models\Boat;
use App\Models\BoatHistory;
use App\Models\OperatorNoShowViolation;
use App\Models\OrganizationRoute;
use App\Models\User;
use App\Services\BoatStatusSyncService;
use App\Services\RegularTripRotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BoatController extends Controller
{
    public function index(BoatStatusSyncService $sync): Response
    {
        $sync->syncOrganization(auth()->user()?->organization_id);

        $boats = Boat::query()
            ->with([
                'operator.user',
                'histories',
                'pictures',
                'organization.routes',
                'rentalDestinations',
                'regularTrips',
                'bookings',
            ])
            ->latest()
            ->get();

        $activeBoats = $boats->where('is_active', true);

        return Inertia::render('boats/Index', [
            'boats' => $boats->map(fn (Boat $boat) => $this->transform($boat)),
            'stats' => [
                'total' => $activeBoats->count(),
                'available' => $activeBoats->where('status', BoatStatus::Available)->count(),
                'onDuty' => $activeBoats->where('status', BoatStatus::OnDuty)->count(),
                'onTrip' => $activeBoats->where('status', BoatStatus::OnTrip)->count(),
                'notAvailable' => $activeBoats
                    ->whereIn('status', [BoatStatus::Maintenance, BoatStatus::Unavailable])
                    ->count(),
                'archived' => $boats->where('is_active', false)->count(),
            ],
            'statusOptions' => BoatStatus::adminOptions(),
            'serviceOptions' => BoatService::options(),
            'rentalDestinations' => $this->rentalDestinationOptions(),
            'createOperatorBoatId' => session('create_operator_boat_id'),
        ]);
    }

    public function store(StoreBoatRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $safetyEquipment = $request->selectedSafetyEquipment();
        $lifeJacketQuantity = $request->lifeJacketQuantity();
        $services = $request->selectedServices();

        $boat = Boat::query()->create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'capacity' => $data['capacity'],
            'type' => 'Pump Boat',
            'license_no' => $data['license_no'] ?? null,
            'registration_date' => $data['registration_date'] ?? null,
            'registration_status' => BoatRegistrationStatus::Registered,
            'status' => $data['status'],
            'service_availability' => $services,
            'service_type' => BoatService::legacyType($services),
            'safety_equipment' => $safetyEquipment,
            'life_jacket_quantity' => $lifeJacketQuantity,
            'safety_information' => SafetyEquipment::summarize($safetyEquipment, $lifeJacketQuantity),
            'is_active' => true,
        ]);

        $boat->syncPictures($request->boatPictureFiles());

        $operator = $request->operatorAttributes();
        $operator['picture_path'] = $request->file('operator_picture')
            ? $request->file('operator_picture')->store('operators', 'public')
            : null;

        $boat->operator()->create($operator);
        $boat->syncRentalDestinations($request->rentalDestinationIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Boat registered. Create the skipper’s app login next.']);

        return to_route('boats.index')->with('create_operator_boat_id', $boat->id);
    }

    public function storeOperatorAccount(Request $request, Boat $boat): RedirectResponse
    {
        abort_unless($boat->organization_id === $request->user()?->organization_id, 404);

        $boat->loadMissing('operator');

        if (! $boat->operator) {
            throw ValidationException::withMessages([
                'email' => 'Register the skipper on this boat first.',
            ]);
        }

        if ($boat->operator->user_id) {
            throw ValidationException::withMessages([
                'email' => 'This skipper already has an app account.',
            ]);
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $user = User::query()->create([
            'organization_id' => $request->user()?->organization_id,
            'name' => $boat->operator->full_name,
            'username' => User::uniqueUsernameFromEmail($data['email']),
            'email' => $data['email'],
            'role' => UserRole::Operator,
            'is_active' => true,
            'password' => $data['password'],
            'email_verified_at' => now(),
        ]);

        $boat->operator->update(['user_id' => $user->id]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Operator app account created. They can sign in on the mobile app with this email.',
        ]);

        return to_route('users.index', [
            'view' => $user->id,
            'role' => 'operator',
        ]);
    }

    public function update(StoreBoatRequest $request, Boat $boat): RedirectResponse
    {
        $boat->loadMissing('operator');
        $data = $request->validated();
        $safetyEquipment = $request->selectedSafetyEquipment();
        $lifeJacketQuantity = $request->lifeJacketQuantity();
        $services = $request->selectedServices();

        $boat->fill([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'capacity' => $data['capacity'],
            'type' => 'Pump Boat',
            'license_no' => $data['license_no'] ?? null,
            'registration_date' => $data['registration_date'] ?? null,
            'registration_status' => BoatRegistrationStatus::Registered,
            'status' => $data['status'],
            'service_availability' => $services,
            'service_type' => BoatService::legacyType($services),
            'safety_equipment' => $safetyEquipment,
            'life_jacket_quantity' => $lifeJacketQuantity,
            'safety_information' => SafetyEquipment::summarize($safetyEquipment, $lifeJacketQuantity),
        ]);

        $boat->save();
        $boat->syncPictures(
            $request->boatPictureFiles(),
            $data['removed_picture_ids'] ?? [],
        );

        $operator = $request->operatorAttributes();
        $operator['picture_path'] = $request->file('operator_picture')
            ? $request->file('operator_picture')->store('operators', 'public')
            : $boat->operator?->picture_path;

        $boat->operator()->updateOrCreate(
            ['boat_id' => $boat->id],
            $operator,
        );

        $boat->syncRentalDestinations($request->rentalDestinationIds());

        if (! $boat->fresh()?->status->isOutOfService()) {
            app(BoatStatusSyncService::class)->sync($boat->fresh());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Boat details updated.']);

        return to_route('boats.index');
    }

    public function updateStatus(Request $request, Boat $boat, BoatStatusSyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(BoatStatus::class)->only(BoatStatus::adminManaged())],
        ]);

        $operational = BoatStatus::from($validated['status']);

        if (
            ! $boat->registration_status->allowsAvailability()
            && $operational === BoatStatus::Available
        ) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Only a registered boat can be available.',
            ]);

            return back();
        }

        $sync->applyManagedStatus($boat, $operational);

        $message = match ($operational) {
            BoatStatus::Maintenance => 'Boat marked under maintenance. It cannot be assigned until restored to Available.',
            BoatStatus::Unavailable => 'Boat marked unavailable. It cannot be assigned until restored to Available.',
            default => 'Boat set to Available. On Duty and On Trip will follow today’s trips.',
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function archive(Boat $boat, RegularTripRotationService $rotation): RedirectResponse
    {
        if (! $boat->is_active) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'This boat is already archived.',
            ]);

            return back();
        }

        $boat->update([
            'is_active' => false,
            'status' => BoatStatus::Unavailable,
        ]);

        $rotation->dropBoatFromOpenLineup($boat);

        $boat->histories()->create([
            'category' => 'maintenance',
            'title' => 'Boat archived',
            'details' => 'Moved to Archived Boats. Cannot be assigned until restored.',
            'occurred_on' => now()->toDateString(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Boat archived. It left the waiting lineup and the Active list.',
        ]);

        return back();
    }

    public function restore(Boat $boat, BoatStatusSyncService $sync, RegularTripRotationService $rotation): RedirectResponse
    {
        if ($boat->is_active) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'This boat is already active.',
            ]);

            return back();
        }

        if (! $boat->registration_status->allowsAvailability()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Only a registered boat can be restored to Active.',
            ]);

            return back();
        }

        $boat->update([
            'is_active' => true,
            'status' => BoatStatus::Available,
        ]);

        $sync->sync($boat->fresh());
        $rotation->restoreBoatToOpenLineup($boat->fresh());

        $boat->histories()->create([
            'category' => 'maintenance',
            'title' => 'Boat restored to Active',
            'details' => 'Returned to the Active Boats list.',
            'occurred_on' => now()->toDateString(),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Boat restored to Active. It returns to the end of today’s waiting lineup.',
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Boat $boat): array
    {
        $histories = $boat->histories->map(fn (BoatHistory $history) => [
            'id' => $history->id,
            'category' => $history->category,
            'title' => $history->title,
            'details' => $history->details,
            'occurred_on' => $history->occurred_on->toDateString(),
            'occurred_on_label' => $history->occurred_on->format('M d, Y'),
        ]);

        $regularTrips = $boat->regularTrips ?? collect();
        $usedBookings = ($boat->bookings ?? collect())->reject(
            fn ($booking) => in_array(
                $booking->status,
                [BoatBookingStatus::Pending, BoatBookingStatus::Rejected, BoatBookingStatus::Cancelled],
                true,
            ),
        );

        $regularCount = $regularTrips->count();
        $rentalCount = $usedBookings->count();

        $lastRegular = $regularTrips->sortByDesc(fn ($trip) => $trip->trip_date?->toDateString() ?? '')->first();
        $lastRental = $usedBookings->sortByDesc(fn ($booking) => $booking->booking_date?->toDateString() ?? '')->first();

        $lastTripDate = collect([
            $lastRegular?->trip_date,
            $lastRental?->booking_date,
        ])->filter()->sortDesc()->first();

        $safetyChecked = $histories
            ->filter(fn (array $item) => $item['category'] === 'maintenance'
                || str_contains(strtolower($item['title']), 'safety'))
            ->sortByDesc('occurred_on')
            ->first();

        $selectedEquipment = $boat->safety_equipment ?? [];
        $safetyChecklist = collect(SafetyEquipment::cases())->map(function (SafetyEquipment $equipment) use ($boat, $selectedEquipment) {
            $checked = in_array($equipment->value, $selectedEquipment, true);
            $label = $equipment->label();

            if ($equipment === SafetyEquipment::LifeJackets && $checked && $boat->life_jacket_quantity) {
                $label .= ' — '.$boat->life_jacket_quantity;
            }

            return [
                'value' => $equipment->value,
                'label' => $label,
                'checked' => $checked,
            ];
        })->values()->all();

        $recentActivity = $histories
            ->sortByDesc('occurred_on')
            ->take(8)
            ->values()
            ->all();

        $operatorActive = $boat->operator?->user
            ? (bool) $boat->operator->user->is_active
            : ($boat->operator !== null);

        return [
            'id' => $boat->id,
            'name' => $boat->name,
            'code' => $boat->code,
            'capacity' => $boat->capacity,
            'type' => $boat->type,
            'license_no' => $boat->license_no,
            'registration_date' => $boat->registration_date?->toDateString(),
            'registration_date_label' => $boat->registration_date?->format('M d, Y'),
            'registered_in_safesakay' => $boat->created_at?->toDateString(),
            'registered_in_safesakay_label' => $boat->created_at?->format('M d, Y'),
            'status' => $boat->status->value,
            'status_label' => $boat->status->label(),
            'registration_status' => $boat->registration_status->value,
            'registration_status_label' => $boat->registration_status->label(),
            'service_type' => $boat->syncedServiceType(),
            'service_type_label' => $boat->serviceAvailabilityLabel(),
            'service_availability' => $boat->selectedServices(),
            'service_availability_label' => $boat->serviceAvailabilityLabel(),
            'rental_destination_ids' => $this->assignedRentalDestinations($boat)->pluck('id')->all(),
            'rental_destinations' => $this->assignedRentalDestinations($boat)
                ->map(fn (OrganizationRoute $route) => $this->rentalDestinationRecord($route))
                ->values()
                ->all(),
            'safety_information' => $boat->safety_information,
            'safety_equipment' => $selectedEquipment,
            'life_jacket_quantity' => $boat->life_jacket_quantity,
            'safety_checklist' => $safetyChecklist,
            'safety_last_checked_label' => $safetyChecked['occurred_on_label'] ?? null,
            'is_active' => (bool) $boat->is_active,
            'picture_url' => $boat->pictureUrl(),
            'pictures' => $boat->picturePayload(),
            'home_port' => $boat->organization?->homePort() ?? $boat->organization?->name ?? 'Home port',
            'usage' => [
                'regular_trips' => $regularCount,
                'exclusive_rentals' => $rentalCount,
                'total_trips' => $regularCount + $rentalCount,
                'last_trip_label' => $lastTripDate?->format('M d, Y'),
            ],
            'recent_activity' => $recentActivity,
            'operator' => $boat->operator ? [
                'user_id' => $boat->operator->user_id,
                'full_name' => $boat->operator->full_name,
                'first_name' => $boat->operator->first_name,
                'middle_name' => $boat->operator->middle_name,
                'last_name' => $boat->operator->last_name,
                'age' => $boat->operator->age,
                'gender' => $boat->operator->gender,
                'contact_number' => $boat->operator->contact_number,
                'address' => $boat->operator->address,
                'experience_years' => $boat->operator->experience_years,
                'emergency_contact_name' => $boat->operator->emergency_contact_name,
                'emergency_contact_number' => $boat->operator->emergency_contact_number,
                'picture_url' => $boat->operator->pictureUrl(),
                'admin_use_only' => $boat->operator->admin_use_only,
                'is_active' => $operatorActive,
                'status_label' => $operatorActive ? 'Active' : 'Inactive',
            ] : null,
            'history' => [
                'trips' => $histories->where('category', 'trip')->values(),
                'schedules' => $histories->where('category', 'schedule')->values(),
                'rentals' => $histories->where('category', 'rental')->values(),
            ],
            'operator_no_show_violations' => $boat->operator_id
                ? OperatorNoShowViolation::query()
                    ->where('operator_id', $boat->operator_id)
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get()
                    ->map(fn (OperatorNoShowViolation $row) => $row->toAdminRecord())
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @return list<array{id: int, destination: string, origin: string, name: string, rental_price: float|null, rental_price_label: string|null, location: string, picture_url: string|null}>
     */
    private function rentalDestinationOptions(): array
    {
        $location = auth()->user()?->organization?->rentalAreaLabel() ?? 'Siargao Islands';

        return OrganizationRoute::query()
            ->where('organization_id', auth()->user()?->organization_id)
            ->where('trip_type', OrganizationRouteType::ExclusiveBoatRental->value)
            ->where('is_active', true)
            ->orderBy('destination')
            ->orderBy('name')
            ->get()
            ->map(fn (OrganizationRoute $route) => $this->rentalDestinationRecord($route, $location))
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, OrganizationRoute>
     */
    private function assignedRentalDestinations(Boat $boat)
    {
        $destinations = $boat->relationLoaded('rentalDestinations')
            ? $boat->rentalDestinations
            : $boat->rentalDestinations()->get();

        return $destinations
            ->filter(fn (OrganizationRoute $route) => $route->isExclusiveBoatRental())
            ->sortBy('destination')
            ->values();
    }

    /**
     * @return array{id: int, destination: string, origin: string, name: string, rental_price: float|null, rental_price_label: string|null, location: string, picture_url: string|null}
     */
    private function rentalDestinationRecord(OrganizationRoute $route, ?string $location = null): array
    {
        return $route->toRentalDestination(
            $location ?? auth()->user()?->organization?->rentalAreaLabel() ?? 'Siargao Islands',
        );
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\RegularTripScheduleMode;
use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Enums\OrganizationRouteType;
use App\Models\OrganizationRoute;
use App\Models\RegularTrip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRegularTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $order = (int) $this->input('rotation_order');

        if ($order < 1) {
            $order = $this->nextRotationOrder();
        }

        $directions = $this->selectedRoute()?->toOption()['directions'] ?? [];
        $time = $this->normalizeTime($this->input('departure_time'));

        $this->merge([
            'rotation_order' => $order,
            'schedule_mode' => RegularTripScheduleMode::Rush->value,
            'direction' => $directions[0] ?? $this->input('direction'),
            'departure_time' => $time ?: RegularTrip::slotDepartureTime($order),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;
        $routeNames = $this->organizationRoutes()->pluck('name')->all();
        $directions = $this->selectedRoute()?->toOption()['directions'] ?? [];

        return [
            'boat_id' => [
                'required',
                'integer',
                Rule::exists('boats', 'id')
                    ->whereNull('deleted_at')
                    ->where('organization_id', $organizationId),
            ],
            'route' => ['required', 'string', 'max:120', Rule::in($routeNames)],
            'direction' => ['required', 'string', 'max:120', Rule::in($directions)],
            'trip_date' => ['required', 'date'],
            'departure_time' => ['required', 'date_format:H:i'],
            'schedule_mode' => ['required', Rule::enum(RegularTripScheduleMode::class)],
            'rotation_order' => ['required', 'integer', 'min:1', 'max:99'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'boat_id' => 'boat',
            'trip_date' => 'date',
            'departure_time' => 'departure time',
            'rotation_order' => 'rotation order',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $boat = Boat::query()->find($this->input('boat_id'));
                $currentId = $this->route('regularTrip')?->boat_id;

                if (
                    $boat
                    && ! $boat->isEligibleForRegularTrip()
                    && (int) $boat->id !== (int) $currentId
                ) {
                    $validator->errors()->add(
                        'boat_id',
                        'This boat is not available for regular trips.',
                    );
                }

                if (
                    $boat
                    && $this->input('trip_date')
                    && $boat->hasCommittedPrivateBookingOn((string) $this->input('trip_date'))
                ) {
                    $validator->errors()->add(
                        'boat_id',
                        'This boat has a private booking on that date, so it will not sail the regular trip.',
                    );
                }

                if ($boat && $this->boatAlreadyInLineup((int) $boat->id)) {
                    $validator->errors()->add(
                        'boat_id',
                        'This boat is already on today’s rotation. It stays on the list, so you do not need to add it again.',
                    );
                }

                if ($this->leaveOrderTaken()) {
                    $validator->errors()->add(
                        'rotation_order',
                        'Another boat already leaves in that order today.',
                    );
                }
            },
        ];
    }

    /**
     * @return Collection<int, OrganizationRoute>
     */
    private function organizationRoutes()
    {
        $organizationId = $this->user()?->organization_id;

        if (! $organizationId) {
            return collect();
        }

        return OrganizationRoute::query()
            ->where('organization_id', $organizationId)
            ->where('trip_type', OrganizationRouteType::RegularTrip->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    private function selectedRoute(): ?OrganizationRoute
    {
        return $this->organizationRoutes()
            ->firstWhere('name', $this->input('route'));
    }

    private function normalizeTime(mixed $time): ?string
    {
        if (! is_string($time) || $time === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return substr($time, 0, 5);
        }

        return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : null;
    }

    private function nextRotationOrder(): int
    {
        $max = $this->outboundTripsOnDate()
            ->max('rotation_order');

        return max(1, (int) $max + 1);
    }

    private function boatAlreadyInLineup(int $boatId): bool
    {
        $currentBoatId = $this->route('regularTrip')?->boat_id;

        if ((int) $currentBoatId === $boatId) {
            return false;
        }

        return $this->outboundTripsOnDate()->contains(function (RegularTrip $trip) use ($boatId) {
            return (int) $trip->boat_id === $boatId
                && in_array($trip->status, [
                    RegularTripStatus::Scheduled,
                    RegularTripStatus::Delayed,
                    RegularTripStatus::OnTrip,
                ], true);
        });
    }

    private function leaveOrderTaken(): bool
    {
        $order = (int) $this->input('rotation_order');

        if ($order < 1) {
            return false;
        }

        return $this->waitingOutboundOnDate()
            ->contains(fn (RegularTrip $trip) => (int) $trip->rotation_order === $order);
    }

    /**
     * @return Collection<int, RegularTrip>
     */
    private function outboundTripsOnDate(): Collection
    {
        $date = $this->input('trip_date');
        $route = $this->input('route');

        if (! $date || ! is_string($route) || $route === '') {
            return collect();
        }

        $currentId = $this->route('regularTrip')?->id;

        return RegularTrip::query()
            ->whereDate('trip_date', $date)
            ->where('route', $route)
            ->when($currentId, fn ($query) => $query->where('id', '!=', $currentId))
            ->get()
            ->filter(fn (RegularTrip $trip) => $trip->isOutbound())
            ->values();
    }

    /**
     * @return Collection<int, RegularTrip>
     */
    private function waitingOutboundOnDate(): Collection
    {
        return $this->outboundTripsOnDate()
            ->filter(fn (RegularTrip $trip) => $trip->status->isWaiting())
            ->values();
    }
}

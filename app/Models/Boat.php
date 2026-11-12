<?php

namespace App\Models;

use App\Enums\BoatBookingStatus;
use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Enums\OrganizationRouteType;
use App\Enums\RegularTripStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BoatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property string $code
 * @property int $capacity
 * @property string $type
 * @property string|null $license_no
 * @property Carbon|null $registration_date
 * @property BoatStatus $status
 * @property BoatRegistrationStatus $registration_status
 * @property string $service_type
 * @property array<int, string>|null $service_availability
 * @property string|null $safety_information
 * @property array<int, string>|null $safety_equipment
 * @property int|null $life_jacket_quantity
 * @property bool $is_active
 * @property string|null $picture_path
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'capacity',
    'type',
    'license_no',
    'registration_date',
    'status',
    'registration_status',
    'service_type',
    'service_availability',
    'safety_information',
    'safety_equipment',
    'life_jacket_quantity',
    'is_active',
    'picture_path',
])]
class Boat extends Model
{
    public const MAX_PICTURES = 5;

    /** @use HasFactory<BoatFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * Private-booking reference slots every 15 minutes across the whole day.
     * Passengers may also type any exact HH:MM time.
     *
     * @return list<string>
     */
    public static function bookingTimeSlots(): array
    {
        $slots = [];

        for ($hour = 0; $hour <= 23; $hour++) {
            foreach ([0, 15, 30, 45] as $minute) {
                $slots[] = sprintf('%02d:%02d', $hour, $minute);
            }
        }

        return $slots;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'registration_date' => 'date',
            'status' => BoatStatus::class,
            'registration_status' => BoatRegistrationStatus::class,
            'is_active' => 'boolean',
            'safety_equipment' => 'array',
            'service_availability' => 'array',
            'life_jacket_quantity' => 'integer',
        ];
    }

    /**
     * @return HasOne<Operator, $this>
     */
    public function operator(): HasOne
    {
        return $this->hasOne(Operator::class);
    }

    /**
     * @return HasMany<BoatHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(BoatHistory::class)->latest('occurred_on');
    }

    /**
     * @return HasMany<RegularTrip, $this>
     */
    public function regularTrips(): HasMany
    {
        return $this->hasMany(RegularTrip::class);
    }

    /**
     * @return HasMany<BoatBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(BoatBooking::class);
    }

    /**
     * @return BelongsToMany<OrganizationRoute, $this>
     */
    public function rentalDestinations(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationRoute::class, 'boat_organization_route')
            ->withTimestamps();
    }

    /**
     * @param  list<int|string>  $routeIds
     */
    public function syncRentalDestinations(array $routeIds): void
    {
        if (! $this->offers(BoatService::PrivateBooking)) {
            $this->rentalDestinations()->sync([]);

            return;
        }

        $ids = OrganizationRoute::query()
            ->where('organization_id', $this->organization_id)
            ->where('trip_type', OrganizationRouteType::ExclusiveBoatRental->value)
            ->where('is_active', true)
            ->whereIn('id', array_values(array_unique(array_map('intval', $routeIds))))
            ->pluck('id')
            ->all();

        $this->rentalDestinations()->sync($ids);
    }

    /**
     * @return list<string>
     */
    public function assignedRentalDestinationLabels(): array
    {
        $destinations = $this->relationLoaded('rentalDestinations')
            ? $this->rentalDestinations
            : $this->rentalDestinations()->get();

        return $destinations
            ->filter(fn (OrganizationRoute $route) => $route->isExclusiveBoatRental() && $route->is_active)
            ->sortBy('destination')
            ->pluck('destination')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function selectedServices(): array
    {
        return array_values(array_intersect(
            BoatService::values(),
            $this->service_availability ?? [],
        ));
    }

    public function offers(BoatService $service): bool
    {
        return in_array($service->value, $this->selectedServices(), true);
    }

    public function isRegistered(): bool
    {
        return $this->registration_status === BoatRegistrationStatus::Registered;
    }

    public function isOutOfService(): bool
    {
        return $this->status->isOutOfService();
    }

    public function isReadyForAssignment(): bool
    {
        return $this->is_active && $this->isRegistered() && ! $this->isOutOfService();
    }

    public function isEligibleForRegularTrip(): bool
    {
        return $this->isReadyForAssignment() && $this->offers(BoatService::RegularTrip);
    }

    public function isListedForPrivateBooking(): bool
    {
        return $this->is_active
            && $this->isRegistered()
            && ! $this->isOutOfService()
            && $this->offers(BoatService::PrivateBooking);
    }

    public function isEligibleForPrivateBooking(Carbon|string|null $onDate = null): bool
    {
        if (! $this->isListedForPrivateBooking()) {
            return false;
        }

        $date = $onDate ? Carbon::parse($onDate)->toDateString() : now()->toDateString();

        if ($this->status === BoatStatus::OnTrip && $date === now('Asia/Manila')->toDateString()) {
            return false;
        }

        return true;
    }

    public function hasCommittedPrivateBookingOn(Carbon|string $date, ?int $exceptBookingId = null): bool
    {
        return $this->hasRentalBlockingRegularTripOn($date, $exceptBookingId, committedOnly: true);
    }

    /**
     * A rental request or confirmed rental for this day keeps the boat off the regular trip schedule.
     */
    public function hasRentalBlockingRegularTripOn(Carbon|string $date, ?int $exceptBookingId = null, bool $committedOnly = false): bool
    {
        $statuses = $committedOnly
            ? [
                BoatBookingStatus::AwaitingPayment,
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
            ]
            : [
                BoatBookingStatus::Pending,
                BoatBookingStatus::AwaitingPayment,
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
                BoatBookingStatus::WeatherHold,
                BoatBookingStatus::ReschedulePending,
            ];

        return BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $this->id)
            ->whereDate('booking_date', Carbon::parse($date)->toDateString())
            ->whereIn('status', $statuses)
            ->when($exceptBookingId, fn ($query) => $query->whereKeyNot($exceptBookingId))
            ->exists();
    }

    public function hasPrivateBookingAt(Carbon|string $date, string $time, ?int $exceptBookingId = null): bool
    {
        $slot = Carbon::parse($time)->format('H:i');

        return BoatBooking::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $this->id)
            ->whereDate('booking_date', Carbon::parse($date)->toDateString())
            ->whereIn('status', [
                BoatBookingStatus::Pending,
                BoatBookingStatus::AwaitingPayment,
                BoatBookingStatus::Confirmed,
                BoatBookingStatus::Ongoing,
            ])
            ->when($exceptBookingId, fn ($query) => $query->whereKeyNot($exceptBookingId))
            ->get()
            ->contains(fn (BoatBooking $booking) => Carbon::parse($booking->start_time)->format('H:i') === $slot);
    }

    public function hasRegularTripAt(Carbon|string $date, ?string $time = null): bool
    {
        $trips = RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $this->id)
            ->whereDate('trip_date', Carbon::parse($date)->toDateString())
            ->whereIn('status', [
                RegularTripStatus::Scheduled,
                RegularTripStatus::OnTrip,
                RegularTripStatus::Delayed,
            ])
            ->get();

        if ($trips->isEmpty()) {
            return false;
        }

        if ($time === null) {
            return true;
        }

        $slot = Carbon::parse($time)->format('H:i');

        return $trips->contains(function (RegularTrip $trip) use ($slot) {
            $departure = Carbon::parse($trip->departure_time)->format('H:i');
            $return = Carbon::parse($trip->departure_time)->addHour()->format('H:i');

            return $slot === $departure || $slot === $return;
        });
    }

    public function hasRegularTripOnTrip(Carbon|string $date): bool
    {
        return RegularTrip::query()
            ->withoutGlobalScope('organization')
            ->where('boat_id', $this->id)
            ->whereDate('trip_date', Carbon::parse($date)->toDateString())
            ->where('status', RegularTripStatus::OnTrip)
            ->exists();
    }

    public function slotConflictMessage(Carbon|string $date, ?string $time = null, ?int $exceptBookingId = null): ?string
    {
        $onDate = Carbon::parse($date)->toDateString();
        $slot = $time ? Carbon::parse($time)->format('H:i') : null;

        if ($this->status === BoatStatus::OnTrip && $onDate === now('Asia/Manila')->toDateString()) {
            return 'This boat is already on a regular trip and cannot take a rental today.';
        }

        if ($this->hasRegularTripOnTrip($onDate)) {
            return 'This boat is already on a regular trip and cannot take a rental today.';
        }

        if (! $this->isEligibleForPrivateBooking($onDate)) {
            if ($this->status === BoatStatus::OnTrip && $onDate === now()->toDateString()) {
                return 'This boat is already on a trip and is unavailable at this time.';
            }

            if ($this->isOutOfService()) {
                return 'This boat is currently unavailable.';
            }

            return 'This boat is not available for private booking.';
        }

        if ($this->hasCommittedPrivateBookingOn($onDate, $exceptBookingId)) {
            return 'This boat already has a private booking on that date.';
        }

        if ($slot && $this->hasPrivateBookingAt($onDate, $slot, $exceptBookingId)) {
            return 'This boat already has a rental request at this time.';
        }

        return null;
    }

    public function isOpenForPrivateBookingOn(Carbon|string|null $date = null, ?int $exceptBookingId = null): bool
    {
        $onDate = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $this->isEligibleForPrivateBooking($onDate)
            && ! $this->hasCommittedPrivateBookingOn($onDate, $exceptBookingId);
    }

    public function serviceAvailabilityLabel(): string
    {
        return BoatService::summarize($this->selectedServices());
    }

    public function syncedServiceType(): string
    {
        return BoatService::legacyType($this->selectedServices());
    }

    /**
     * @param  Builder<Boat>  $query
     */
    public function scopeEligibleForRegularTrip(Builder $query): void
    {
        $query->where('registration_status', BoatRegistrationStatus::Registered)
            ->whereNotIn('status', [BoatStatus::Maintenance, BoatStatus::Unavailable])
            ->whereJsonContains('service_availability', BoatService::RegularTrip->value);
    }

    /**
     * @param  Builder<Boat>  $query
     */
    public function scopeEligibleForPrivateBooking(Builder $query): void
    {
        $query->where('registration_status', BoatRegistrationStatus::Registered)
            ->whereNotIn('status', [
                BoatStatus::Maintenance,
                BoatStatus::Unavailable,
            ])
            ->whereJsonContains('service_availability', BoatService::PrivateBooking->value);
    }

    /**
     * @return HasMany<BoatPicture, $this>
     */
    public function pictures(): HasMany
    {
        return $this->hasMany(BoatPicture::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<RentalFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(RentalFeedback::class);
    }

    public function pictureUrl(): ?string
    {
        if ($this->relationLoaded('pictures')) {
            $url = $this->pictures->first()?->url();

            if ($url) {
                return $url;
            }
        }

        return public_storage_url($this->picture_path);
    }

    /**
     * @return list<array{id: int, url: string}>
     */
    public function picturePayload(): array
    {
        $pictures = $this->relationLoaded('pictures')
            ? $this->pictures
            : $this->pictures()->get();

        $payload = $pictures
            ->map(fn (BoatPicture $picture) => [
                'id' => $picture->id,
                'url' => $picture->url(),
            ])
            ->filter(fn (array $picture) => filled($picture['url']))
            ->values()
            ->all();

        if ($payload === [] && ($url = public_storage_url($this->picture_path))) {
            return [['id' => 0, 'url' => $url]];
        }

        return $payload;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int|string>  $removedIds
     */
    public function syncPictures(array $files, array $removedIds = []): void
    {
        foreach ($removedIds as $pictureId) {
            $pictureId = (int) $pictureId;

            if ($pictureId < 1) {
                continue;
            }

            $picture = $this->pictures()->whereKey($pictureId)->first();

            if (! $picture) {
                continue;
            }

            Storage::disk('public')->delete($picture->path);
            $picture->delete();
        }

        $order = (int) $this->pictures()->max('sort_order');

        foreach ($files as $file) {
            $order++;

            $this->pictures()->create([
                'path' => $file->store('boats', 'public'),
                'sort_order' => $order,
            ]);
        }

        $this->unsetRelation('pictures');
        $this->picture_path = $this->pictures()->value('path');
        $this->save();
    }
}

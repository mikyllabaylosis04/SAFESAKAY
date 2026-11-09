<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\RegularTripStatus;
use App\Models\Boat;
use App\Models\RegularTrip;
use App\Models\User;
use App\Services\RegularTripRotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

trait ReturnsMobileUser
{
    /**
     * @return array<string, mixed>
     */
    protected function mobileUser(User $user): array
    {
        $user->load(['organization', 'operatorProfiles.boat']);
        $operator = $user->operatorProfiles->first();
        $pictureUrl = $operator?->boat
            ? $this->operatorPictureUrl($operator->boat)
            : $this->mediaUrl($operator?->picture_path);
        $pictureUrl ??= $this->mediaUrl($user->picture_path);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'address' => $operator?->address ?: $user->address,
            'contact_number' => $operator?->contact_number ?? $user->contact_number,
            'picture_url' => $pictureUrl,
            'emergency_contact_name' => $operator?->emergency_contact_name ?? $user->emergency_contact_name,
            'emergency_contact_number' => $operator?->emergency_contact_number ?? $user->emergency_contact_number,
            'role' => $user->role->canonical()->value,
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->displayName(),
                'address' => $user->organization->address,
                'contact_number' => $user->organization->contact_number,
                'status' => $user->organization->accessState(),
                'status_label' => $user->organization->accessStateLabel(),
                'allows_operations' => $user->organization->providesOperations(),
            ] : null,
            'operator' => $operator ? [
                'full_name' => $operator->full_name,
                'first_name' => $operator->first_name,
                'middle_name' => $operator->middle_name,
                'last_name' => $operator->last_name,
                'age' => $operator->age,
                'gender' => $operator->gender,
                'contact_number' => $operator->contact_number,
                'address' => $operator->address,
                'experience_years' => $operator->experience_years,
                'emergency_contact_name' => $operator->emergency_contact_name,
                'emergency_contact_number' => $operator->emergency_contact_number,
                'picture_url' => $pictureUrl,
                'boat_id' => $operator->boat?->id,
                'boat_name' => $operator->boat?->name,
                'boat_code' => $operator->boat?->code,
            ] : null,
            'rotation' => $this->operatorRotation($user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function operatorRotation(User $user): ?array
    {
        if (! $user->isMobileOperator()) {
            return null;
        }

        $user->loadMissing('operatorProfiles.boat');
        $boat = $user->operatorProfiles->first()?->boat;

        if ($user->organization_id && $user->organization?->providesOperations()) {
            app(RegularTripRotationService::class)->syncUpcoming($user->organization_id);
        }

        if (! $boat) {
            return [
                'boat' => null,
                'going' => null,
                'returning' => null,
            ];
        }

        $today = RegularTrip::query()
            ->with('boat')
            ->where('boat_id', $boat->id)
            ->whereDate('trip_date', now()->toDateString())
            ->orderBy('cycle_number')
            ->orderBy('id')
            ->get()
            ->reject(fn (RegularTrip $trip) => in_array($trip->status, [
                RegularTripStatus::Cancelled,
                RegularTripStatus::OffDuty,
            ], true));

        $activeGoing = $today->first(function (RegularTrip $trip) {
            return $trip->isOutbound() && in_array($trip->status, [
                RegularTripStatus::Scheduled,
                RegularTripStatus::OnTrip,
                RegularTripStatus::Delayed,
            ], true);
        });

        $activeReturn = $today->first(function (RegularTrip $trip) {
            return ! $trip->isOutbound() && $trip->status !== RegularTripStatus::Completed;
        });

        return [
            'boat' => [
                'id' => $boat->id,
                'name' => $boat->name,
                'code' => $boat->code,
            ],
            'going' => $activeGoing?->toMobileAssignment(),
            'returning' => $activeReturn?->toMobileAssignment(),
        ];
    }

    protected function authResponse(User $user, string $token): JsonResponse
    {
        return response()->json([
            'token' => $token,
            'user' => $this->mobileUser($user),
        ]);
    }

    protected function mediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $relative = str_starts_with($path, '/storage/')
            ? $path
            : '/storage/'.ltrim(str_replace('\\', '/', $path), '/');

        $forwardedHost = request()->headers->get('X-Forwarded-Host');
        $forwardedProto = request()->headers->get('X-Forwarded-Proto', request()->getScheme());

        if (is_string($forwardedHost) && $forwardedHost !== '') {
            return $forwardedProto.'://'.$forwardedHost.$relative;
        }

        return request()->getSchemeAndHttpHost().$relative;
    }

    protected function boatPictureUrl(Boat $boat, int $index = 0): ?string
    {
        $boat->loadMissing('pictures');
        $picture = $boat->pictures->values()->get($index);
        $path = $picture?->path ?? ($index === 0 ? $boat->picture_path : null);

        if (! $path) {
            return null;
        }

        $url = $this->mediaUrl($path);

        if (! $url) {
            return null;
        }

        return $this->appendPictureCacheBuster($url, $path, $picture?->id);
    }

    /**
     * @return list<string>
     */
    protected function boatPictureUrls(Boat $boat): array
    {
        $boat->loadMissing('pictures');
        $count = $boat->pictures->count();

        if ($count === 0) {
            $url = $this->boatPictureUrl($boat, 0);

            return $url ? [$url] : [];
        }

        return $boat->pictures
            ->values()
            ->map(fn ($picture, int $index) => $this->boatPictureUrl($boat, $index))
            ->filter()
            ->values()
            ->all();
    }

    protected function operatorPictureUrl(Boat $boat): ?string
    {
        $boat->loadMissing('operator');
        $path = $boat->operator?->picture_path;

        if (! $path) {
            return null;
        }

        $url = $this->mediaUrl($path);

        return $url ? $this->appendPictureCacheBuster($url, $path) : null;
    }

    protected function appendPictureCacheBuster(string $url, string $path, ?int $pictureId = null): string
    {
        $version = $this->pictureCacheVersion($path, $pictureId);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'v='.$version;
    }

    protected function pictureCacheVersion(string $path, ?int $pictureId = null): string
    {
        $mtime = 0;

        try {
            if (Storage::disk('public')->exists($path)) {
                $mtime = Storage::disk('public')->lastModified($path);
            }
        } catch (\Throwable) {
            //
        }

        return $mtime.'-'.($pictureId ?? 0);
    }
}

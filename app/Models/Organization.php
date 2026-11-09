<?php

namespace App\Models;

use App\Enums\OrganizationRouteType;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $address
 * @property string $contact_number
 * @property bool $is_active
 * @property string|null $inactive_kind
 * @property Carbon|null $approved_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspended_reason
 * @property int|null $suspended_by
 * @property Carbon|null $restore_requested_at
 * @property string|null $restore_request_reason
 * @property string|null $restore_request_details
 * @property int|null $restore_requested_by
 * @property Carbon|null $closure_requested_at
 * @property string|null $closure_reason
 * @property string|null $closure_details
 * @property int|null $closure_requested_by
 * @property Carbon|null $closure_rejected_at
 * @property string|null $closure_rejection_reason
 * @property Carbon|null $billing_locked_at
 * @property string|null $billing_lock_reason
 * @property string|null $gcash_account_name
 * @property string|null $gcash_qr_path
 */
#[Fillable([
    'tenant_id',
    'name',
    'slug',
    'address',
    'contact_number',
    'is_active',
    'inactive_kind',
    'approved_at',
    'suspended_at',
    'suspended_reason',
    'suspended_by',
    'restore_requested_at',
    'restore_request_reason',
    'restore_request_details',
    'restore_requested_by',
    'closure_requested_at',
    'closure_reason',
    'closure_details',
    'closure_requested_by',
    'closure_rejected_at',
    'closure_rejection_reason',
    'billing_locked_at',
    'billing_lock_reason',
    'gcash_account_name',
    'gcash_qr_path',
    'operator_no_show_grace_minutes',
    'passenger_no_show_grace_minutes',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public const DEFAULT_NO_SHOW_GRACE_MINUTES = 15;

    /** Minutes after departure before a passenger may report the boat/operator as a no-show. */
    public function operatorNoShowGraceMinutes(): int
    {
        return max(5, (int) ($this->operator_no_show_grace_minutes ?? self::DEFAULT_NO_SHOW_GRACE_MINUTES));
    }

    /** Minutes after departure before the operator may report the passenger as a no-show. */
    public function passengerNoShowGraceMinutes(): int
    {
        return max(5, (int) ($this->passenger_no_show_grace_minutes ?? self::DEFAULT_NO_SHOW_GRACE_MINUTES));
    }

    public static function noShowGraceFor(?int $organizationId, string $kind): int
    {
        $organization = $organizationId ? static::query()->find($organizationId) : null;

        if (! $organization) {
            return self::DEFAULT_NO_SHOW_GRACE_MINUTES;
        }

        return $kind === 'passenger'
            ? $organization->passengerNoShowGraceMinutes()
            : $organization->operatorNoShowGraceMinutes();
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            if (blank($organization->tenant_id)) {
                $organization->tenant_id = static::uniqueTenantId();
            }

            if (blank($organization->slug)) {
                $organization->slug = static::uniqueSlug($organization->name);
            }
        });
    }

    public static function uniqueTenantId(): string
    {
        do {
            $tenantId = 'SS-'.strtoupper(Str::random(8));
        } while (static::query()->where('tenant_id', $tenantId)->exists());

        return $tenantId;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $i = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    public static function normalizePlaceKey(string $value): string
    {
        $value = Str::of($value)
            ->lower()
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();

        return (string) preg_replace('/^barangay\s+/u', '', $value);
    }

    /**
     * One barangay address can belong to only one registered organization.
     *
     * @param  array{region?: mixed, province?: mixed, city?: mixed, barangay?: mixed}  $parts
     */
    public static function locationAlreadyRegistered(string $name, string $address, array $parts = []): bool
    {
        $nameKey = static::normalizePlaceKey($name);
        $addressKey = static::normalizePlaceKey($address);
        $barangayKey = static::normalizePlaceKey((string) ($parts['barangay'] ?? ''));
        $cityKey = static::normalizePlaceKey((string) ($parts['city'] ?? ''));
        $provinceKey = static::normalizePlaceKey((string) ($parts['province'] ?? ''));

        return static::query()
            ->where('slug', '!=', 'safesakay')
            ->get(['id', 'name', 'address'])
            ->contains(function (self $organization) use ($nameKey, $addressKey, $barangayKey, $cityKey, $provinceKey): bool {
                $existingName = static::normalizePlaceKey($organization->name);
                $existingAddress = static::normalizePlaceKey($organization->address);

                if ($nameKey !== '' && $existingName === $nameKey) {
                    return true;
                }

                if ($addressKey !== '' && $existingAddress === $addressKey) {
                    return true;
                }

                if ($barangayKey !== '' && $cityKey !== '') {
                    return str_contains($existingAddress, $barangayKey)
                        && str_contains($existingAddress, $cityKey);
                }

                if ($barangayKey !== '' && $provinceKey !== '') {
                    return str_contains($existingAddress, $barangayKey)
                        && str_contains($existingAddress, $provinceKey);
                }

                return $barangayKey !== '' && (
                    $existingName === $barangayKey
                    || str_contains($existingAddress, $barangayKey)
                );
            });
    }

    /**
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    public function scopeTenants(Builder $query): Builder
    {
        return $query->where('slug', '!=', 'safesakay');
    }

    public function isPlatformRecord(): bool
    {
        return $this->slug === 'safesakay';
    }

    /**
     * @return list<string>
     */
    public static function closureReasons(): array
    {
        return [
            'No longer operating pump boats',
            'Switching to another management system',
            'Temporary suspension of barangay operations',
            'Financial or budget concerns',
            'SafeSakay is no longer needed',
            'Other reason',
        ];
    }

    /**
     * @return list<string>
     */
    public static function reactivationReasons(): array
    {
        return [
            'Resuming pump boat operations',
            'Ready to use SafeSakay again',
            'Organization operations have resumed',
            'Financial or subscription concerns resolved',
            'Returning from another management system',
            'Other reason',
        ];
    }

    /**
     * @return list<string>
     */
    public static function suspensionReasons(): array
    {
        return [
            'Unpaid subscription',
        ];
    }

    public function isPending(): bool
    {
        return $this->approved_at === null && $this->suspended_at === null;
    }

    public function isAccessPaused(): bool
    {
        return $this->is_active === false && ! $this->isPending() && ! $this->isArchived();
    }

    public function isArchived(): bool
    {
        return $this->is_active === false && $this->inactive_kind === 'archived';
    }

    public function isDeactivated(): bool
    {
        return $this->isAccessPaused() && $this->inactive_kind === 'deactivated';
    }

    public function isSuspended(): bool
    {
        return $this->isAccessPaused() && $this->inactive_kind !== 'deactivated';
    }

    public function allowsAccess(): bool
    {
        return $this->is_active === true;
    }

    public function providesOperations(): bool
    {
        return $this->accessState() === 'active';
    }

    /**
     * @return array<string, mixed>
     */
    public function inactiveOperationsPayload(): array
    {
        return [
            'message' => 'This organization is no longer active on SafeSakay.',
            'code' => 'ORGANIZATION_INACTIVE',
            'organization_status' => $this->accessState(),
            'organization_status_label' => $this->accessStateLabel(),
            'organization_name' => $this->name,
        ];
    }

    public function abortUnlessOperational(): void
    {
        if ($this->providesOperations()) {
            return;
        }

        abort(response()->json($this->inactiveOperationsPayload(), 403));
    }

    public static function abortInactive(?self $organization): void
    {
        if ($organization) {
            $organization->abortUnlessOperational();

            return;
        }

        abort(response()->json([
            'message' => 'This organization is no longer active on SafeSakay.',
            'code' => 'ORGANIZATION_INACTIVE',
            'organization_status' => 'archived',
            'organization_status_label' => 'Archived',
            'organization_name' => null,
        ], 403));
    }

    public function accessState(): string
    {
        if ($this->isPending()) {
            return 'pending';
        }

        if ($this->isArchived()) {
            return 'archived';
        }

        if ($this->isDeactivated()) {
            return 'inactive';
        }

        if ($this->isAccessPaused() || $this->is_active === false) {
            return 'suspended';
        }

        return 'active';
    }

    public function accessStateLabel(): string
    {
        return match ($this->accessState()) {
            'pending' => 'Pending approval',
            'inactive' => 'Deactivated',
            'suspended' => 'Suspended',
            'archived' => 'Archived',
            default => 'Active',
        };
    }

    public function accessDenialMessage(): string
    {
        if ($this->isPending()) {
            return 'This organization is waiting for Super Admin approval.';
        }

        if ($this->isArchived()) {
            return 'This organization is archived. Sign in to request reactivation from Super Admin.';
        }

        if ($this->isDeactivated()) {
            return 'Your organization\'s access has been temporarily suspended by the SafeSakay Super Admin.';
        }

        return 'This organization is suspended. Use Request restore below so Super Admin can review your restore request.';
    }

    public function hasPendingRestoreRequest(): bool
    {
        return $this->restore_requested_at !== null && ($this->isAccessPaused() || $this->isArchived());
    }

    public function hasPendingClosureRequest(): bool
    {
        return $this->closure_requested_at !== null && $this->is_active === true;
    }

    public function closureStatusLabel(): string
    {
        if ($this->isArchived()) {
            return 'Archived';
        }

        if ($this->hasPendingClosureRequest()) {
            return 'Pending Super Admin review';
        }

        if ($this->closure_rejected_at) {
            return 'Rejected';
        }

        return 'No request';
    }

    public function suspend(User $actor, ?string $reason = null): void
    {
        $this->pauseAccess($actor, $reason, 'suspended');
    }

    public function deactivate(User $actor, ?string $reason = null): void
    {
        $this->pauseAccess($actor, $reason, 'deactivated');
    }

    public function pauseAccess(User $actor, ?string $reason, string $kind): void
    {
        $this->update([
            'is_active' => false,
            'inactive_kind' => $kind,
            'suspended_at' => now(),
            'suspended_reason' => $reason ? trim($reason) : null,
            'suspended_by' => $actor->id,
            'restore_requested_at' => null,
            'restore_request_reason' => null,
            'restore_request_details' => null,
            'restore_requested_by' => null,
            'closure_requested_at' => null,
            'closure_reason' => null,
            'closure_details' => null,
            'closure_requested_by' => null,
        ]);
    }

    public function restoreAccess(): void
    {
        $this->update([
            'is_active' => true,
            'inactive_kind' => null,
            'suspended_at' => null,
            'suspended_reason' => null,
            'suspended_by' => null,
            'restore_requested_at' => null,
            'restore_request_reason' => null,
            'restore_request_details' => null,
            'restore_requested_by' => null,
            'closure_requested_at' => null,
            'closure_reason' => null,
            'closure_details' => null,
            'closure_requested_by' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'restore_requested_at' => 'datetime',
            'closure_requested_at' => 'datetime',
            'closure_rejected_at' => 'datetime',
            'billing_locked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function restoreRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restore_requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closureRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closure_requested_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Boat, $this>
     */
    public function boats(): HasMany
    {
        return $this->hasMany(Boat::class);
    }

    /**
     * @return HasMany<OrganizationRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(OrganizationRoute::class)->orderBy('sort_order');
    }

    /**
     * Demo / test helper only. New organizations start with no routes; admins add them in Route Management.
     */
    public function seedDefaultRoute(): OrganizationRoute
    {
        $origin = 'Lakandula';
        $destination = 'Placer';

        if (! str_contains(mb_strtolower($this->name.' '.$this->address), 'lakandula')) {
            $origin = trim((string) preg_replace('/^barangay\s+/i', '', $this->name)) ?: $this->name;
        }

        return $this->routes()->firstOrCreate(
            ['name' => OrganizationRoute::makeName($origin, $destination)],
            [
                'origin' => $origin,
                'destination' => $destination,
                'trip_type' => OrganizationRouteType::RegularTrip->value,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );
    }

    /**
     * Human-readable organization name for UI (mobile/web), regardless of registration casing.
     */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        if ($name === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    public function homePort(): string
    {
        $haystack = mb_strtolower($this->name.' '.$this->address);

        if (str_contains($haystack, 'lakandula')) {
            return 'Lakandula';
        }

        $fromName = trim((string) preg_replace('/^barangay\s+/i', '', $this->name));

        if ($fromName !== '') {
            return $fromName;
        }

        $fromAddress = trim((string) Str::before((string) $this->address, ','));
        $fromAddress = trim((string) preg_replace('/^barangay\s+/i', '', $fromAddress));

        if ($fromAddress !== '') {
            return $fromAddress;
        }

        $origin = $this->relationLoaded('routes')
            ? $this->routes->first()?->origin
            : $this->routes()->orderBy('sort_order')->value('origin');

        if (is_string($origin) && $origin !== '') {
            return $origin;
        }

        return 'Home port';
    }

    public function exclusiveRentalOrigin(): string
    {
        $name = trim($this->name);

        if ($name !== '') {
            return preg_match('/^barangay\b/i', $name) === 1
                ? $name
                : 'Barangay '.$name;
        }

        $fromAddress = trim((string) Str::before($this->address, ','));
        $fromAddress = trim((string) preg_replace('/^barangay\s+/i', '', $fromAddress));

        if ($fromAddress !== '') {
            return 'Barangay '.$fromAddress;
        }

        return $this->name !== '' ? $this->name : 'Barangay';
    }

    public function rentalAreaLabel(): string
    {
        $haystack = mb_strtolower($this->name.' '.$this->address);

        if (str_contains($haystack, 'siargao')) {
            return 'Siargao Islands';
        }

        $fromAddress = trim((string) Str::afterLast($this->address, ','));

        return $fromAddress !== '' ? $fromAddress : ($this->name ?: 'Siargao Islands');
    }

    public function gcashQrUrl(): ?string
    {
        return public_storage_url($this->gcash_qr_path);
    }

    /**
     * @return array{account_name: string|null, qr_path: string|null, qr_url: string|null}
     */
    public function gcashSettings(): array
    {
        return [
            'account_name' => $this->gcash_account_name,
            'qr_path' => $this->gcash_qr_path,
            'qr_url' => $this->gcashQrUrl(),
        ];
    }

    public static function defaultServiceArea(): ?self
    {
        $query = static::query()->tenants()->where('is_active', true);

        return (clone $query)
            ->where(function ($match) {
                $match->whereRaw('lower(name) like ?', ['%lakandula%'])
                    ->orWhereRaw('lower(address) like ?', ['%lakandula%']);
            })
            ->orderBy('id')
            ->first()
            ?? $query->orderBy('id')->first();
    }
}

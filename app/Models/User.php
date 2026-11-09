<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property string|null $address
 * @property UserRole $role
 * @property bool $is_active
 * @property string|null $deactivated_reason
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'name', 'username', 'email', 'address', 'contact_number', 'picture_path', 'emergency_contact_name', 'emergency_contact_number', 'password', 'role', 'is_active', 'deactivated_reason', 'deactivated_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<BoatBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(BoatBooking::class);
    }

    /**
     * @return HasMany<PushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * @return HasMany<Operator, $this>
     */
    public function operatorProfiles(): HasMany
    {
        return $this->hasMany(Operator::class);
    }

    public function operatorSuspensionDenialMessage(): ?string
    {
        $profile = $this->relationLoaded('operatorProfiles')
            ? $this->operatorProfiles->first()
            : $this->operatorProfiles()->first();

        if (! $profile instanceof Operator || ! $profile->isAccountSuspended()) {
            return null;
        }

        return $profile->suspensionAccessMessage();
    }

    public function isMobileOperator(): bool
    {
        return $this->role->isMobileOperator();
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Passenger;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Administrator;
    }

    public function homeRouteName(): string
    {
        if ($this->isSuperAdmin()) {
            return 'platform.dashboard';
        }

        $this->loadMissing('organization');

        if ($this->organization?->isArchived()) {
            return 'organization.archived';
        }

        return 'dashboard';
    }

    public function organizationAllowsAccess(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $this->loadMissing('organization');

        if (! $this->organization) {
            return true;
        }

        return $this->organization->allowsAccess();
    }

    public function organizationBlocksLogin(): bool
    {
        if ($this->isSuperAdmin()) {
            return false;
        }

        $this->loadMissing('organization');

        if (! $this->organization) {
            return false;
        }

        return $this->organization->isPending() || $this->organization->isAccessPaused();
    }

    public function organizationAccessDenialMessage(): string
    {
        $this->loadMissing('organization');

        return $this->organization?->accessDenialMessage()
            ?? 'This organization cannot sign in.';
    }

    public function organizationIsSuspended(): bool
    {
        return ! $this->organizationAllowsAccess();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeForManagement(Builder $query, ?int $organizationId): Builder
    {
        if (! $organizationId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $visible) use ($organizationId): void {
            $visible
                ->where(function (Builder $staff) use ($organizationId): void {
                    $staff->where('organization_id', $organizationId)
                        ->whereIn('role', [
                            UserRole::Administrator,
                            UserRole::User,
                            UserRole::Operator,
                            UserRole::Staff,
                        ]);
                })
                ->orWhere(function (Builder $customers) use ($organizationId): void {
                    $customers->where('role', UserRole::Passenger)
                        ->where(function (Builder $linked) use ($organizationId): void {
                            $linked->where('organization_id', $organizationId)
                                ->orWhereHas('bookings', function (Builder $bookings) use ($organizationId): void {
                                    $bookings->withoutGlobalScope('organization')
                                        ->where('organization_id', $organizationId);
                                });
                        });
                });
        });
    }

    public function attachToOrganization(?int $organizationId): void
    {
        if ($this->isSuperAdmin() || ! $organizationId || $this->organization_id === $organizationId) {
            return;
        }

        $this->update(['organization_id' => $organizationId]);
    }

    public static function uniqueUsernameFromEmail(string $email, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::before($email, '@'), '_');
        $base = Str::lower(preg_replace('/[^a-z0-9_]/', '', $base) ?? '');

        if (strlen($base) < 3) {
            $base = 'user'.substr(md5($email), 0, 6);
        }

        $base = substr($base, 0, 24);
        $username = $base;
        $suffix = 1;

        while (static::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('username', $username)
            ->exists()) {
            $username = substr($base, 0, 24 - strlen((string) $suffix)).$suffix;
            $suffix++;
        }

        return $username;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }
}

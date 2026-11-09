<?php

namespace App\Models;

use App\Enums\OperatorGcashStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $boat_id
 * @property int|null $user_id
 * @property string $full_name
 * @property string|null $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property int|null $age
 * @property string|null $gender
 * @property string|null $contact_number
 * @property string|null $address
 * @property int|null $experience_years
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_number
 * @property string|null $picture_path
 * @property bool $admin_use_only
 * @property \Illuminate\Support\Carbon|null $exclusive_rental_restricted_at
 * @property int|null $exclusive_rental_restricted_by
 * @property string|null $exclusive_rental_restriction_reason
 * @property \Illuminate\Support\Carbon|null $account_suspended_at
 * @property \Illuminate\Support\Carbon|null $account_suspension_ends_at
 * @property string|null $account_suspension_reason
 */
#[Fillable([
    'boat_id',
    'user_id',
    'full_name',
    'first_name',
    'middle_name',
    'last_name',
    'age',
    'gender',
    'contact_number',
    'address',
    'experience_years',
    'emergency_contact_name',
    'emergency_contact_number',
    'picture_path',
    'admin_use_only',
    'exclusive_rental_restricted_at',
    'exclusive_rental_restricted_by',
    'exclusive_rental_restriction_reason',
    'account_suspended_at',
    'account_suspension_ends_at',
    'account_suspension_reason',
])]
class Operator extends Model
{
    public const MIN_AGE = 18;

    public const MAX_AGE = 80;

    public const NAME_REGEX = '/^(?=.{2,60}$)\p{L}+(?:[ \'\-]\p{L}+)*$/u';

    public const MOBILE_REGEX = '/^09\d{9}$/';

    /**
     * @return list<string>
     */
    public const GENDERS = [
        'Male',
        'Female',
        'Prefer not to say',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'experience_years' => 'integer',
            'admin_use_only' => 'boolean',
            'exclusive_rental_restricted_at' => 'datetime',
            'account_suspended_at' => 'datetime',
            'account_suspension_ends_at' => 'datetime',
        ];
    }

    public function isExclusiveRentalRestricted(): bool
    {
        return $this->exclusive_rental_restricted_at !== null;
    }

    public function refreshAccountSuspension(): void
    {
        if ($this->account_suspension_ends_at === null || $this->account_suspension_ends_at->isFuture()) {
            return;
        }

        if ($this->exclusive_rental_restricted_at === null) {
            return;
        }

        $this->forceFill([
            'exclusive_rental_restricted_at' => null,
            'exclusive_rental_restricted_by' => null,
            'exclusive_rental_restriction_reason' => null,
        ])->save();
    }

    public function isAccountSuspended(): bool
    {
        $this->refreshAccountSuspension();

        return $this->account_suspension_ends_at !== null
            && $this->account_suspension_ends_at->isFuture();
    }

    public function suspendAccount(string $reason, int $days = 7): void
    {
        $start = now();

        $this->update([
            'account_suspended_at' => $start,
            'account_suspension_ends_at' => $start->copy()->addDays($days),
            'account_suspension_reason' => $reason,
        ]);

        $this->user?->tokens()->delete();
    }

    public function suspensionRemainingLabel(): ?string
    {
        if (! $this->isAccountSuspended() || $this->account_suspension_ends_at === null) {
            return null;
        }

        $days = max(1, (int) ceil(now()->diffInHours($this->account_suspension_ends_at, false) / 24));

        return 'You will regain access after '.$days.' '.($days === 1 ? 'day' : 'days').'.';
    }

    public function suspensionAccessMessage(): string
    {
        $until = $this->account_suspension_ends_at?->timezone('Asia/Manila')->format('M j, Y') ?? 'the end of the suspension';

        return 'This operator account is suspended until '.$until.'. '.($this->suspensionRemainingLabel() ?? '');
    }

    /**
     * @return HasMany<OperatorNoShowViolation, $this>
     */
    public function violations(): HasMany
    {
        return $this->hasMany(OperatorNoShowViolation::class);
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<int>
     */
    public static function managedBoatIdsForUser(User $user): array
    {
        return self::query()
            ->where('user_id', $user->id)
            ->pluck('boat_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public static function userManagesBoat(User $user, int|string|null $boatId): bool
    {
        if (! $user->isMobileOperator() || $boatId === null || $boatId === '') {
            return false;
        }

        return in_array((int) $boatId, self::managedBoatIdsForUser($user), true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function linkedUsersForBoat(int $boatId)
    {
        $userIds = self::query()
            ->where('boat_id', $boatId)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return User::query()->whereIn('id', $userIds)->get();
    }

    /**
     * @return HasMany<OperatorGcashAccount, $this>
     */
    public function gcashAccounts(): HasMany
    {
        return $this->hasMany(OperatorGcashAccount::class);
    }

    public function approvedGcashAccount(): ?OperatorGcashAccount
    {
        return $this->gcashAccounts
            ->first(fn (OperatorGcashAccount $account) => $account->status === OperatorGcashStatus::Approved)
            ?? $this->gcashAccounts()
                ->where('status', OperatorGcashStatus::Approved)
                ->latest('reviewed_at')
                ->latest('id')
                ->first();
    }

    public function pendingGcashAccount(): ?OperatorGcashAccount
    {
        return $this->gcashAccounts
            ->first(fn (OperatorGcashAccount $account) => $account->status === OperatorGcashStatus::PendingApproval)
            ?? $this->gcashAccounts()
                ->where('status', OperatorGcashStatus::PendingApproval)
                ->latest('submitted_at')
                ->latest('id')
                ->first();
    }

    public function latestRejectedGcashAccount(): ?OperatorGcashAccount
    {
        return $this->gcashAccounts()
            ->where('status', OperatorGcashStatus::Rejected)
            ->latest('reviewed_at')
            ->latest('id')
            ->first();
    }

    public function pictureUrl(): ?string
    {
        return public_storage_url($this->picture_path);
    }

    public static function sanitizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    public static function sanitizeMobile(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    public static function composeFullName(?string $first, ?string $middle, ?string $last): string
    {
        return trim(implode(' ', array_filter([
            self::sanitizeName($first),
            self::sanitizeName($middle),
            self::sanitizeName($last),
        ])));
    }

    /**
     * @return array{first: string, middle: string, last: string}
     */
    public static function splitFullName(?string $fullName): array
    {
        $parts = preg_split('/\s+/', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || $parts === []) {
            return ['first' => '', 'middle' => '', 'last' => ''];
        }

        $first = array_shift($parts) ?? '';
        $last = $parts === [] ? '' : (string) array_pop($parts);
        $middle = implode(' ', $parts);

        return [
            'first' => $first,
            'middle' => $middle,
            'last' => $last,
        ];
    }
}

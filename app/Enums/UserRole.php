<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';
    case Operator = 'operator';
    case Administrator = 'administrator';
    case SuperAdmin = 'super_admin';
    case Staff = 'staff';
    case Passenger = 'passenger';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Operator, self::Staff => 'Operator',
            self::Administrator => 'Admin',
            self::SuperAdmin => 'Super Admin',
            self::Passenger => 'Customer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::User => 'Staff access for viewing schedules and bookings',
            self::Operator, self::Staff => 'Boat operator. Uses the mobile app to manage bookings and boats.',
            self::Administrator => 'Full access to the admin system',
            self::SuperAdmin => 'Platform owner. Manages organizations, not barangay boat operations.',
            self::Passenger => 'Customer account for the SafeSakay mobile app. Appears here after they register, log in, or book a boat.',
        };
    }

    public function canonical(): self
    {
        return match ($this) {
            self::Staff => self::Operator,
            default => $this,
        };
    }

    public function isMobileOperator(): bool
    {
        return $this->canonical() === self::Operator;
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function canAccessAdminWeb(): bool
    {
        return $this->isSuperAdmin()
            || in_array($this->canonical(), [self::Administrator, self::User], true);
    }

    public function canAccessBarangayWeb(): bool
    {
        return in_array($this->canonical(), [self::Administrator, self::User], true);
    }

    public function adminWebDenialMessage(): string
    {
        return $this->canonical() === self::Operator
            ? 'Operator accounts can only sign in through the SafeSakay mobile app.'
            : 'Passenger accounts can only sign in through the SafeSakay mobile app.';
    }

    /**
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Administrator, self::User, self::Operator];
    }
}

<?php

namespace App\Models;

use App\Enums\OrganizationRouteType;
use App\Enums\PassengerFareType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $origin
 * @property string $destination
 * @property string $trip_type
 * @property string|null $rental_price
 * @property string|null $regular_fare
 * @property string|null $student_discount_percent
 * @property string|null $senior_discount_percent
 * @property string|null $down_payment_percent
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable([
    'organization_id',
    'name',
    'origin',
    'destination',
    'trip_type',
    'rental_price',
    'regular_fare',
    'student_discount_percent',
    'senior_discount_percent',
    'down_payment_percent',
    'is_active',
    'sort_order',
])]
class OrganizationRoute extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rental_price' => 'decimal:2',
            'regular_fare' => 'decimal:2',
            'student_discount_percent' => 'decimal:2',
            'senior_discount_percent' => 'decimal:2',
            'down_payment_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function makeName(string $origin, string $destination): string
    {
        return trim($origin).' ↔ '.trim($destination);
    }

    public function routeType(): OrganizationRouteType
    {
        return OrganizationRouteType::fromStored($this->trip_type);
    }

    public function isRegularTrip(): bool
    {
        return $this->routeType() === OrganizationRouteType::RegularTrip;
    }

    public function isExclusiveBoatRental(): bool
    {
        return $this->routeType() === OrganizationRouteType::ExclusiveBoatRental;
    }

    public function tripTypeLabel(): string
    {
        return $this->routeType()->label();
    }

    public const FARE_DISCOUNT_PERCENT = 20;

    public function regularFareAmount(): ?float
    {
        if (! $this->isRegularTrip() || $this->regular_fare === null) {
            return null;
        }

        return round((float) $this->regular_fare, 2);
    }

    public function studentDiscountPercent(): float
    {
        if ($this->student_discount_percent === null) {
            return self::FARE_DISCOUNT_PERCENT;
        }

        return max(0, min(100, (float) $this->student_discount_percent));
    }

    public function seniorDiscountPercent(): float
    {
        if ($this->senior_discount_percent === null) {
            return self::FARE_DISCOUNT_PERCENT;
        }

        return max(0, min(100, (float) $this->senior_discount_percent));
    }

    public function fareAmountFor(PassengerFareType|string $type): ?float
    {
        $regular = $this->regularFareAmount();

        if ($regular === null) {
            return null;
        }

        $kind = PassengerFareType::tryFromValue($type);
        $percent = match ($kind) {
            PassengerFareType::Student => $this->studentDiscountPercent(),
            PassengerFareType::Senior => $this->seniorDiscountPercent(),
            default => 0.0,
        };

        return round($regular * ((100 - $percent) / 100), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function fareTable(): array
    {
        $regular = $this->regularFareAmount();
        $student = $this->fareAmountFor(PassengerFareType::Student);
        $senior = $this->fareAmountFor(PassengerFareType::Senior);

        return [
            'regular_fare' => $regular,
            'regular_fare_label' => $regular === null ? null : self::formatPeso($regular),
            'student_fare' => $student,
            'student_fare_label' => $student === null ? null : self::formatPeso($student),
            'senior_fare' => $senior,
            'senior_fare_label' => $senior === null ? null : self::formatPeso($senior),
            'student_discount_percent' => $this->studentDiscountPercent(),
            'senior_discount_percent' => $this->seniorDiscountPercent(),
            'discount_percent' => $this->studentDiscountPercent(),
        ];
    }

    public function matchesTrip(string $route, string $direction): bool
    {
        $haystack = mb_strtolower($route.' '.$direction);
        $origin = mb_strtolower(trim($this->origin));
        $destination = mb_strtolower(trim($this->destination));

        if ($origin === '' || $destination === '') {
            return false;
        }

        return str_contains($haystack, $origin) && str_contains($haystack, $destination);
    }

    public function rentalPriceAmount(): ?float
    {
        if (! $this->isExclusiveBoatRental() || $this->rental_price === null) {
            return null;
        }

        return round((float) $this->rental_price, 2);
    }

    public function rentalPriceLabel(): ?string
    {
        $amount = $this->rentalPriceAmount();

        if ($amount === null) {
            return null;
        }

        return self::formatPeso((string) number_format($amount, 2, '.', ''));
    }

    public function downPaymentPercentAmount(): ?float
    {
        if (! $this->isExclusiveBoatRental() || $this->down_payment_percent === null) {
            return null;
        }

        return round((float) $this->down_payment_percent, 2);
    }

    public function downPaymentPercentLabel(): ?string
    {
        $percent = $this->downPaymentPercentAmount();

        if ($percent === null) {
            return null;
        }

        return self::formatPercent($percent);
    }

    /**
     * @return array{
     *     rental_price: float,
     *     rental_price_label: string,
     *     down_payment_percent: float,
     *     down_payment_percent_label: string,
     *     down_payment_amount: float,
     *     down_payment_amount_label: string,
     *     remaining_balance: float,
     *     remaining_balance_label: string
     * }|null
     */
    public function priceBreakdown(): ?array
    {
        $price = $this->rentalPriceAmount();

        if ($price === null) {
            return null;
        }

        $percent = $this->downPaymentPercentAmount() ?? 0.0;
        [$down, $remaining] = self::calculateDownPayment($price, $percent);

        return [
            'rental_price' => $price,
            'rental_price_label' => self::formatPeso($price),
            'down_payment_percent' => $percent,
            'down_payment_percent_label' => self::formatPercent($percent),
            'down_payment_amount' => (float) $down,
            'down_payment_amount_label' => self::formatPeso($down),
            'remaining_balance' => (float) $remaining,
            'remaining_balance_label' => self::formatPeso($remaining),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function calculateDownPayment(float $rentalPrice, float $percent): array
    {
        $price = number_format(max(0, $rentalPrice), 2, '.', '');
        $rate = number_format(max(0, min(100, $percent)), 2, '.', '');

        if (function_exists('bcmul') && function_exists('bcdiv') && function_exists('bcsub')) {
            $down = bcmul($price, bcdiv($rate, '100', 8), 2);
            $remaining = bcsub($price, $down, 2);
        } else {
            $priceCents = (int) round(((float) $price) * 100);
            $downCents = (int) round($priceCents * ((float) $rate) / 100);
            $down = number_format($downCents / 100, 2, '.', '');
            $remaining = number_format(($priceCents - $downCents) / 100, 2, '.', '');
        }

        return [$down, $remaining];
    }

    public static function formatPeso(float|string $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }

    public static function formatPercent(float $percent): string
    {
        $normalized = number_format($percent, 2, '.', '');

        if (fmod($percent, 1.0) === 0.0) {
            return ((int) $percent).'%';
        }

        return rtrim(rtrim($normalized, '0'), '.').'%';
    }

    /**
     * @return array<string, mixed>
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'trip_type' => $this->routeType()->value,
            'directions' => [
                $this->origin.' → '.$this->destination,
                $this->destination.' → '.$this->origin,
            ],
            ...$this->fareTable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'trip_type' => $this->routeType()->value,
            'trip_type_label' => $this->tripTypeLabel(),
            'is_active' => $this->is_active,
            'status_label' => $this->is_active ? 'Active' : 'Inactive',
            'rental_price' => $this->rentalPriceAmount(),
            'rental_price_label' => $this->rentalPriceLabel(),
            'down_payment_percent' => $this->downPaymentPercentAmount(),
            'down_payment_percent_label' => $this->downPaymentPercentLabel(),
            ...$this->fareTable(),
            'student_discount_percent' => $this->student_discount_percent !== null
                ? (float) $this->student_discount_percent
                : null,
            'senior_discount_percent' => $this->senior_discount_percent !== null
                ? (float) $this->senior_discount_percent
                : null,
        ];
    }

    /**
     * @return array{id: int, destination: string, origin: string, name: string, rental_price: float|null, rental_price_label: string|null, location: string, picture_url: string|null}
     */
    public function toRentalDestination(string $location = 'Siargao Islands', ?string $pictureUrl = null): array
    {
        $breakdown = $this->priceBreakdown();

        return [
            'id' => $this->id,
            'destination' => $this->destination,
            'origin' => $this->origin,
            'name' => $this->name,
            'rental_price' => $this->rentalPriceAmount(),
            'rental_price_label' => $this->rentalPriceLabel(),
            'down_payment_percent' => $breakdown['down_payment_percent'] ?? $this->downPaymentPercentAmount(),
            'down_payment_percent_label' => $breakdown['down_payment_percent_label'] ?? $this->downPaymentPercentLabel(),
            'down_payment_amount' => $breakdown['down_payment_amount'] ?? null,
            'down_payment_amount_label' => $breakdown['down_payment_amount_label'] ?? null,
            'remaining_balance' => $breakdown['remaining_balance'] ?? null,
            'remaining_balance_label' => $breakdown['remaining_balance_label'] ?? null,
            'location' => $location,
            'picture_url' => $pictureUrl,
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Boat, $this>
     */
    public function boats(): BelongsToMany
    {
        return $this->belongsToMany(Boat::class, 'boat_organization_route')
            ->withTimestamps();
    }
}

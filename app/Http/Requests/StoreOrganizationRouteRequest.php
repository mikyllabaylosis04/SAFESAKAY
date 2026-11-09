<?php

namespace App\Http\Requests;

use App\Enums\OrganizationRouteType;
use App\Models\OrganizationRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->organization_id !== null;
    }

    protected function prepareForValidation(): void
    {
        $rawType = $this->input('trip_type');
        $tripType = is_string($rawType) && $rawType !== ''
            ? OrganizationRouteType::tryFrom($rawType)
            : OrganizationRouteType::RegularTrip;

        $origin = trim((string) $this->input('origin'));
        $destination = trim((string) $this->input('destination'));
        $isCreate = ! $this->route('organizationRoute');
        $homePort = $this->user()?->organization?->homePort();

        if ($isCreate && is_string($homePort) && $homePort !== '') {
            $origin = $homePort;
        }
        $rentalPrice = $this->normalizedDecimal($this->input('rental_price'));
        $regularFare = $this->normalizedDecimal($this->input('regular_fare'));
        $studentDiscount = $this->normalizedDecimal($this->input('student_discount_percent'));
        $seniorDiscount = $this->normalizedDecimal($this->input('senior_discount_percent'));
        $downPaymentPercent = $this->normalizedDecimal($this->input('down_payment_percent'));

        if ($tripType === OrganizationRouteType::ExclusiveBoatRental) {
            $regularFare = null;
            $studentDiscount = null;
            $seniorDiscount = null;
        } else {
            $rentalPrice = null;
            $downPaymentPercent = null;
            $studentDiscount = $studentDiscount ?? OrganizationRoute::FARE_DISCOUNT_PERCENT;
            $seniorDiscount = $seniorDiscount ?? OrganizationRoute::FARE_DISCOUNT_PERCENT;
        }

        $this->merge([
            'origin' => $origin,
            'destination' => $destination,
            ...($tripType ? ['trip_type' => $tripType->value] : []),
            'rental_price' => $rentalPrice,
            'regular_fare' => $regularFare,
            'student_discount_percent' => $studentDiscount,
            'senior_discount_percent' => $seniorDiscount,
            'down_payment_percent' => $downPaymentPercent,
            'name' => $origin && $destination
                ? OrganizationRoute::makeName($origin, $destination)
                : $this->input('name'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $route = $this->route('organizationRoute');
        $routeId = $route instanceof OrganizationRoute ? $route->id : null;

        return [
            'origin' => ['required', 'string', 'max:80'],
            'destination' => ['required', 'string', 'max:80', 'different:origin'],
            'name' => [
                'required',
                'string',
                'max:170',
                Rule::unique('organization_routes', 'name')
                    ->where('organization_id', $this->user()?->organization_id)
                    ->ignore($routeId),
            ],
            'trip_type' => ['required', Rule::enum(OrganizationRouteType::class)],
            'rental_price' => [
                Rule::requiredIf($this->input('trip_type') === OrganizationRouteType::ExclusiveBoatRental->value),
                'nullable',
                'numeric',
                'gte:0',
                'decimal:0,2',
                'max:999999.99',
            ],
            'down_payment_percent' => [
                Rule::requiredIf($this->input('trip_type') === OrganizationRouteType::ExclusiveBoatRental->value),
                'nullable',
                'numeric',
                'gte:0',
                'lte:100',
                'decimal:0,2',
            ],
            'regular_fare' => [
                'nullable',
                'numeric',
                'gte:0',
                'decimal:0,2',
                'max:999999.99',
            ],
            'student_discount_percent' => [
                'nullable',
                'numeric',
                'gte:0',
                'lte:100',
                'decimal:0,2',
            ],
            'senior_discount_percent' => [
                'nullable',
                'numeric',
                'gte:0',
                'lte:100',
                'decimal:0,2',
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'trip_type' => 'route type',
            'rental_price' => 'rental price',
            'regular_fare' => 'regular fare',
            'student_discount_percent' => 'student discount',
            'senior_discount_percent' => 'senior discount',
            'down_payment_percent' => 'required down payment',
            'is_active' => 'status',
        ];
    }

    private function normalizedDecimal(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $raw = str_replace([',', '₱', '%', ' '], '', $raw);
        }

        return $raw;
    }
}

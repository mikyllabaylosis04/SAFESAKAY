<?php

namespace Database\Factories;

use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use App\Enums\BoatStatus;
use App\Models\Boat;
use App\Models\Operator;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Boat>
 */
class BoatFactory extends Factory
{
    protected $model = Boat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99);

        return [
            'organization_id' => Organization::factory(),
            'name' => 'Lakandula '.$number,
            'code' => 'PB-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'capacity' => fake()->randomElement([12, 16, 20, 24, 30]),
            'type' => 'Pump Boat',
            'license_no' => 'LIC-'.fake()->numerify('#####'),
            'registration_date' => fake()->dateTimeBetween('-3 years', 'now'),
            'registration_status' => BoatRegistrationStatus::Registered,
            'status' => BoatStatus::Available,
            'service_type' => BoatService::RegularTrip->value,
            'service_availability' => [BoatService::RegularTrip->value],
            'safety_information' => 'Life jackets for all passengers. Fire extinguisher and first-aid kit on board.',
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Boat $boat): void {
            $boat->type = 'Pump Boat';
            $boat->registration_status ??= BoatRegistrationStatus::Registered;
            $boat->service_availability = match ($boat->service_type) {
                BoatService::RegularTrip->value => [BoatService::RegularTrip->value],
                BoatService::PrivateBooking->value => [BoatService::PrivateBooking->value],
                'both' => BoatService::values(),
                default => $boat->service_availability ?? [],
            };
        })->afterCreating(function (Boat $boat) {
            Operator::query()->create([
                'boat_id' => $boat->id,
                'first_name' => 'Juan',
                'middle_name' => null,
                'last_name' => 'Dela Cruz',
                'full_name' => 'Juan Dela Cruz',
                'age' => fake()->numberBetween(24, 58),
                'gender' => fake()->randomElement(['Male', 'Female', 'Prefer not to say']),
                'contact_number' => '09'.fake()->numerify('#########'),
                'address' => 'Barangay Lakandula, Siargao',
                'experience_years' => fake()->optional()->numberBetween(0, 20),
                'admin_use_only' => true,
            ]);
        });
    }
}

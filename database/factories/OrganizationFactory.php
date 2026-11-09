<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Barangay '.fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'address' => fake()->streetAddress().', Siargao',
            'contact_number' => '09'.fake()->numerify('#########'),
            'is_active' => true,
            'approved_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization) {
            $organization->seedDefaultRoute();
        });
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing') && config('database.default') === 'mysql') {
            $database = (string) config('database.connections.mysql.database', '');

            if ($database === (string) config('safesakay.demo_database')) {
                $this->fail('PHPUnit must not run against demo MySQL ('.$database.'). Use php artisan test (see phpunit.xml).');
            }
        }

        $this->withoutVite();
    }
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function operatorPayload(array $overrides = []): array
    {
        return [
            'operator_first_name' => 'Test',
            'operator_last_name' => 'Operator',
            'operator_age' => 32,
            'operator_gender' => 'Male',
            'operator_contact_number' => '09123456789',
            'operator_address' => 'Barangay Lakandula, Del Carmen, Siargao',
            'operator_experience_years' => 5,
            'operator_emergency_contact_name' => 'Maria Santos',
            'operator_emergency_contact_number' => '09987654321',
            ...$overrides,
        ];
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

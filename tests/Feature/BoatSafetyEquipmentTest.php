<?php

namespace Tests\Feature;

use App\Enums\SafetyEquipment;
use App\Models\Boat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoatSafetyEquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_safety_equipment_and_life_jacket_quantity_are_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 12,
                'type' => 'Pump Boat',
                'status' => 'available',
                'service_type' => 'regular_trip',
                'safety_equipment' => [
                    SafetyEquipment::LifeJackets->value,
                    SafetyEquipment::FireExtinguisher->value,
                    SafetyEquipment::FirstAidKit->value,
                ],
                'life_jacket_quantity' => 12,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $boat = Boat::query()->where('code', 'CAIX')->first();

        $this->assertNotNull($boat);
        $this->assertSame([
            SafetyEquipment::LifeJackets->value,
            SafetyEquipment::FireExtinguisher->value,
            SafetyEquipment::FirstAidKit->value,
        ], $boat->safety_equipment);
        $this->assertSame(12, $boat->life_jacket_quantity);
        $this->assertStringContainsString('Life Jackets (12)', (string) $boat->safety_information);
        $this->assertStringContainsString('Fire Extinguisher', (string) $boat->safety_information);
    }

    public function test_life_jacket_quantity_is_required_when_life_jackets_are_selected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('boats.index'))
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 12,
                'type' => 'Pump Boat',
                'status' => 'available',
                'service_type' => 'regular_trip',
                'safety_equipment' => [SafetyEquipment::LifeJackets->value],
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'))
            ->assertSessionHasErrors('life_jacket_quantity');
    }

    public function test_life_jacket_quantity_is_cleared_when_life_jackets_are_not_selected(): void
    {
        $user = User::factory()->create();
        $boat = Boat::factory()->create([
            'code' => 'CAIX',
            'organization_id' => $user->organization_id,
            'safety_equipment' => [SafetyEquipment::LifeJackets->value],
            'life_jacket_quantity' => 8,
        ]);

        $this->actingAs($user)
            ->post(route('boats.update', $boat), [
                'name' => $boat->name,
                'code' => $boat->code,
                'capacity' => $boat->capacity,
                'type' => $boat->type,
                'status' => $boat->status->value,
                'service_type' => $boat->service_type,
                'safety_equipment' => [SafetyEquipment::Flashlight->value],
                'life_jacket_quantity' => 8,
                ...$this->operatorPayload([
                    'operator_first_name' => 'Test',
                    'operator_last_name' => 'Operator',
                ]),
            ])
            ->assertRedirect(route('boats.index'));

        $boat->refresh();

        $this->assertSame([SafetyEquipment::Flashlight->value], $boat->safety_equipment);
        $this->assertNull($boat->life_jacket_quantity);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatorInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_name_parts_and_optional_experience_are_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 12,
                ...$this->operatorPayload([
                    'operator_first_name' => 'Juan',
                    'operator_middle_name' => 'Miguel',
                    'operator_last_name' => "O'Neil",
                    'operator_age' => 28,
                    'operator_gender' => 'Prefer not to say',
                    'operator_experience_years' => 8,
                    'operator_emergency_contact_name' => 'Maria Santos',
                    'operator_emergency_contact_number' => '09987654321',
                ]),
            ])
            ->assertRedirect(route('boats.index'));

        $boat = Boat::query()->where('code', 'CAIX')->first();

        $this->assertNotNull($boat?->operator);
        $this->assertSame('Juan', $boat->operator->first_name);
        $this->assertSame('Miguel', $boat->operator->middle_name);
        $this->assertSame("O'Neil", $boat->operator->last_name);
        $this->assertSame("Juan Miguel O'Neil", $boat->operator->full_name);
        $this->assertSame(28, $boat->operator->age);
        $this->assertSame('Prefer not to say', $boat->operator->gender);
        $this->assertSame('09123456789', $boat->operator->contact_number);
        $this->assertSame(8, $boat->operator->experience_years);
        $this->assertSame('Maria Santos', $boat->operator->emergency_contact_name);
        $this->assertSame('09987654321', $boat->operator->emergency_contact_number);
        $this->assertNull($boat->operator->user_id);
    }

    public function test_operator_required_fields_are_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('boats.index'))
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 12,
                'operator_first_name' => 'J',
                'operator_last_name' => 'Operator2',
                'operator_age' => 16,
                'operator_gender' => '',
                'operator_contact_number' => '0912AB56789',
                'operator_address' => 'Town',
            ])
            ->assertRedirect(route('boats.index'))
            ->assertSessionHasErrors([
                'operator_first_name',
                'operator_last_name',
                'operator_age',
                'operator_gender',
                'operator_contact_number',
                'operator_address',
                'operator_experience_years',
                'operator_emergency_contact_name',
                'operator_emergency_contact_number',
            ]);
    }

    public function test_underage_operators_are_rejected_for_safety(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('boats.index'))
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 12,
                ...$this->operatorPayload([
                    'operator_age' => 17,
                ]),
            ])
            ->assertRedirect(route('boats.index'))
            ->assertSessionHasErrors('operator_age');

        $this->assertDatabaseMissing('boats', [
            'organization_id' => $user->organization_id,
            'code' => 'CAIX',
        ]);
    }

    public function test_private_booking_page_hides_private_operator_details(): void
    {
        $user = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'code' => 'PAKYAW',
            'service_type' => 'private_booking',
            'status' => 'available',
        ]);

        $boat->operator?->update([
            'age' => 41,
            'address' => 'Secret operator address, Siargao',
            'experience_years' => 12,
            'emergency_contact_name' => 'Hidden Contact',
        ]);

        $this->actingAs($user)
            ->get(route('schedules.boat-booking-schedule'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('schedules/BoatBookingSchedule')
                ->where('availableBoats.0.operator.full_name', $boat->operator?->full_name)
                ->where('availableBoats.0.operator.contact_number', $boat->operator?->contact_number)
                ->where('availableBoats.0.home_port', $user->organization?->homePort())
                ->missing('availableBoats.0.operator.age')
                ->missing('availableBoats.0.operator.address')
                ->missing('availableBoats.0.operator.experience_years')
                ->missing('availableBoats.0.operator.emergency_contact_name'));
    }

    public function test_updating_a_boat_keeps_the_linked_mobile_account(): void
    {
        $admin = User::factory()->create();
        $operatorAccount = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
            'username' => 'juan.operator',
        ]);
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'code' => 'KEEP-OP',
        ]);

        $boat->operator?->update(['user_id' => $operatorAccount->id]);

        $this->actingAs($admin)
            ->post(route('boats.update', $boat), [
                'name' => 'Updated CAIX',
                'code' => 'KEEP-OP',
                'capacity' => 16,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $this->assertSame($operatorAccount->id, $boat->fresh()?->operator?->user_id);
    }

    public function test_admin_can_create_the_operator_app_account_after_registering_a_boat(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'CAIX',
            'code' => 'PB-010',
        ]);

        $this->assertNull($boat->operator?->user_id);

        $response = $this->actingAs($admin)
            ->post(route('boats.operator-account', $boat), [
                'email' => 'juan.skipper@safesakay.test',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ]);

        $operatorUser = User::query()->where('email', 'juan.skipper@safesakay.test')->first();

        $this->assertNotNull($operatorUser);
        $this->assertSame(UserRole::Operator, $operatorUser->role);
        $this->assertSame($admin->organization_id, $operatorUser->organization_id);
        $this->assertSame($operatorUser->id, $boat->fresh()?->operator?->user_id);
        $this->assertSame($boat->operator?->full_name, $operatorUser->name);

        $response->assertRedirect(route('users.index', [
            'view' => $operatorUser->id,
            'role' => 'operator',
        ]));
    }

    public function test_boat_registration_prompts_the_operator_account_step(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('boats.store'), [
                'name' => 'CAIX Login',
                'code' => 'PB-ACC',
                'capacity' => 12,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'))
            ->assertSessionHas('create_operator_boat_id');

        $boat = Boat::query()->where('code', 'PB-ACC')->first();

        $this->assertNotNull($boat);

        $this->actingAs($admin)
            ->get(route('boats.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('boats/Index')
                ->where('createOperatorBoatId', $boat->id)
            );
    }

    public function test_operator_app_account_cannot_be_created_twice_for_the_same_skipper(): void
    {
        $admin = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
        ]);
        $existing = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Operator,
        ]);
        $boat->operator?->update(['user_id' => $existing->id]);

        $this->actingAs($admin)
            ->from(route('boats.index'))
            ->post(route('boats.operator-account', $boat), [
                'email' => 'second.login@safesakay.test',
                'password' => 'SafeSakay@1',
                'password_confirmation' => 'SafeSakay@1',
            ])
            ->assertRedirect(route('boats.index'))
            ->assertSessionHasErrors('email');
    }
}

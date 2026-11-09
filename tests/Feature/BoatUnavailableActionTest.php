<?php

namespace Tests\Feature;

use App\Enums\BoatStatus;
use App\Models\Boat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoatUnavailableActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_mark_a_boat_unavailable_from_the_fleet_list(): void
    {
        $user = User::factory()->create();
        $boat = Boat::factory()->create([
            'organization_id' => $user->organization_id,
            'name' => 'CHARMIS',
            'code' => 'PB-003',
            'status' => BoatStatus::Available,
        ]);

        $this->actingAs($user)
            ->from(route('boats.index'))
            ->patch(route('boats.status.update', $boat), [
                'status' => BoatStatus::Unavailable->value,
            ])
            ->assertRedirect(route('boats.index'));

        $this->assertNotSoftDeleted($boat);
        $this->assertSame(BoatStatus::Unavailable, $boat->fresh()->status);
    }
}

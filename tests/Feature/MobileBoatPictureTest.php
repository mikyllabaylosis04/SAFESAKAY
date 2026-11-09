<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Boat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileBoatPictureTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_boat_list_uses_storage_url_with_cache_buster(): void
    {
        Storage::fake('public');

        $path = 'boats/test-boat.jpg';
        Storage::disk('public')->put($path, 'fake-image');

        $admin = User::factory()->create();
        $passenger = User::factory()->create([
            'organization_id' => $admin->organization_id,
            'role' => UserRole::Passenger,
        ]);

        $boat = Boat::factory()->create([
            'organization_id' => $admin->organization_id,
            'picture_path' => $path,
        ]);
        $boat->pictures()->create([
            'path' => $path,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($passenger, 'sanctum')
            ->getJson('/api/boats')
            ->assertOk()
            ->assertJsonPath('data.0.id', $boat->id);

        $url = (string) $response->json('data.0.picture_url');

        $this->assertStringContainsString('/storage/boats/test-boat.jpg', $url);
        $this->assertStringContainsString('v=', $url);
        $this->assertStringNotContainsString('/api/boats/'.$boat->id.'/picture', $url);
    }
}

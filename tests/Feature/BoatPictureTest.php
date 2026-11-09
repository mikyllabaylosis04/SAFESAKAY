<?php

namespace Tests\Feature;

use App\Models\Boat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BoatPictureTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_boat_pictures_are_visible(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('caix.jpg', 640, 480);

        $this->actingAs($user)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 10,
                'type' => 'Pump Boat',
                'status' => 'available',
                'service_type' => 'private_booking',
                'boat_picture' => $file,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $boat = Boat::query()->where('code', 'CAIX')->first();

        $this->assertNotNull($boat);
        $this->assertNotNull($boat->picture_path);
        $this->assertSame(1, $boat->pictures()->count());
        $this->assertTrue(Storage::disk('public')->exists($boat->picture_path));
        $this->assertSame('/storage/'.$boat->picture_path, $boat->pictureUrl());

        $this->get($boat->pictureUrl())->assertOk();
    }

    public function test_a_boat_can_have_up_to_five_pictures(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $files = collect(range(1, 5))
            ->map(fn (int $index) => UploadedFile::fake()->image("boat-{$index}.jpg"))
            ->all();

        $this->actingAs($user)
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 10,
                'type' => 'Pump Boat',
                'status' => 'available',
                'service_type' => 'private_booking',
                'boat_pictures' => $files,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'));

        $boat = Boat::query()->where('code', 'CAIX')->first();

        $this->assertNotNull($boat);
        $this->assertSame(5, $boat->pictures()->count());
        $this->assertCount(5, $boat->picturePayload());
    }

    public function test_a_sixth_boat_picture_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $files = collect(range(1, 6))
            ->map(fn (int $index) => UploadedFile::fake()->image("boat-{$index}.jpg"))
            ->all();

        $this->actingAs($user)
            ->from(route('boats.index'))
            ->post(route('boats.store'), [
                'name' => 'CAIX',
                'code' => 'CAIX',
                'capacity' => 10,
                'type' => 'Pump Boat',
                'status' => 'available',
                'service_type' => 'private_booking',
                'boat_pictures' => $files,
                ...$this->operatorPayload(),
            ])
            ->assertRedirect(route('boats.index'))
            ->assertSessionHasErrors('boat_pictures');

        $this->assertSame(0, Boat::query()->count());
    }
}

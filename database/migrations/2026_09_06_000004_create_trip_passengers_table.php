<?php

use App\Models\RegularTrip;
use App\Models\TripPassenger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('regular_trip_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->unsignedTinyInteger('age');
            $table->string('gender', 20);
            $table->string('contact_number', 20);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
        });

        RegularTrip::query()
            ->withoutGlobalScopes()
            ->with('boat')
            ->orderBy('id')
            ->each(function (RegularTrip $trip): void {
                $capacity = max(0, (int) $trip->boat?->capacity);

                if ($capacity === 0) {
                    return;
                }

                $count = min(
                    $capacity,
                    max(1, (int) $trip->passenger_count ?: (int) floor($capacity * 0.6)),
                );

                foreach (TripPassenger::sampleRecords($count, (int) $trip->id) as $index => $passenger) {
                    TripPassenger::query()->create([
                        'organization_id' => $trip->organization_id,
                        'regular_trip_id' => $trip->id,
                        'full_name' => $passenger['full_name'],
                        'age' => $passenger['age'],
                        'gender' => $passenger['gender'],
                        'contact_number' => $passenger['contact_number'],
                        'sort_order' => $index + 1,
                    ]);
                }

                $trip->forceFill(['passenger_count' => $count])->save();
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_passengers');
    }
};

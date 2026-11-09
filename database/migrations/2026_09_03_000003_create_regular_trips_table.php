<?php

use App\Enums\RegularTripStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regular_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->string('route');
            $table->date('trip_date');
            $table->time('departure_time');
            $table->unsignedSmallInteger('passenger_count')->default(0);
            $table->string('status')->default(RegularTripStatus::Scheduled->value);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        $boats = DB::table('boats')->orderBy('id')->get();

        if ($boats->isEmpty()) {
            return;
        }

        $routes = [
            'Dapa → General Luna',
            'General Luna → Dapa',
            'Dapa → Cloud 9',
            'Del Carmen → Dapa',
        ];

        $times = ['06:00:00', '07:30:00', '09:00:00', '12:00:00', '14:00:00', '16:30:00'];
        $statuses = [
            RegularTripStatus::OnTrip->value,
            RegularTripStatus::Scheduled->value,
            RegularTripStatus::Scheduled->value,
            RegularTripStatus::Delayed->value,
            RegularTripStatus::Completed->value,
            RegularTripStatus::Scheduled->value,
        ];

        foreach ($boats->values() as $index => $boat) {
            $tripDate = $index > 4 ? now()->addDay() : now();
            $time = $times[$index % count($times)];
            $route = $routes[$index % count($routes)];
            $capacity = max(1, (int) $boat->capacity);
            $passengers = min($capacity, 8 + (($index * 5) % $capacity));

            $status = $statuses[$index % count($statuses)];

            DB::table('regular_trips')->insert([
                'boat_id' => $boat->id,
                'route' => $route,
                'trip_date' => $tripDate->toDateString(),
                'departure_time' => $time,
                'passenger_count' => $passengers,
                'status' => $status,
                'remarks' => 'Regular public trip rotation.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('boat_histories')->insert([
                'boat_id' => $boat->id,
                'category' => 'schedule',
                'title' => $route.' · '.date('g:i A', strtotime($time)),
                'occurred_on' => $tripDate->toDateString(),
                'details' => 'Assigned to regular trip rotation.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('regular_trips');
    }
};

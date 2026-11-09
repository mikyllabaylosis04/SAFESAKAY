<?php

use App\Enums\BoatBookingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boat_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('contact_number')->nullable();
            $table->string('package');
            $table->date('booking_date');
            $table->time('start_time');
            $table->unsignedSmallInteger('passenger_count')->default(1);
            $table->string('status')->default(BoatBookingStatus::Confirmed->value);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        $privateBoatIds = DB::table('boats')->orderByDesc('id')->limit(3)->pluck('id');

        if ($privateBoatIds->isEmpty()) {
            return;
        }

        DB::table('boats')->whereIn('id', $privateBoatIds)->update([
            'service_type' => 'private_booking',
        ]);

        $packages = [
            'Private island hopping',
            'Dapa ↔ General Luna charter',
            'Sunset cruise',
        ];
        $customers = [
            ['Maria Santos', '09171234567'],
            ['Kyle Mendoza', '09987654321'],
            ['Ana Villanueva', '09051239876'],
        ];
        $times = ['08:00:00', '13:00:00', '16:30:00'];
        $statuses = [
            BoatBookingStatus::Confirmed->value,
            BoatBookingStatus::Ongoing->value,
            BoatBookingStatus::Confirmed->value,
        ];

        foreach ($privateBoatIds->values() as $index => $boatId) {
            if ($index > 1) {
                continue;
            }

            $boat = DB::table('boats')->where('id', $boatId)->first();
            $capacity = max(1, (int) ($boat->capacity ?? 12));
            $package = $packages[$index % count($packages)];
            $customer = $customers[$index % count($customers)];
            $time = $times[$index % count($times)];

            DB::table('boat_bookings')->insert([
                'boat_id' => $boatId,
                'customer_name' => $customer[0],
                'contact_number' => $customer[1],
                'package' => $package,
                'booking_date' => now()->toDateString(),
                'start_time' => $time,
                'passenger_count' => min($capacity, 6 + ($index * 2)),
                'status' => $statuses[$index],
                'remarks' => 'Private booking rental.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('boat_histories')->insert([
                'boat_id' => $boatId,
                'category' => 'rental',
                'title' => $package,
                'occurred_on' => now()->toDateString(),
                'details' => 'Booked rental for '.$customer[0].'.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('boat_bookings');
    }
};

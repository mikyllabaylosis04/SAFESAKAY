<?php

use App\Enums\BoatBookingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $boats = DB::table('boats')
            ->where('service_type', 'private_booking')
            ->orderBy('id')
            ->get();

        if ($boats->isEmpty()) {
            return;
        }

        $samples = [
            [
                'status' => BoatBookingStatus::Pending->value,
                'customer' => 'Rhea Dela Peña',
                'contact' => '09180001111',
                'package' => 'Private island hopping',
                'time' => '09:00:00',
                'days' => 1,
            ],
            [
                'status' => BoatBookingStatus::Pending->value,
                'customer' => 'Jonel Castro',
                'contact' => '09220002222',
                'package' => 'Cloud 9 drop-off',
                'time' => '10:30:00',
                'days' => 0,
            ],
            [
                'status' => BoatBookingStatus::Completed->value,
                'customer' => 'Liza Navarro',
                'contact' => '09330003333',
                'package' => 'Sunset cruise',
                'time' => '16:00:00',
                'days' => -2,
            ],
            [
                'status' => BoatBookingStatus::Cancelled->value,
                'customer' => 'Paolo Ramirez',
                'contact' => '09440004444',
                'package' => 'Dapa ↔ General Luna charter',
                'time' => '07:30:00',
                'days' => -1,
            ],
        ];

        foreach ($samples as $index => $sample) {
            $boat = $boats[$index % $boats->count()];
            $capacity = max(1, (int) $boat->capacity);

            DB::table('boat_bookings')->insert([
                'boat_id' => $boat->id,
                'customer_name' => $sample['customer'],
                'contact_number' => $sample['contact'],
                'package' => $sample['package'],
                'booking_date' => now()->addDays($sample['days'])->toDateString(),
                'start_time' => $sample['time'],
                'passenger_count' => min($capacity, 8),
                'status' => $sample['status'],
                'remarks' => 'Booking request record.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('boat_bookings')
            ->whereIn('customer_name', [
                'Rhea Dela Peña',
                'Jonel Castro',
                'Liza Navarro',
                'Paolo Ramirez',
            ])
            ->delete();
    }
};

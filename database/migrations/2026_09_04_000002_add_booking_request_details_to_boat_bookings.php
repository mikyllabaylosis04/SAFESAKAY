<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->string('code')->nullable()->after('id');
            $table->string('destination')->nullable()->after('package');
            $table->string('purpose')->nullable()->after('destination');
        });

        $year = now()->year;
        $destinations = [
            'Sagisi Beach',
            'Sugba Lagoon',
            'Cloud 9',
            'General Luna',
            'Naked Island',
        ];
        $purposes = [
            'Island hopping',
            'Mangaligo',
            'Family trip',
            'Swimming',
            'Direct travel',
        ];

        foreach (DB::table('boat_bookings')->orderBy('id')->get() as $index => $booking) {
            DB::table('boat_bookings')->where('id', $booking->id)->update([
                'code' => sprintf('BR-%d-%05d', $year, $booking->id),
                'destination' => $booking->destination ?: ($destinations[$index % count($destinations)]),
                'purpose' => $booking->purpose ?: ($purposes[$index % count($purposes)]),
            ]);
        }

        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('boat_bookings', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'destination', 'purpose']);
        });
    }
};

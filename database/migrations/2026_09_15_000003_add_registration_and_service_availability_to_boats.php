<?php

use App\Enums\BoatRegistrationStatus;
use App\Enums\BoatService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boats', function (Blueprint $table) {
            $table->string('registration_status')
                ->default(BoatRegistrationStatus::Registered->value)
                ->after('status');
            $table->json('service_availability')->nullable()->after('service_type');
        });

        foreach (DB::table('boats')->orderBy('id')->get() as $boat) {
            $availability = match ($boat->service_type) {
                BoatService::RegularTrip->value => [BoatService::RegularTrip->value],
                BoatService::PrivateBooking->value => [BoatService::PrivateBooking->value],
                'both' => [
                    BoatService::RegularTrip->value,
                    BoatService::PrivateBooking->value,
                ],
                default => [],
            };

            DB::table('boats')->where('id', $boat->id)->update([
                'registration_status' => BoatRegistrationStatus::Registered->value,
                'service_availability' => json_encode($availability),
                'type' => 'Pump Boat',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('boats', function (Blueprint $table) {
            $table->dropColumn(['registration_status', 'service_availability']);
        });
    }
};

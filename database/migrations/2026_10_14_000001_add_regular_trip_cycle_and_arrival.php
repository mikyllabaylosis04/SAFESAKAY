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
        Schema::table('regular_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('regular_trips', 'arrived_at')) {
                $table->timestamp('arrived_at')->nullable()->after('departed_at');
            }

            if (! Schema::hasColumn('regular_trips', 'cycle_number')) {
                $table->unsignedSmallInteger('cycle_number')->default(1)->after('rotation_order');
            }

            if (! Schema::hasColumn('regular_trips', 'return_of_id')) {
                $table->unsignedBigInteger('return_of_id')->nullable()->after('boat_id');
                $table->index('return_of_id');
            }
        });

        $this->backfillCyclesAndPairs();
    }

    public function down(): void
    {
        Schema::table('regular_trips', function (Blueprint $table) {
            if (Schema::hasColumn('regular_trips', 'return_of_id')) {
                $table->dropIndex(['return_of_id']);
                $table->dropColumn('return_of_id');
            }

            if (Schema::hasColumn('regular_trips', 'cycle_number')) {
                $table->dropColumn('cycle_number');
            }

            if (Schema::hasColumn('regular_trips', 'arrived_at')) {
                $table->dropColumn('arrived_at');
            }
        });
    }

    private function backfillCyclesAndPairs(): void
    {
        $trips = DB::table('regular_trips')
            ->orderBy('trip_date')
            ->orderBy('boat_id')
            ->orderBy('id')
            ->get();

        $cycleByGoing = [];
        $outboundIndex = [];

        foreach ($trips as $trip) {
            $direction = (string) ($trip->direction ?? '');
            $route = (string) ($trip->route ?? '');
            $isOutbound = true;

            if (str_contains($direction, '→') && str_contains($route, '↔')) {
                [$origin, $destination] = array_map('trim', explode('↔', $route, 2));
                $isOutbound = $direction === $origin.' → '.$destination;
            }

            if (! $isOutbound) {
                continue;
            }

            $key = $trip->boat_id.'|'.$trip->trip_date.'|'.$trip->route;
            $outboundIndex[$key] = ($outboundIndex[$key] ?? 0) + 1;
            $cycle = $outboundIndex[$key];
            $cycleByGoing[$trip->id] = $cycle;

            DB::table('regular_trips')->where('id', $trip->id)->update([
                'cycle_number' => $cycle,
            ]);
        }

        foreach ($trips as $trip) {
            $direction = (string) ($trip->direction ?? '');
            $route = (string) ($trip->route ?? '');
            $isOutbound = true;

            if (str_contains($direction, '→') && str_contains($route, '↔')) {
                [$origin, $destination] = array_map('trim', explode('↔', $route, 2));
                $isOutbound = $direction === $origin.' → '.$destination;
            }

            if ($isOutbound || ! str_contains($direction, '→')) {
                continue;
            }

            [$from, $to] = array_map('trim', explode('→', $direction, 2));
            $goingDirection = $to.' → '.$from;

            $going = $trips->first(function ($candidate) use ($trip, $goingDirection) {
                $status = (string) $candidate->status;

                return (int) $candidate->id !== (int) $trip->id
                    && (int) $candidate->boat_id === (int) $trip->boat_id
                    && (string) $candidate->trip_date === (string) $trip->trip_date
                    && (string) $candidate->route === (string) $trip->route
                    && (string) $candidate->direction === $goingDirection
                    && ! in_array($status, [
                        RegularTripStatus::Cancelled->value,
                    ], true);
            });

            if (! $going) {
                continue;
            }

            DB::table('regular_trips')->where('id', $trip->id)->update([
                'return_of_id' => $going->id,
                'cycle_number' => $cycleByGoing[$going->id] ?? 1,
            ]);
        }
    }
};

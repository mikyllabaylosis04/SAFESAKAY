<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boats', function (Blueprint $table) {
            $table->text('safety_information')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        if (Schema::hasColumn('boats', 'notes')) {
            foreach (DB::table('boats')->get() as $boat) {
                DB::table('boats')->where('id', $boat->id)->update([
                    'safety_information' => $boat->notes,
                ]);
            }

            Schema::table('boats', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }

        Schema::create('boat_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->date('occurred_on');
            $table->string('details')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('boats')->get() as $boat) {
            DB::table('boat_histories')->insert([
                [
                    'boat_id' => $boat->id,
                    'category' => 'trip',
                    'title' => 'Dapa to General Luna',
                    'occurred_on' => now()->subDays(4)->toDateString(),
                    'details' => 'Regular public trip completed with 18 passengers.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'boat_id' => $boat->id,
                    'category' => 'schedule',
                    'title' => 'Morning rotation · 6:00 AM',
                    'occurred_on' => now()->subDays(8)->toDateString(),
                    'details' => 'Assigned to regular trip rotation.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'boat_id' => $boat->id,
                    'category' => 'rental',
                    'title' => 'Private island hopping',
                    'occurred_on' => now()->subDays(12)->toDateString(),
                    'details' => 'Booked rental for 6 hours, approved and completed.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('boat_histories');

        Schema::table('boats', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });

        foreach (DB::table('boats')->get() as $boat) {
            DB::table('boats')->where('id', $boat->id)->update([
                'notes' => $boat->safety_information,
            ]);
        }

        Schema::table('boats', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['safety_information', 'is_active']);
        });
    }
};

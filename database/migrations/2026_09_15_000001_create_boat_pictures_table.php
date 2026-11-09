<?php

use App\Models\Boat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boat_pictures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Boat::query()
            ->withoutGlobalScopes()
            ->whereNotNull('picture_path')
            ->orderBy('id')
            ->each(function (Boat $boat): void {
                $boat->pictures()->create([
                    'path' => $boat->picture_path,
                    'sort_order' => 0,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('boat_pictures');
    }
};

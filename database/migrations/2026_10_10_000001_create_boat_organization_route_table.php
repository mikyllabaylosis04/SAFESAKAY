<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boat_organization_route', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_route_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['boat_id', 'organization_route_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boat_organization_route');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'contact_number')) {
                $table->string('contact_number', 20)->nullable()->after('address');
            }
            if (! Schema::hasColumn('users', 'picture_path')) {
                $table->string('picture_path')->nullable()->after('contact_number');
            }
            if (! Schema::hasColumn('users', 'emergency_contact_name')) {
                $table->string('emergency_contact_name', 120)->nullable()->after('picture_path');
            }
            if (! Schema::hasColumn('users', 'emergency_contact_number')) {
                $table->string('emergency_contact_number', 20)->nullable()->after('emergency_contact_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['emergency_contact_number', 'emergency_contact_name', 'picture_path', 'contact_number'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

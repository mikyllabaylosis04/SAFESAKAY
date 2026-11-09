
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            // PLATFORM BRANDING
            $table->string('platform_name', 100)
                ->default('SafeSakay');

            $table->text('platform_description')
                ->nullable();

            $table->string('logo_path')
                ->nullable();

            // GENERAL SYSTEM CONFIGURATION
            $table->string('timezone')
                ->default('Asia/Manila');

            $table->string('date_format')
                ->default('MM/DD/YYYY');

            $table->string('time_format')
                ->default('12-hour');

            $table->string('language')
                ->default('en');

            // NOTIFICATION SETTINGS
            $table->boolean('notify_organization_registration')
                ->default(true);

            $table->boolean('notify_approval_requests')
                ->default(true);

            $table->boolean('notify_system_activity')
                ->default(true);

            $table->boolean('notify_security_alerts')
                ->default(true);

            // SECURITY SETTINGS
            $table->unsignedInteger('session_timeout')
                ->default(30);

            $table->unsignedInteger('max_login_attempts')
                ->default(5);

            $table->boolean('require_strong_passwords')
                ->default(true);

            $table->boolean('enable_activity_logging')
                ->default(true);

            // CREATED AT AND UPDATED AT
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};

<?php

namespace App\Providers;

use App\Models\BoatBooking;
use App\Models\RegularTrip;
use App\Observers\BoatBookingObserver;
use App\Observers\RegularTripObserver;
use App\Listeners\PromptBeforeDestructiveDatabaseCommands;
use App\Listeners\PromptBeforeRestoreSeeders;
use App\Support\DemoDatabaseGuard;
use Illuminate\Support\Facades\DB;
use App\Support\PasswordPolicy;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Services\HostedRentalCheckoutService;
use App\Services\PushNotificationForwarder;
use Illuminate\Notifications\Events\NotificationSent;
use App\Support\OnlinePaymentProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HostedRentalCheckoutService::class, function () {
            return HostedRentalCheckoutService::forActive();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Password::defaults(fn () => PasswordPolicy::rule());
        BoatBooking::observe(BoatBookingObserver::class);
        RegularTrip::observe(RegularTripObserver::class);

        Event::listen(CommandStarting::class, PromptBeforeDestructiveDatabaseCommands::class);
        Event::listen(CommandStarting::class, PromptBeforeRestoreSeeders::class);
        Event::listen(NotificationSent::class, PushNotificationForwarder::class);

        if (DemoDatabaseGuard::isProtectedMysql() && ! config('safesakay.allow_destructive_db_commands', false)) {
            DB::prohibitDestructiveCommands();
        }

        $this->configureOutboundHttpCaBundle();
    }

    /**
     * WAMP/Windows PHP often ships without curl.cainfo; outbound HTTPS (Xendit, etc.) then fails with cURL error 60.
     */
    private function configureOutboundHttpCaBundle(): void
    {
        if (trim((string) ini_get('curl.cainfo')) !== '') {
            return;
        }

        $bundle = storage_path('certs/cacert.pem');

        if (is_file($bundle)) {
            Http::globalOptions(['verify' => $bundle]);
        }
    }
}

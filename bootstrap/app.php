<?php

use App\Http\Middleware\ApplyPlatformSettings;
use App\Http\Middleware\EnsureAccountNotSuspended;
use App\Http\Middleware\EnsureAdminWebAccess;
use App\Http\Middleware\EnsureBarangayAdmin;
use App\Http\Middleware\EnsureOrganizationActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAuthenticated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('rotation:advance')->dailyAt('00:05');
        $schedule->command('regular-trips:notify-boarding-wait')->everyMinute();
        $schedule->command('rentals:expire-pending')->hourly();
        $schedule->command('no-show:expire-pending-reschedules')->everyFiveMinutes();
        $schedule->command('passenger-no-show:expire-disputes')->everyFifteenMinutes();
        $schedule->command('commissions:process-settlement-reminders')->dailyAt('08:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [
            ApplyPlatformSettings::class,
        ], append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            return $request->is('platform', 'platform/*')
                ? '/superadmin'
                : '/login';
        });

        $middleware->alias([
            'admin.web' => EnsureAdminWebAccess::class,
            'barangay.web' => EnsureBarangayAdmin::class,
            'super.admin' => EnsureSuperAdmin::class,
            'org.active' => EnsureOrganizationActive::class,
            'account.not_suspended' => EnsureAccountNotSuspended::class,
            'guest' => RedirectIfAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

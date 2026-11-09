<?php

use App\Http\Controllers\Settings\AdminSettingsController;
use App\Http\Controllers\Settings\PaymentSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'admin.web'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/organization/closure', [\App\Http\Controllers\Settings\OrganizationClosureController::class, 'store'])
        ->middleware('barangay.web')
        ->name('settings.organization.closure');

    Route::middleware('barangay.web')->group(function () {
        Route::get('settings/commission', [AdminSettingsController::class, 'commission'])->name('settings.commission.edit');
        Route::get('settings/rental-policies', [AdminSettingsController::class, 'rentalPolicies'])->name('settings.rental-policies.edit');
        Route::get('settings/notifications', [AdminSettingsController::class, 'notifications'])->name('settings.notifications.edit');
        Route::post('settings/notifications', [AdminSettingsController::class, 'updateNotifications'])->name('settings.notifications.update');

        Route::redirect('settings/payments', '/settings/commission')->name('settings.payments.edit');
        Route::post('settings/payments', [PaymentSettingsController::class, 'update'])->name('settings.payments.update');
        Route::post('settings/payments/operator-gcash/{account}/approve', [PaymentSettingsController::class, 'approve'])->name('settings.payments.operator-gcash.approve');
        Route::post('settings/payments/operator-gcash/{account}/reject', [PaymentSettingsController::class, 'reject'])->name('settings.payments.operator-gcash.reject');
        Route::post('settings/payments/commission', [PaymentSettingsController::class, 'updateCommission'])->name('settings.payments.commission.update');
        Route::post('settings/payments/no-show-rules', [PaymentSettingsController::class, 'updateNoShowRules'])->name('settings.payments.no-show-rules.update');
    });
});

Route::middleware(['auth', 'verified', 'admin.web'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');


<?php

use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\AdminPasswordResetController;
use App\Http\Controllers\Auth\RestoreRequestController;
use App\Http\Controllers\Auth\SuperAdminLoginController;
use App\Http\Controllers\BoatBookingController;
use App\Http\Controllers\BoatController;
use App\Http\Controllers\Admin\MyCommissionController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OperatorNoShowController;
use App\Http\Controllers\OrganizationRegistrationController;
use App\Http\Controllers\OrganizationRouteController;
use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Platform\PlatformReportController;
use App\Http\Controllers\Platform\PlatformSystemSettingsController;
use App\Http\Controllers\Platform\PlatformUserController;
use App\Http\Controllers\RegularTripController;
use App\Http\Controllers\RentalBlackoutController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TripConfirmationController;
use App\Http\Controllers\TripPassengerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeatherSuspensionController;
use App\Http\Controllers\XenditPaymentReturnController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

// HOME
Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route(Auth::user()->homeRouteName());
    }

    return Inertia::render('adminlogin', [
        'canResetPassword' => Features::enabled(Features::resetPasswords()),
        'status' => session('status'),
    ]);
})->name('home');

Route::redirect('super-admin', '/superadmin')
    ->name('super-admin');

Route::get('locations', [
    OrganizationRegistrationController::class,
    'locations'
])->name('locations');

// GUEST ROUTES
Route::middleware('guest')->group(function () {
    Route::get('register', [
        OrganizationRegistrationController::class, 'create'
    ])->name('register');

    Route::get('register/locations', [
        OrganizationRegistrationController::class, 'locations'
    ])->name('register.locations');

    Route::post('register', [
        OrganizationRegistrationController::class, 'store'
    ])->name('register.store');

    Route::post('forgot-password/send-code', [
        AdminPasswordResetController::class, 'sendCode'
    ])->middleware('throttle:5,1')
      ->name('password.code.send');

    Route::post('forgot-password/verify-code', [
        AdminPasswordResetController::class, 'verifyCode'
    ])->middleware('throttle:10,1')
      ->name('password.code.verify');

    Route::post('forgot-password/reset', [
        AdminPasswordResetController::class, 'reset'
    ])->middleware('throttle:5,1')
      ->name('password.code.reset');

    Route::post('forgot-password/start-over', [
        AdminPasswordResetController::class, 'startOver'
    ])->name('password.code.start-over');

    Route::get('superadmin', [
        SuperAdminLoginController::class, 'create'
    ])->name('superadmin.login');

    Route::post('superadmin', [
        SuperAdminLoginController::class, 'store'
    ])->middleware('throttle:login')
      ->name('superadmin.login.store');

    Route::post('restore-request', [
        RestoreRequestController::class, 'store'
    ])->middleware('throttle:5,1')
      ->name('restore-request.store');
});

// SUPER ADMIN LOGOUT
Route::post('superadmin/logout', [
    SuperAdminLoginController::class, 'destroy'
])->middleware('auth')->name('superadmin.logout');

// SUPER ADMIN PLATFORM ROUTES
Route::middleware([
    'auth',
    'verified',
    'admin.web',
    'super.admin',
])
->prefix('platform')
->name('platform.')
->group(function () {

    // DASHBOARD
    Route::get('/', [
        PlatformController::class, 'dashboard'
    ])->name('dashboard');

    // ORGANIZATIONS
    Route::get('organizations', [
        PlatformController::class, 'organizations'
    ])->name('organizations');

    Route::get('organization-requests', [
        PlatformController::class, 'organizationRequests'
    ])->name('organization-requests');

    // SYSTEM REPORTS
    Route::get('reports', [
        PlatformReportController::class, 'index'
    ])->name('reports');

    Route::get('reports/access-history', [
        PlatformReportController::class, 'legacyAccessHistory'
    ])->name('reports.access-history');

    Route::get('reports/export/{report}', [
        PlatformReportController::class, 'export'
    ])->name('reports.export');

    // AUDIT LOGS
    Route::get('audit-logs', [
        PlatformAuditLogController::class, 'index'
    ])->name('audit-logs');

    Route::get('audit-logs/export', [
        PlatformAuditLogController::class, 'export'
    ])->name('audit-logs.export');

    // SYSTEM SETTINGS
    Route::get('system-settings', [
        PlatformSystemSettingsController::class, 'index'
    ])->name('system-settings');

    Route::put('system-settings', [
        PlatformSystemSettingsController::class, 'update'
    ])->name('system-settings.update');

    // ORGANIZATION CREATION
    Route::get('organizations/create', [
        PlatformController::class, 'create'
    ])->name('organizations.create');

    Route::post('organizations', [
        PlatformController::class, 'store'
    ])->name('organizations.store');

    // PENDING APPROVAL
    Route::get('pending', [
        PlatformController::class, 'pending'
    ])->name('pending');

    // ADMIN MANAGEMENT
    Route::get('admins', [
        PlatformController::class, 'admins'
    ])->name('admins');

    Route::patch('admins/{user}', [
        PlatformController::class, 'updateAdminStatus'
    ])->name('admins.status');

    // USER MANAGEMENT
    Route::get('users', [
        PlatformUserController::class, 'index'
    ])->name('users.index');

    // ORGANIZATION DETAILS
    Route::get('organizations/{organization}', [
        PlatformController::class, 'show'
    ])->name('organizations.show');

    Route::patch('organizations/{organization}/approve', [
        PlatformController::class, 'approve'
    ])->name('organizations.approve');

    Route::patch('organizations/{organization}/reject', [
        PlatformController::class, 'reject'
    ])->name('organizations.reject');

    Route::patch('organizations/{organization}/deactivate', [
        PlatformController::class, 'deactivate'
    ])->name('organizations.deactivate');

    Route::patch('organizations/{organization}/restore', [
        PlatformController::class, 'restore'
    ])->name('organizations.restore');

    Route::patch('organizations/{organization}/restore-request', [
        PlatformController::class, 'dismissRestoreRequest'
    ])->name('organizations.restore-request.dismiss');

    Route::patch('organizations/{organization}/closure/approve', [
        PlatformController::class, 'approveClosure'
    ])->name('organizations.closure.approve');

    Route::patch('organizations/{organization}/closure/reject', [
        PlatformController::class, 'rejectClosure'
    ])->name('organizations.closure.reject');

    Route::patch('organizations/{organization}/reactivation/approve', [
        PlatformController::class, 'restore'
    ])->name('organizations.reactivation.approve');

    Route::patch('organizations/{organization}/reactivation/reject', [
        PlatformController::class, 'dismissRestoreRequest'
    ])->name('organizations.reactivation.reject');
});

// ADMIN NOTIFICATIONS
Route::middleware([
    'auth',
    'verified',
    'admin.web',
])->group(function () {

    Route::get('notifications', [
        AdminNotificationController::class, 'index'
    ])->name('notifications.index');

    Route::post('notifications/read-all', [
        AdminNotificationController::class, 'markAllRead'
    ])->name('notifications.read-all');

    Route::post('notifications/{notification}/read', [
        AdminNotificationController::class, 'markRead'
    ])->name('notifications.read');

    Route::delete('notifications/{notification}', [
        AdminNotificationController::class, 'destroy'
    ])->name('notifications.destroy');
});

// BARANGAY ADMIN ROUTES
Route::middleware([
    'auth',
    'verified',
    'admin.web',
    'barangay.web',
])->group(function () {

    // ORGANIZATION
    Route::get('organization/archived', [
        \App\Http\Controllers\OrganizationArchiveController::class,
        'show'
    ])->name('organization.archived');

    Route::post('organization/reactivate', [
        \App\Http\Controllers\OrganizationArchiveController::class,
        'reactivate'
    ])->name('organization.reactivate');

    // DASHBOARD
    Route::get('dashboard', [
        DashboardController::class, 'index'
    ])->name('dashboard');

    Route::inertia(
        'admin-guide',
        'help/AdminGuide'
    )->name('admin-guide');

    // ROUTE MANAGEMENT
    Route::get('routes', [
        OrganizationRouteController::class, 'index'
    ])->name('routes.index');

    Route::post('routes', [
        OrganizationRouteController::class, 'store'
    ])->name('routes.store');

    Route::post('routes/{organizationRoute}', [
        OrganizationRouteController::class, 'update'
    ])->name('routes.update');

    Route::patch('routes/{organizationRoute}/status', [
        OrganizationRouteController::class, 'updateStatus'
    ])->name('routes.status.update');

    // BOAT MANAGEMENT
    Route::get('boats', [
        BoatController::class, 'index'
    ])->name('boats.index');

    Route::post('boats', [
        BoatController::class, 'store'
    ])->name('boats.store');

    Route::post('boats/{boat}/operator-account', [
        BoatController::class, 'storeOperatorAccount'
    ])->name('boats.operator-account');

    Route::post('boats/{boat}', [
        BoatController::class, 'update'
    ])->name('boats.update');

    Route::patch('boats/{boat}/status', [
        BoatController::class, 'updateStatus'
    ])->name('boats.status.update');

    Route::patch('boats/{boat}/archive', [
        BoatController::class, 'archive'
    ])->name('boats.archive');

    Route::patch('boats/{boat}/restore', [
        BoatController::class, 'restore'
    ])->name('boats.restore');

    // REGULAR TRIP SCHEDULES
    Route::get('schedules/regular-trip-rotation', [
        RegularTripController::class, 'index'
    ])->name('schedules.regular-trip-rotation');

    Route::post('regular-trips', [
        RegularTripController::class, 'store'
    ])->name('regular-trips.store');

    Route::patch('regular-trips/schedule-mode', [
        RegularTripController::class, 'updateScheduleMode'
    ])->name('regular-trips.schedule-mode');

    Route::get('regular-trips/{regularTrip}', [
        RegularTripController::class, 'show'
    ])->name('regular-trips.show');

    Route::post('regular-trips/{regularTrip}', [
        RegularTripController::class, 'update'
    ])->name('regular-trips.update');

    Route::patch('regular-trips/{regularTrip}/status', [
        RegularTripController::class, 'updateStatus'
    ])->name('regular-trips.status.update');

    Route::delete('regular-trips/{regularTrip}', [
        RegularTripController::class, 'destroy'
    ])->name('regular-trips.destroy');

    Route::post('regular-trips/{regularTrip}/boarding/start', [
        RegularTripController::class, 'startBoarding'
    ])->name('regular-trips.boarding.start');

    Route::post('regular-trips/{regularTrip}/passengers', [
        TripPassengerController::class, 'store'
    ])->name('regular-trips.passengers.store');

    Route::post(
        'regular-trips/{regularTrip}/passengers/{tripPassenger}',
        [TripPassengerController::class, 'update']
    )->name('regular-trips.passengers.update');

    Route::delete(
        'regular-trips/{regularTrip}/passengers/{tripPassenger}',
        [TripPassengerController::class, 'destroy']
    )->name('regular-trips.passengers.destroy');

    // BOAT RENTAL SCHEDULE
    Route::get('schedules/boat-booking-schedule', [
        BoatBookingController::class, 'index'
    ])->name('schedules.boat-booking-schedule');

    // RENTAL BLACKOUTS
    Route::post('rental-blackouts', [
        RentalBlackoutController::class, 'store'
    ])->name('rental-blackouts.store');

    Route::post('rental-blackouts/{rentalBlackout}', [
        RentalBlackoutController::class, 'update'
    ])->name('rental-blackouts.update');

    Route::patch('rental-blackouts/{rentalBlackout}/cancel', [
        RentalBlackoutController::class, 'cancel'
    ])->name('rental-blackouts.cancel');

    // BOAT BOOKINGS
    Route::post('boat-bookings', [
        BoatBookingController::class, 'store'
    ])->name('boat-bookings.store');

    Route::post('boat-bookings/{boatBooking}', [
        BoatBookingController::class, 'update'
    ])->name('boat-bookings.update');

    Route::patch('boat-bookings/{boatBooking}/review', [
        BoatBookingController::class, 'review'
    ])->name('boat-bookings.review');

    Route::post('boat-bookings/{boatBooking}/verify-payment', [
        BoatBookingController::class, 'verifyPayment'
    ])->name('boat-bookings.verify-payment');

    Route::post('boat-bookings/{boatBooking}/reject-payment', [
        BoatBookingController::class, 'rejectPayment'
    ])->name('boat-bookings.reject-payment');

    Route::post('boat-bookings/{boatBooking}/balance-payment', [
        BoatBookingController::class, 'recordBalance'
    ])->name('boat-bookings.balance-payment');

    Route::post('boat-bookings/{boatBooking}/complete', [
        BoatBookingController::class, 'complete'
    ])->name('boat-bookings.complete');

    Route::get('boat-bookings/{boatBooking}/receipt', [
        BoatBookingController::class, 'receipt'
    ])->name('boat-bookings.receipt');

    // PAYMENTS & MY COMMISSION (read the booking payment ledger and rental_commissions)
    Route::get('payments', [PaymentsController::class, 'index'])
        ->name('payments.index');
    Route::get('payments/export', [PaymentsController::class, 'export'])
        ->name('payments.export');

    Route::get('my-commission', [MyCommissionController::class, 'index'])
        ->name('my-commission.index');
    Route::post('my-commission/{rentalCommission}/mark-paid', [
        MyCommissionController::class, 'markPaid',
    ])->name('my-commission.mark-paid');
    Route::get('my-commission/export', [MyCommissionController::class, 'export'])
        ->name('my-commission.export');

    Route::redirect('commissions', '/operator-payouts');

    Route::get('operator-payouts', [CommissionController::class, 'index'])
        ->name('operator-payouts.index');

    // LEGACY COMMISSION ACTIONS (Operator Payout ledger + record payout)
    Route::get('commissions/export', [
        CommissionController::class, 'export'
    ])->name('commissions.export');

    Route::post('commissions/{rentalCommission}/verify', [
        CommissionController::class, 'verify'
    ])->name('commissions.verify');

    Route::post('commissions/{rentalCommission}/reject', [
        CommissionController::class, 'reject'
    ])->name('commissions.reject');

    Route::post('commissions/{rentalCommission}/record-payout', [
        CommissionController::class, 'recordOperatorPayout'
    ])->name('commissions.record-payout');

    Route::post('commissions/{rentalCommission}/record-cash', [
        CommissionController::class, 'recordCash'
    ])->name('commissions.record-cash');

    Route::post('commissions/{rentalCommission}/follow-up', [
        CommissionController::class, 'followUp'
    ])->name('commissions.follow-up');

    Route::post(
        'commissions/operators/{operator}/restrict-exclusive',
        [CommissionController::class, 'restrictOperator']
    )->name('commissions.operators.restrict');

    Route::post(
        'commissions/operators/{operator}/lift-restriction',
        [CommissionController::class, 'liftOperatorRestriction']
    )->name('commissions.operators.lift');

    Route::get('commissions/{rentalCommission}/proof', [
        CommissionController::class, 'proof'
    ])->name('commissions.proof');

    Route::redirect('reports/commissions', '/reports')->name('reports.commissions');

    // BOOKING REQUESTS
    Route::get('bookings/requests', [
        BoatBookingController::class, 'requests'
    ])->name('bookings.requests');

    Route::get('bookings/requests/{boatBooking}', [
        BoatBookingController::class, 'show'
    ])->name('bookings.requests.show');

    // OPERATOR NO-SHOW MANAGEMENT
    Route::get('bookings/no-show-reports', [OperatorNoShowController::class, 'index'])
        ->name('bookings.no-show-reports');

    Route::patch(
        'bookings/no-show-reports/passenger/{boatBooking}',
        [OperatorNoShowController::class, 'acknowledgePassengerNoShow']
    )->name('bookings.no-show-reports.passenger.acknowledge');

    Route::patch(
        'bookings/no-show-reports/{operatorNoShowReport}',
        [OperatorNoShowController::class, 'update']
    )->name('bookings.no-show-reports.update');

    Route::get(
        'bookings/no-show-reports/{operatorNoShowReport}/evidence',
        [OperatorNoShowController::class, 'evidence']
    )->name('bookings.no-show-reports.evidence');

    Route::patch(
        'bookings/passenger-no-show-reports/{passengerNoShowReport}',
        [OperatorNoShowController::class, 'resolvePassengerNoShow']
    )->name('bookings.passenger-no-show-reports.resolve');

    Route::get(
        'bookings/passenger-no-show-reports/{passengerNoShowReport}/evidence',
        [OperatorNoShowController::class, 'passengerEvidence']
    )->name('bookings.passenger-no-show-reports.evidence');

    Route::patch(
        'bookings/refund-requests/{rentalRefundRequest}',
        [\App\Http\Controllers\RentalRefundController::class, 'update']
    )->name('bookings.refund-requests.update');

    // BARANGAY REPORTS
    Route::get('reports', [
        ReportController::class, 'index'
    ])->name('reports.index');

    Route::get('reports/export/boats', [
        ReportController::class, 'exportBoats'
    ])->name('reports.export.boats');

    Route::get('reports/regular-trips', [
        ReportController::class, 'regularTrips'
    ])->name('reports.regular-trips');

    Route::get('reports/manifest/{regularTrip}/print', [
        ReportController::class, 'printManifest'
    ])->name('reports.manifest.print');

    Route::get('reports/manifest/{regularTrip}/pdf', [
        ReportController::class, 'downloadManifest'
    ])->name('reports.manifest.pdf');

    // ANNOUNCEMENTS
    Route::get('announcements', [
        AnnouncementController::class, 'index'
    ])->name('announcements.index');

    Route::post('announcements', [
        AnnouncementController::class, 'store'
    ])->name('announcements.store');

    // WEATHER SUSPENSIONS
    Route::post('weather-suspensions', [
        WeatherSuspensionController::class, 'store'
    ])->name('weather-suspensions.store');

    Route::patch(
        'weather-suspensions/{weatherSuspension}/lift',
        [WeatherSuspensionController::class, 'lift']
    )->name('weather-suspensions.lift');

    Route::patch(
        'weather-suspensions/{weatherSuspension}/extend',
        [WeatherSuspensionController::class, 'extend']
    )->name('weather-suspensions.extend');

    // BOOKING RESCHEDULE
    Route::patch(
        'boat-bookings/{boatBooking}/reschedule',
        [BoatBookingController::class, 'reviewReschedule']
    )->name('boat-bookings.reschedule');

    // USER MANAGEMENT
    Route::get('users', [
        UserController::class, 'index'
    ])->name('users.index');

    Route::post('users', [
        UserController::class, 'store'
    ])->name('users.store');

    Route::post('users/{user}', [
        UserController::class, 'update'
    ])->name('users.update');

    Route::patch('users/{user}/status', [
        UserController::class, 'updateStatus'
    ])->name('users.status.update');

    // TRIP CONFIRMATION
    Route::post(
        'boat-bookings/{boatBooking}/trip-confirmation/request',
        [TripConfirmationController::class, 'sendRequest']
    )->name('boat-bookings.trip-confirmation.request');

    Route::post(
        'boat-bookings/{boatBooking}/trip-confirmation/confirm',
        [TripConfirmationController::class, 'confirmTrip']
    )->name('boat-bookings.trip-confirmation.confirm');

    Route::post(
        'boat-bookings/{boatBooking}/trip-confirmation/cannot-operate',
        [TripConfirmationController::class, 'cannotOperate']
    )->name('boat-bookings.trip-confirmation.cannot-operate');

    Route::post(
        'boat-bookings/{boatBooking}/trip-confirmation/replacement',
        [TripConfirmationController::class, 'proposeReplacement']
    )->name('boat-bookings.trip-confirmation.replacement');

    Route::post(
        'boat-bookings/replacements/{replacement}/approve',
        [TripConfirmationController::class, 'approveReplacement']
    )->name('boat-bookings.replacements.approve');

    Route::post(
        'boat-bookings/{boatBooking}/trip-confirmation/no-replacement',
        [TripConfirmationController::class, 'noReplacement']
    )->name('boat-bookings.trip-confirmation.no-replacement');
});

// Xendit hosted checkout return pages (after GCash payment in browser)
Route::get('/payment/xendit/success', [XenditPaymentReturnController::class, 'success'])
    ->name('payment.xendit.success');
Route::get('/payment/xendit/cancel', [XenditPaymentReturnController::class, 'cancel'])
    ->name('payment.xendit.cancel');

// EXISTING PROFILE SETTINGS
require __DIR__.'/settings.php';

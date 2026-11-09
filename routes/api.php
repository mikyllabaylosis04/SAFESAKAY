
<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoatController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\OperatorNoShowController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OperatorGcashController;
use App\Http\Controllers\Api\OperatorTransactionController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PassengerPaymentController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\TripConfirmationController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\WeatherAdvisoryController;

use App\Http\Controllers\Api\RentalCheckoutPolicyController;
use App\Http\Controllers\Api\XenditCheckoutController;
use App\Http\Controllers\Api\XenditWebhookController;

use App\Http\Controllers\OrganizationRegistrationController;
use Illuminate\Support\Facades\Route;

// ==========================================
// PUBLIC API ROUTES
// ==========================================

Route::get('/organizations', [OrganizationController::class, 'index']);
Route::get('/locations', [OrganizationRegistrationController::class, 'locations']);
Route::get('/boats/{boat}/picture', [BoatController::class, 'picture']);
Route::get('/boats/{boat}/operator-picture', [BoatController::class, 'operatorPicture']);

Route::get('/help', [AuthController::class, 'help']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/verify-reset-code', [AuthController::class, 'verifyResetCode'])->middleware('throttle:10,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// ==========================================
// XENDIT WEBHOOK - SAFESAKAY (TEST MODE)
// ==========================================
// Public endpoint. Authenticate using x-callback-token
// inside XenditWebhookController.

Route::post('/xendit/webhook', [
    XenditWebhookController::class,
    'handle',
])->middleware('throttle:60,1');

// ==========================================
// AUTHENTICATED API ROUTES
// ==========================================

Route::middleware(['auth:sanctum', 'account.not_suspended'])->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/me/photo', [AuthController::class, 'updatePhoto'])->middleware('throttle:10,1');
    Route::patch('/me/name', [AuthController::class, 'updateName']);
    Route::patch('/me/password', [AuthController::class, 'updatePassword']);
    Route::patch('/me/service-area', [AuthController::class, 'updateServiceArea']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/push-tokens', [PushTokenController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/push-tokens', [PushTokenController::class, 'destroy'])->middleware('throttle:20,1');

    Route::get('/boats', [BoatController::class, 'index']);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::get('/bookings/{booking}/receipt', [BookingController::class, 'receipt']);

    Route::get('/commissions', [CommissionController::class, 'index']);
    Route::get('/payments/history', [PassengerPaymentController::class, 'index']);
    Route::get('/bookings/{booking}/feedback', [FeedbackController::class, 'show']);
    Route::post('/bookings/{booking}/feedback', [FeedbackController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/operator/transactions', [OperatorTransactionController::class, 'index']);
    Route::get('/operator/transactions/{booking}', [OperatorTransactionController::class, 'show']);
    Route::get('/commissions/{commission}/proof', [CommissionController::class, 'proof']);

    Route::get('/weather-advisories', [WeatherAdvisoryController::class, 'index']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
});

// ==========================================
// ACTIVE ORGANIZATION API ROUTES
// ==========================================

Route::middleware(['auth:sanctum', 'org.active'])->group(function () {

    // ======================================
    // BOATS
    // ======================================

    Route::get('/boats/{boat}/availability', [BoatController::class, 'availability']);
    Route::patch('/boats/{boat}/status', [BoatController::class, 'updateStatus']);

    // ======================================
    // REGULAR TRIPS
    // ======================================

    Route::get('/trips', [TripController::class, 'index']);
    Route::patch('/trips/{trip}', [TripController::class, 'update']);
    Route::post('/trips/{trip}/passengers', [TripController::class, 'storePassenger']);

    // ======================================
    // BOAT BOOKINGS
    // ======================================

    Route::post('/bookings', [BookingController::class, 'store']);
    Route::patch('/bookings/{booking}', [BookingController::class, 'update']);

    Route::post('/bookings/{booking}/approve', [BookingController::class, 'approve']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::post('/bookings/{booking}/refund-choice', [BookingController::class, 'refundChoice'])->middleware('throttle:10,1');

    Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
    Route::post('/bookings/{booking}/reschedule-review', [BookingController::class, 'reviewReschedule']);

    Route::post('/bookings/{booking}/complete', [BookingController::class, 'complete']);

    // ======================================
    // EXISTING MANUAL GCASH PAYMENTS
    // ======================================

    Route::post('/bookings/{booking}/payment-proof', [BookingController::class, 'submitPaymentProof']);
    Route::post('/bookings/{booking}/verify-payment', [BookingController::class, 'verifyPayment']);
    Route::post('/bookings/{booking}/reject-payment', [BookingController::class, 'rejectPayment']);
    Route::post('/bookings/{booking}/balance-payment', [BookingController::class, 'recordBalance']);

    Route::post('/bookings/{booking}/xendit/checkout', [
        XenditCheckoutController::class,
        'store',
    ])->middleware('throttle:5,1');

    Route::post('/bookings/{booking}/checkout-policy/accept', [
        RentalCheckoutPolicyController::class,
        'accept',
    ])->middleware('throttle:10,1');

    // ======================================
    // OPERATOR NO-SHOW
    // ======================================

    Route::post('/bookings/{booking}/report-no-show', [BookingController::class, 'reportOperatorNoShow']);

    Route::post('/bookings/{booking}/no-show/choose-resolution', [BookingController::class, 'chooseOperatorNoShowResolution']);

    Route::get('/bookings/{booking}/no-show', [OperatorNoShowController::class, 'show']);

    Route::get('/bookings/{booking}/no-show/reschedule-boats', [OperatorNoShowController::class, 'rescheduleBoats']);

    Route::post('/bookings/{booking}/no-show/refund-request', [OperatorNoShowController::class, 'submitRefundRequest']);

    Route::get('/bookings/{booking}/no-show/refund-receipt', [OperatorNoShowController::class, 'refundReceipt']);

    Route::post('/bookings/{booking}/no-show/reschedule-request', [OperatorNoShowController::class, 'submitRescheduleRequest']);

    Route::post('/bookings/{booking}/no-show/reschedule/respond', [OperatorNoShowController::class, 'respondReschedule']);

    // ======================================
    // PASSENGER NO-SHOW
    // ======================================

    Route::post('/bookings/{booking}/passenger-no-show', [BookingController::class, 'markPassengerNoShow']);
    Route::post('/bookings/{booking}/passenger-no-show/dispute', [BookingController::class, 'disputePassengerNoShow'])->middleware('throttle:5,1');

    Route::post('/bookings/{booking}/confirm-availability', [BookingController::class, 'confirmOperatorAvailability']);

    // ======================================
    // TRIP CONFIRMATION
    // ======================================

    Route::get('/operator/scheduled-trips', [TripConfirmationController::class, 'scheduledTrips']);

    Route::post('/bookings/{booking}/trip-confirmation/confirm', [TripConfirmationController::class, 'confirmTrip']);

    Route::post('/bookings/{booking}/trip-confirmation/cannot-operate', [TripConfirmationController::class, 'cannotOperate']);

    Route::post('/bookings/{booking}/meeting-point-arrival', [TripConfirmationController::class, 'confirmArrival']);

    Route::post('/bookings/{booking}/passengers/check-in', [TripConfirmationController::class, 'checkInPassenger']);

    Route::post('/bookings/{booking}/passengers/no-show', [TripConfirmationController::class, 'markPassengersNoShow']);

    Route::post('/boat-booking-replacements/{replacement}/respond', [TripConfirmationController::class, 'respondReplacement']);

    // ======================================
    // COMMISSIONS
    // ======================================

    Route::post('/commissions/{commission}/settle', [CommissionController::class, 'settle']);

    Route::post('/commissions/{commission}/declare-cash', [CommissionController::class, 'declareCash']);

    // ======================================
    // OPERATOR GCASH
    // ======================================

    Route::get('/operator/gcash', [OperatorGcashController::class, 'show']);

    Route::post('/operator/gcash', [OperatorGcashController::class, 'update']);

    Route::get('/operator/gcash/qr', [OperatorGcashController::class, 'qr']);
});

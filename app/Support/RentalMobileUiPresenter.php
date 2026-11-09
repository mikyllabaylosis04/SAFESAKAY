<?php

namespace App\Support;

use App\Enums\BoatBookingStatus;
use App\Enums\BookingPaymentStatus;
use App\Models\BoatBooking;
use App\Models\User;
use App\Services\HostedRentalCheckoutService;
use App\Services\XenditCheckoutService;
use Illuminate\Support\Carbon;

class RentalMobileUiPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forBooking(BoatBooking $booking, ?User $viewer = null): array
    {
        $tripService = app(\App\Services\TripConfirmationService::class);
        $canMarkNoShow = $viewer instanceof User && $viewer->isMobileOperator()
            ? $tripService->canMarkPassengerNoShow($booking, $viewer)
            : false;

        $xenditEnabled = self::xenditEnabled();
        $onlineEnabled = OnlinePaymentProvider::isEnabled();

        return [
            'xendit_enabled' => $xenditEnabled,
            'online_payment_enabled' => $onlineEnabled,
            'down_payment_payee_label' => self::downPaymentPayeeLabel($booking),
            'down_payment_instruction' => self::downPaymentInstruction($booking),
            'awaiting_payment_banner_title' => self::awaitingPaymentBannerTitle($booking),
            'awaiting_payment_banner_message' => self::awaitingPaymentBannerMessage($booking, $viewer),
            'rental_detail_notice' => self::rentalDetailNotice($booking, $viewer),
            'show_important_reminders' => self::showImportantReminders($booking),
            'important_reminders' => self::importantReminders(),
            'show_passenger_no_show_action' => $canMarkNoShow,
            'passenger_no_show_available_at' => self::passengerNoShowOpensAt($booking)?->toIso8601String(),
            'passenger_no_show_available_at_label' => self::passengerNoShowOpensAt($booking)
                ?->timezone('Asia/Manila')
                ->format('M d, Y · g:i A'),
            'passenger_no_show_blocked_reason' => self::passengerNoShowBlockedReason($booking, $viewer, $tripService),
        ];
    }

    public static function showImportantReminders(BoatBooking $booking): bool
    {
        return in_array($booking->status, [
            BoatBookingStatus::Confirmed,
            BoatBookingStatus::Ongoing,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function importantReminders(): array
    {
        return [
            'Arrive 30 minutes before departure.',
            'Wear life jackets at all times.',
            'Follow the instructions of the boat operator.',
        ];
    }

    public static function downPaymentPayeeLabel(BoatBooking $booking): string
    {
        if ($booking->paysOperatorGcash()) {
            return 'Boat operator GCash (legacy booking)';
        }

        return 'SafeSakay';
    }

    public static function downPaymentInstruction(BoatBooking $booking): string
    {
        if ($booking->paysOperatorGcash()) {
            return 'This legacy booking uses the operator GCash account for the down payment.';
        }

        if (self::xenditEnabled()) {
            return 'Pay with GCash through Xendit in SafeSakay, or send the down payment to the barangay admin GCash account shown below and upload your receipt. Down payment is non-refundable.';
        }

        return 'Send the down payment to the barangay admin GCash account shown below, then upload your GCash receipt in SafeSakay. Down payment is non-refundable.';
    }

    private static function xenditEnabled(): bool
    {
        return (bool) config('services.xendit.enabled', false);
    }

    public static function awaitingPaymentBannerTitle(BoatBooking $booking): ?string
    {
        if (
            $booking->status === BoatBookingStatus::AwaitingPayment
            && self::hasOpenOnlineCheckout($booking)
        ) {
            return self::xenditEnabled()
                ? 'Xendit checkout pending'
                : 'Online checkout pending';
        }

        return match (true) {
            $booking->status === BoatBookingStatus::AwaitingPayment
                && $booking->paymentStatus() === BookingPaymentStatus::ForVerification => 'Waiting for admin verification',
            $booking->status === BoatBookingStatus::AwaitingPayment
                && $booking->paymentStatus() === BookingPaymentStatus::Unpaid => 'Waiting for your payment',
            default => null,
        };
    }

    public static function awaitingPaymentBannerMessage(BoatBooking $booking, ?User $viewer): ?string
    {
        if ($booking->status !== BoatBookingStatus::AwaitingPayment) {
            return null;
        }

        if ($booking->paymentStatus() === BookingPaymentStatus::ForVerification) {
            if ($viewer instanceof User && $viewer->isMobileOperator()) {
                return 'The passenger uploaded a GCash down payment receipt. Waiting for barangay admin to verify the down payment before this rental is confirmed.';
            }

            return 'Your GCash receipt was submitted. Waiting for barangay admin to verify your down payment.';
        }

        if ($booking->paymentStatus() === BookingPaymentStatus::Unpaid) {
            if (self::hasOpenOnlineCheckout($booking)) {
                return self::xenditEnabled()
                    ? 'Your Xendit GCash checkout is open. Complete payment in the hosted checkout page, then return to SafeSakay and refresh your booking status.'
                    : 'Your online GCash checkout is open. Complete payment in the checkout page, then return to SafeSakay to refresh your booking status.';
            }

            if ($viewer instanceof User && $viewer->isMobileOperator()) {
                return 'You accepted this rental. The passenger must pay the down payment to barangay admin GCash and upload proof in SafeSakay.';
            }

            return self::downPaymentInstruction($booking);
        }

        return null;
    }

    private static function hasOpenOnlineCheckout(BoatBooking $booking): bool
    {
        if (self::xenditEnabled()) {
            return app(XenditCheckoutService::class)->hasBlockingPendingCheckout($booking);
        }

        return app(HostedRentalCheckoutService::class)->hasAnyBlockingPendingCheckout($booking);
    }

    /**
     * @return array{title: string, message: string, tone: string}|null
     */
    public static function rentalDetailNotice(BoatBooking $booking, ?User $viewer): ?array
    {
        if ($booking->status === BoatBookingStatus::AwaitingPayment
            && $booking->paymentStatus() === BookingPaymentStatus::ForVerification) {
            $forOperator = $viewer instanceof User && $viewer->isMobileOperator();

            return [
                'tone' => 'info',
                'title' => $forOperator ? 'Down payment for verification' : 'Payment under review',
                'message' => $forOperator
                    ? 'Passenger proof is on file. Barangay admin is verifying the down payment — you will be notified when the trip is confirmed.'
                    : 'Barangay admin is verifying your GCash down payment. You will be notified once it is confirmed.',
            ];
        }

        return null;
    }

    public static function passengerNoShowOpensAt(BoatBooking $booking): ?Carbon
    {
        return app(\App\Services\TripConfirmationService::class)->waitingPeriodEndsAt($booking);
    }

    public static function passengerNoShowBlockedReason(
        BoatBooking $booking,
        ?User $viewer,
        ?\App\Services\TripConfirmationService $tripService = null,
    ): ?string {
        if (! $viewer instanceof User || ! $viewer->isMobileOperator()) {
            return null;
        }

        if (! in_array($booking->status, [BoatBookingStatus::Confirmed, BoatBookingStatus::Ongoing], true)) {
            return null;
        }

        $tripService ??= app(\App\Services\TripConfirmationService::class);

        if ($tripService->canMarkPassengerNoShow($booking, $viewer)) {
            return null;
        }

        return app(\App\Services\PassengerNoShowService::class)->blockedReason($booking, $viewer, false)
            ?? 'Passenger no-show is not available for this trip right now.';
    }
}

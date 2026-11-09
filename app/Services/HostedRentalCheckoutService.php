<?php

namespace App\Services;

use App\Models\BoatBooking;
use App\Models\RentalPayment;
use App\Support\OnlinePaymentProvider;
use Illuminate\Support\Carbon;

class HostedRentalCheckoutService
{
    public function __construct(
        private readonly string $provider,
    ) {}

    public static function forActive(): self
    {
        return new self(OnlinePaymentProvider::active() ?? 'xendit');
    }

    public static function forProvider(string $provider): self
    {
        return new self($provider);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function checkoutTtlMinutes(): int
    {
        $seconds = (int) config('services.xendit.session_duration_seconds', 7200);

        return max(15, min((int) ceil($seconds / 60), 24 * 60));
    }

    public function expireStaleCheckouts(BoatBooking $booking): void
    {
        $now = now();

        RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', $this->provider)
            ->where('status', 'pending')
            ->whereNotNull('checkout_expires_at')
            ->where('checkout_expires_at', '<', $now)
            ->update([
                'status' => 'expired',
                'checkout_url' => null,
            ]);
    }

    public function expireAllStaleCheckouts(BoatBooking $booking): void
    {
        foreach (OnlinePaymentProvider::ledgerProviders() as $provider) {
            self::forProvider($provider)->expireStaleCheckouts($booking);
        }
    }

    public function expireActivePendingCheckouts(
        BoatBooking $booking,
        string $status = 'expired'
    ): void {
        RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', $this->provider)
            ->where('status', 'pending')
            ->update([
                'status' => $status,
                'checkout_url' => null,
            ]);
    }

    public function activePendingCheckout(
        BoatBooking $booking
    ): ?RentalPayment {
        $this->expireStaleCheckouts($booking);

        return RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', $this->provider)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();
    }

    public function hasBlockingPendingCheckout(
        BoatBooking $booking
    ): bool {
        return $this->activePendingCheckout($booking) !== null;
    }

    public function hasAnyBlockingPendingCheckout(BoatBooking $booking): bool
    {
        $this->expireAllStaleCheckouts($booking);

        foreach (OnlinePaymentProvider::ledgerProviders() as $provider) {
            if (self::forProvider($provider)->hasBlockingPendingCheckout($booking)) {
                return true;
            }
        }

        return false;
    }

    public function checkoutExpiresAt(): Carbon
    {
        return now()->addMinutes($this->checkoutTtlMinutes());
    }

    public function paidOnlinePayment(
        BoatBooking $booking,
        ?string $paymentType = null,
    ): ?RentalPayment {
        $query = RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', $this->provider)
            ->where('status', 'paid')
            ->orderByDesc('id');

        if ($paymentType !== null) {
            $query->where('payment_type', $paymentType);
        }

        return $query->first();
    }

    public function requiresReconciliation(
        BoatBooking $booking
    ): bool {
        return RentalPayment::query()
            ->where('boat_booking_id', $booking->id)
            ->where('provider', $this->provider)
            ->where('status', 'requires_reconciliation')
            ->exists();
    }

    public function requiresAnyReconciliation(BoatBooking $booking): bool
    {
        foreach (OnlinePaymentProvider::ledgerProviders() as $provider) {
            if (self::forProvider($provider)->requiresReconciliation($booking)) {
                return true;
            }
        }

        return false;
    }
}

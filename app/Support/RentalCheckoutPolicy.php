<?php

namespace App\Support;

use App\Models\BoatBooking;
use App\Models\RentalCheckoutPolicyAcceptance;
use App\Models\User;

final class RentalCheckoutPolicy
{
    public const VERSION = '2026-11-07';

    /**
     * @return array<string, mixed>
     */
    public static function mobilePayload(BoatBooking $booking, ?User $user): array
    {
        $booking->loadMissing('organization');

        $accepted = $user instanceof User
            ? self::hasCurrentAcceptance($booking, $user)
            : false;

        return [
            'version' => self::VERSION,
            'title' => 'Cancellation, Refund & No-Show Policy',
            'summary' => self::summary(),
            'sections' => self::sections($booking),
            'accepted' => $accepted,
            'accepted_at_label' => $accepted && $user instanceof User
                ? RentalCheckoutPolicyAcceptance::query()
                    ->where('user_id', $user->id)
                    ->where('boat_booking_id', $booking->id)
                    ->where('policy_version', self::VERSION)
                    ->orderByDesc('accepted_at')
                    ->value('accepted_at')
                    ?->timezone('Asia/Manila')
                    ->format('M d, Y · g:i A')
                : null,
        ];
    }

    public static function hasCurrentAcceptance(BoatBooking $booking, User $user): bool
    {
        return RentalCheckoutPolicyAcceptance::query()
            ->where('user_id', $user->id)
            ->where('boat_booking_id', $booking->id)
            ->where('policy_version', self::VERSION)
            ->exists();
    }

    public static function recordAcceptance(BoatBooking $booking, User $user, ?string $ip): RentalCheckoutPolicyAcceptance
    {
        return RentalCheckoutPolicyAcceptance::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'boat_booking_id' => $booking->id,
                'policy_version' => self::VERSION,
            ],
            [
                'accepted_at' => now(),
                'ip_address' => $ip,
            ],
        );
    }

    public static function summary(): string
    {
        return 'Review how down payments, full payments, no-shows, weather holds, and remaining balances are handled before you pay online.';
    }

    /**
     * @return list<array{heading: string, body: string}>
     */
    private static function sections(BoatBooking $booking): array
    {
        return self::sectionsFor($booking->organization?->name);
    }

    /**
     * @return list<array{heading: string, body: string}>
     */
    public static function sectionsFor(?string $organizationName): array
    {
        $org = filled($organizationName) ? $organizationName : 'your barangay organization';

        return [
            [
                'heading' => '1. Downpayment is non-refundable',
                'body' => 'A downpayment is required to confirm your rental with '.$org.'. Once the rental is confirmed, the downpayment is not refunded if you cancel, change your mind, or do not show up.',
            ],
            [
                'heading' => '2. If you paid in full and cancel',
                'body' => 'Only the downpayment portion is kept. The rest of what you paid online is refunded to the same GCash account.',
            ],
            [
                'heading' => '3. Passenger no-show',
                'body' => 'If you do not arrive by the check-in time and the no-show is verified, the downpayment is not refunded.',
            ],
            [
                'heading' => '4. Bad weather',
                'body' => 'If unsafe weather puts your rental on hold, you can reschedule to another date at no extra charge. Weather holds are reschedule only and are not refunded.',
            ],
            [
                'heading' => '5. Operator cannot operate or the admin cancels',
                'body' => 'You will not lose your payment. You can choose a full refund to your GCash or reschedule to a new date.',
            ],
            [
                'heading' => '6. Remaining balance',
                'body' => 'For downpayment bookings, the remaining balance may be paid through GCash online or cash on the day of the trip. Payment must be verified and recorded by authorized staff before departure. Cash payments are never marked paid automatically in the system.',
            ],
        ];
    }
}

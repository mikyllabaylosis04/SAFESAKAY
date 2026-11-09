<?php

namespace App\Support;

use App\Models\User;

/**
 * Barangay admin notification bell preferences. Safety alerts stay on; turning off any other
 * category only hides it from the bell. The record is still shown on its page (Rental Requests,
 * Payments, Announcements) so no workflow depends on the bell.
 */
final class AdminNotificationPreferences
{
    /**
     * @return array<string, array{label: string, description: string, locked: bool}>
     */
    public static function catalog(): array
    {
        return [
            'new_rental_requests' => [
                'label' => 'New Rental Requests',
                'description' => 'A passenger sends a new private rental request.',
                'locked' => false,
            ],
            'operator_approval' => [
                'label' => 'Operator Approval',
                'description' => 'An operator accepts or declines a rental request.',
                'locked' => false,
            ],
            'upcoming_trip_reminders' => [
                'label' => 'Upcoming Trip Reminders',
                'description' => 'Confirmed rentals happening today or tomorrow.',
                'locked' => false,
            ],
            'operator_readiness' => [
                'label' => 'Operator Readiness Confirmation',
                'description' => 'Tomorrow’s rentals that the operator has not confirmed yet.',
                'locked' => false,
            ],
            'passenger_no_show' => [
                'label' => 'Passenger No-Show',
                'description' => 'An operator reports a passenger no-show or a passenger disputes it.',
                'locked' => true,
            ],
            'operator_no_show' => [
                'label' => 'Boat / Operator No-Show',
                'description' => 'A passenger reports that the boat or operator did not arrive.',
                'locked' => true,
            ],
            'weather_alerts' => [
                'label' => 'Weather Alerts',
                'description' => 'Weather holds, suspensions, and weather announcements.',
                'locked' => true,
            ],
            'payment_confirmation' => [
                'label' => 'Payment Confirmation',
                'description' => 'Passenger payments that need checking or were confirmed.',
                'locked' => false,
            ],
            'cancellation_refund' => [
                'label' => 'Cancellation and Refund Updates',
                'description' => 'Cancelled rentals, reschedule requests, and refund status changes.',
                'locked' => false,
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function for(?User $user): array
    {
        $saved = is_array($user?->notification_preferences) ? $user->notification_preferences : [];

        return collect(self::catalog())
            ->mapWithKeys(fn (array $meta, string $key) => [
                $key => $meta['locked'] ? true : (bool) ($saved[$key] ?? true),
            ])
            ->all();
    }

    public static function enabled(?User $user, string $key): bool
    {
        return self::for($user)[$key] ?? true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public static function normalize(array $input): array
    {
        return collect(self::catalog())
            ->mapWithKeys(fn (array $meta, string $key) => [
                $key => $meta['locked'] ? true : filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOL),
            ])
            ->all();
    }
}

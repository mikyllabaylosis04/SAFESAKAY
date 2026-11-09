<?php

namespace App\Support;

use App\Models\BoatBooking;

final class RentalCheckoutContext
{
    /**
     * @return array<string, mixed>
     */
    public static function forBooking(BoatBooking $booking): array
    {
        $booking->loadMissing(['organization', 'boat.operator']);

        $organizationName = trim((string) ($booking->organization?->displayName() ?? $booking->organization?->name ?? ''));

        if ($organizationName === '') {
            $organizationName = (string) config('app.name', 'SafeSakay');
        }

        $operatorName = trim((string) (
            $booking->boat->operator?->full_name
            ?? $booking->boat->operator_name
            ?? ''
        ));

        return [
            'payment_receiver_name' => 'SafeSakay',
            'payment_receiver_label' => 'SafeSakay for '.$organizationName,
            'online_collection_note' => 'Online GCash payments are sent to SafeSakay through Xendit for '.$organizationName.'. SafeSakay releases the boat operator share after the trip, minus the barangay commission.',
            'operator_name' => $operatorName !== '' ? $operatorName : null,
            'billed_to' => $booking->customer_name,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Boat;
use App\Models\OrganizationRoute;

class RentalPricingService
{
    /**
     * @return array{
     *     rental_price: string|null,
     *     down_payment_percent: string|null,
     *     down_payment_amount: string|null,
     *     remaining_balance: string|null
     * }
     */
    public function snapshotForDestination(?Boat $boat, string $destination): array
    {
        $empty = [
            'rental_price' => null,
            'down_payment_percent' => null,
            'down_payment_amount' => null,
            'remaining_balance' => null,
        ];

        if (! $boat) {
            return $empty;
        }

        $boat->loadMissing('rentalDestinations');

        $route = $boat->rentalDestinations
            ->first(function (OrganizationRoute $route) use ($destination, $boat) {
                return $route->isExclusiveBoatRental()
                    && $route->is_active
                    && (int) $route->organization_id === (int) $boat->organization_id
                    && $route->destination === $destination;
            });

        if (! $route) {
            return $empty;
        }

        $breakdown = $route->priceBreakdown();

        if ($breakdown === null) {
            return $empty;
        }

        return [
            'rental_price' => number_format($breakdown['rental_price'], 2, '.', ''),
            'down_payment_percent' => number_format($breakdown['down_payment_percent'], 2, '.', ''),
            'down_payment_amount' => number_format($breakdown['down_payment_amount'], 2, '.', ''),
            'remaining_balance' => number_format($breakdown['remaining_balance'], 2, '.', ''),
        ];
    }
}

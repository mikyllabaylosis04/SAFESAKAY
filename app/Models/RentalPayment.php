<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalPayment extends Model
{
    protected $table = 'rental_payments';

    protected $fillable = [
        'organization_id',
        'boat_booking_id',
        'provider',
        'payment_type',
        'amount',
        'commission_amount',
        'operator_amount',
        'provider_fee',
        'currency',
        'status',
        'checkout_session_id',
        'provider_payment_id',
        'checkout_url',
        'checkout_expires_at',
        'paid_at',
        'refund_id',
        'refund_status',
        'refunded_amount',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'boat_booking_id' => 'integer',
            'amount' => 'integer',
            'commission_amount' => 'integer',
            'operator_amount' => 'integer',
            'provider_fee' => 'integer',
            'paid_at' => 'datetime',
            'checkout_expires_at' => 'datetime',
            'refunded_amount' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    public function isRefunded(): bool
    {
        return in_array($this->refund_status, ['succeeded', 'manual'], true);
    }

    /** Xendit payment request id (pr-...) the refund API needs. */
    public function paymentRequestId(): ?string
    {
        foreach ([$this->checkout_session_id, $this->provider_payment_id] as $id) {
            if (is_string($id) && str_starts_with($id, 'pr-')) {
                return $id;
            }
        }

        return null;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(BoatBooking::class, 'boat_booking_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [
            'paid',
            'requires_reconciliation',
        ], true);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function requiresReconciliation(): bool
    {
        return $this->status === 'requires_reconciliation';
    }
}

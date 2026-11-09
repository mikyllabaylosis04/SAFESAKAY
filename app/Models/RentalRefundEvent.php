<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalRefundEvent extends Model
{
    protected $fillable = [
        'rental_refund_request_id',
        'event',
        'title',
        'detail',
        'actor_user_id',
        'actor_name',
    ];

    /**
     * @return BelongsTo<RentalRefundRequest, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(RentalRefundRequest::class, 'rental_refund_request_id');
    }
}

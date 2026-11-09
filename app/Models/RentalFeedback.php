<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'boat_booking_id', 'boat_id', 'user_id', 'rating', 'tags', 'comment'])]
class RentalFeedback extends Model
{
    protected $table = 'rental_feedback';

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'tags' => 'array',
        ];
    }

    /**
     * @return BelongsTo<BoatBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(BoatBooking::class, 'boat_booking_id');
    }

    /**
     * @return BelongsTo<Boat, $this>
     */
    public function boat(): BelongsTo
    {
        return $this->belongsTo(Boat::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->boat_booking_id,
            'rating' => $this->rating,
            'tags' => $this->tags ?? [],
            'comment' => $this->comment,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'submitted_at_label' => $this->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
        ];
    }
}

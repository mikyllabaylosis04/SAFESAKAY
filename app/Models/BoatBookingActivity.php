<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $boat_booking_id
 * @property string $event
 * @property string $title
 * @property int|null $actor_id
 * @property string|null $actor_name
 */
#[Fillable([
    'boat_booking_id',
    'event',
    'title',
    'actor_id',
    'actor_name',
    'metadata',
])]
class BoatBookingActivity extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array{id: int, event: string, title: string, actor_name: string|null, occurred_at: string, occurred_at_label: string}
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'title' => $this->title,
            'actor_name' => $this->actor_name,
            'occurred_at' => $this->created_at?->toIso8601String(),
            'occurred_at_label' => $this->created_at?->copy()->timezone('Asia/Manila')->format('M d, Y · g:i A'),
            'metadata' => $this->metadata,
        ];
    }
}

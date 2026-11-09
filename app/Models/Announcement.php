<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $title
 * @property string $body
 * @property string $category
 * @property list<string> $audience
 * @property int $recipient_count
 * @property Carbon|null $published_at
 */
#[Fillable([
    'organization_id',
    'user_id',
    'title',
    'body',
    'category',
    'audience',
    'recipient_count',
    'published_at',
])]
class Announcement extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'recipient_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasOne<WeatherSuspension, $this>
     */
    public function weatherSuspension(): HasOne
    {
        return $this->hasOne(WeatherSuspension::class);
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'weather' => 'Weather',
            'delay' => 'Trip Delay',
            'resume' => 'Resume operations',
            'cancellation' => 'Trip Cancellation',
            'emergency' => 'Emergency Advisory',
            default => 'General Notice',
        };
    }
}

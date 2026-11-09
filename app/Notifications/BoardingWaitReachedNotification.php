<?php

namespace App\Notifications;

use App\Models\RegularTrip;
use Illuminate\Notifications\Notification;

class BoardingWaitReachedNotification extends Notification
{
    public function __construct(public RegularTrip $trip) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->trip->loadMissing('boat');

        $boatName = $this->trip->boat?->name ?: 'A boat';
        $minutes = $this->trip->boardingWaitMinutes();

        return [
            'kind' => 'system',
            'event' => 'boarding_wait_reached',
            'booking_id' => null,
            'trip_id' => $this->trip->id,
            'title' => 'Waiting limit reached',
            'body' => "{$boatName} reached the {$minutes}-minute boarding wait. Review passengers and confirm departure if it is safe. The boat will not leave automatically.",
        ];
    }
}

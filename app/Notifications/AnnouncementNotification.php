<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Notifications\Notification;

class AnnouncementNotification extends Notification
{
    public function __construct(public Announcement $announcement) {}

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
        return [
            'kind' => 'announcement',
            'event' => $this->announcement->category ?: 'general',
            'announcement_id' => $this->announcement->id,
            'booking_id' => null,
            'title' => $this->announcement->title,
            'body' => $this->announcement->body,
        ];
    }
}

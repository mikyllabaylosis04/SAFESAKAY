<?php

namespace App\Notifications;

use App\Enums\BoatStatus;
use App\Models\Boat;
use App\Models\User;
use Illuminate\Notifications\Notification;

class BoatStatusReportNotification extends Notification
{
    public function __construct(
        public Boat $boat,
        public User $operator,
        public BoatStatus $status,
    ) {}

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
        $boatName = $this->boat->name;
        $boatCode = $this->boat->code ?: 'Boat';
        $operatorName = $this->operator->name;
        $label = $this->status->label();

        return [
            'kind' => $this->status === BoatStatus::Maintenance ? 'maintenance' : 'system',
            'event' => $this->status->value,
            'booking_id' => null,
            'title' => 'Operator boat update',
            'body' => "{$operatorName} sent {$boatName} ({$boatCode}) as {$label}.",
        ];
    }
}

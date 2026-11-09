<?php

namespace App\Notifications;

use App\Enums\OperatorGcashStatus;
use App\Models\OperatorGcashAccount;
use Illuminate\Notifications\Notification;

class OperatorGcashReviewedNotification extends Notification
{
    public function __construct(public OperatorGcashAccount $account) {}

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
        $approved = $this->account->status === OperatorGcashStatus::Approved;

        return [
            'kind' => 'system',
            'event' => $approved ? 'gcash_approved' : 'gcash_rejected',
            'booking_id' => null,
            'title' => $approved ? 'GCash account approved' : 'GCash account rejected',
            'body' => $approved
                ? 'Your GCash payment account is approved. Passengers can now pay the down payment to this account when you accept a rental.'
                : 'Your GCash payment account was rejected. Update your details in GCash Payment Settings and submit again.',
        ];
    }
}

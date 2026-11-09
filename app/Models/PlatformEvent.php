<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property int|null $organization_id
 * @property string $organization_name
 * @property string $action
 * @property string|null $note
 * @property Carbon $created_at
 */
class PlatformEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'organization_id',
        'organization_name',
        'action',
        'note',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return list<string>
     */
    public static function accessHistoryActions(): array
    {
        return [
            'onboarded',
            'approved',
            'suspended',
            'deactivated',
            'restored',
            'archived',
            'restore_requested',
            'restore_request_dismissed',
            'closure_requested',
            'closure_rejected',
            'reactivation_requested',
            'reactivation_rejected',
            'billing_locked',
            'rejected',
        ];
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'approved' => 'Approved',
            'suspended' => 'Suspended',
            'deactivated' => 'Deactivated',
            'admin_deactivated' => 'Admin deactivated',
            'admin_restored' => 'Admin restored',
            'restored' => 'Restored',
            'restore_requested' => 'Restore requested',
            'restore_request_dismissed' => 'Restore request dismissed',
            'closure_requested' => 'Closure requested',
            'closure_rejected' => 'Closure rejected',
            'archived' => 'Archived',
            'reactivation_requested' => 'Reactivation requested',
            'reactivation_rejected' => 'Reactivation rejected',
            'rejected' => 'Rejected',
            'onboarded' => 'Onboarded',
            'billing_locked' => 'Payment violation',
            'subscription_paid' => 'Monthly plan paid',
            'subscription_rejected' => 'Monthly plan rejected',
            'subscription_waived' => 'Monthly plan waived',
            'settings_updated' => 'Settings updated',
            default => ucfirst($this->action),
        };
    }
}

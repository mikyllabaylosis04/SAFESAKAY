<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property bool $commission_enabled
 * @property string $commission_percentage
 * @property Carbon|null $effective_date
 * @property int $settlement_due_days
 */
class OrganizationCommissionSetting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'commission_enabled',
        'commission_percentage',
        'effective_date',
        'settlement_due_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_enabled' => 'boolean',
            'effective_date' => 'date',
            'settlement_due_days' => 'integer',
        ];
    }

    public function settlementDueDays(): int
    {
        $days = (int) ($this->settlement_due_days ?? 3);

        return max(1, min(30, $days));
    }

    public function percentageAmount(): float
    {
        return round(max(0, min(100, (float) $this->commission_percentage)), 2);
    }
}

<?php

namespace App\Models;

use App\Enums\PlatformInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $period
 * @property Carbon $period_start
 * @property string $amount
 * @property PlatformInvoiceStatus $status
 * @property string|null $gcash_reference
 * @property string|null $proof_path
 * @property Carbon|null $submitted_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $rejection_reason
 */
class PlatformMonthlyInvoice extends Model
{
    protected $fillable = [
        'organization_id',
        'period',
        'period_start',
        'due_on',
        'amount',
        'status',
        'gcash_reference',
        'proof_path',
        'submitted_at',
        'verified_by',
        'verified_at',
        'rejection_reason',
        'warning_sent_at',
        'locked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'due_on' => 'date',
            'status' => PlatformInvoiceStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'warning_sent_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function canSubmit(): bool
    {
        return in_array($this->status, [
            PlatformInvoiceStatus::Due,
            PlatformInvoiceStatus::Rejected,
            PlatformInvoiceStatus::Overdue,
            PlatformInvoiceStatus::Waived,
        ], true);
    }

    public function canVerify(): bool
    {
        return $this->status === PlatformInvoiceStatus::AwaitingVerification
            && filled($this->proof_path);
    }

    public function periodLabel(): string
    {
        return $this->period_start?->format('F Y') ?? $this->period;
    }
}

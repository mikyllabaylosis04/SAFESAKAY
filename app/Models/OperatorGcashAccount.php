<?php

namespace App\Models;

use App\Enums\OperatorGcashStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $operator_id
 * @property int|null $organization_id
 * @property string $account_name
 * @property string $mobile_number
 * @property string $qr_path
 * @property OperatorGcashStatus $status
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $rejection_reason
 */
#[Fillable([
    'operator_id',
    'organization_id',
    'account_name',
    'mobile_number',
    'qr_path',
    'status',
    'submitted_at',
    'reviewed_at',
    'reviewed_by',
    'rejection_reason',
])]
class OperatorGcashAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OperatorGcashStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function qrUrl(): ?string
    {
        return public_storage_url($this->qr_path);
    }

    public function absoluteQrUrl(): ?string
    {
        $relative = $this->qrUrl();

        if (! $relative) {
            return null;
        }

        $forwardedHost = request()->headers->get('X-Forwarded-Host');
        $forwardedProto = request()->headers->get('X-Forwarded-Proto', request()->getScheme());

        if (is_string($forwardedHost) && $forwardedHost !== '') {
            return $forwardedProto.'://'.$forwardedHost.$relative;
        }

        return request()->getSchemeAndHttpHost().$relative;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'account_name' => $this->account_name,
            'mobile_number' => $this->mobile_number,
            'qr_url' => $this->absoluteQrUrl(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submitted_at_label' => $this->submitted_at?->format('M d, Y · g:i A'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public function duplicateQrTo(string $directory): string
    {
        $extension = pathinfo($this->qr_path, PATHINFO_EXTENSION) ?: 'png';
        $target = trim($directory, '/').'/'.uniqid('qr_', true).'.'.$extension;

        if (Storage::disk('public')->exists($this->qr_path)) {
            Storage::disk('public')->copy($this->qr_path, $target);
        }

        return $target;
    }
}

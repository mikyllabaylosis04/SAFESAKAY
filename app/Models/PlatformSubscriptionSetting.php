<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property bool $billing_enabled
 * @property string $monthly_fee
 * @property string|null $gcash_account_name
 * @property string|null $gcash_qr_path
 * @property Carbon|null $updated_at
 */
class PlatformSubscriptionSetting extends Model
{
    protected $fillable = [
        'billing_enabled',
        'monthly_fee',
        'gcash_account_name',
        'gcash_qr_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'billing_enabled' => false,
                'monthly_fee' => '0.00',
            ],
        );
    }

    public function feeAmount(): float
    {
        return round(max(0, (float) $this->monthly_fee), 2);
    }

    public function gcashQrUrl(): ?string
    {
        return public_storage_url($this->gcash_qr_path);
    }
}

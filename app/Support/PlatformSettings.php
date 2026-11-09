<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PlatformSettings
{
    private const CACHE_KEY = 'platform.system_settings';

    public const SESSION_TIMEOUTS = [15, 30, 60, 120];

    public const LOGIN_ATTEMPTS = [3, 5, 10];

    public const DEFAULTS = [
        'platform_name' => 'SafeSakay',
        'platform_description' => 'A Web-Based Pump Boat Monitoring System',
        'logo_path' => null,
        'notify_organization_registration' => true,
        'notify_approval_requests' => true,
        'notify_system_activity' => true,
        'session_timeout' => 120,
        'max_login_attempts' => 5,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, function (): array {
                $settings = SystemSetting::query()->find(1);

                if (! $settings) {
                    return self::DEFAULTS;
                }

                return [
                    ...self::DEFAULTS,
                    ...collect($settings->only(array_keys(self::DEFAULTS)))
                        ->reject(fn ($value) => $value === null)
                        ->all(),
                ];
            });
        } catch (Throwable) {
            return self::DEFAULTS;
        }
    }

    public static function model(): SystemSetting
    {
        return SystemSetting::query()->firstOrCreate(['id' => 1], self::DEFAULTS);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function sessionTimeout(): int
    {
        $minutes = (int) self::all()['session_timeout'];

        return in_array($minutes, self::SESSION_TIMEOUTS, true) ? $minutes : self::DEFAULTS['session_timeout'];
    }

    public static function maxLoginAttempts(): int
    {
        $attempts = (int) self::all()['max_login_attempts'];

        return in_array($attempts, self::LOGIN_ATTEMPTS, true) ? $attempts : self::DEFAULTS['max_login_attempts'];
    }

    public static function notifies(string $key): bool
    {
        return (bool) (self::all()['notify_'.$key] ?? true);
    }

    /**
     * @return array{name: string, description: string|null, logo_url: string|null}
     */
    public static function branding(): array
    {
        $settings = self::all();
        $logo = $settings['logo_path'] ?? null;

        return [
            'name' => (string) ($settings['platform_name'] ?: self::DEFAULTS['platform_name']),
            'description' => $settings['platform_description'] ?? null,
            'logo_url' => $logo ? Storage::disk('public')->url($logo) : null,
        ];
    }
}

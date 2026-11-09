<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'platform_name',
        'platform_description',
        'logo_path',
        'timezone',
        'date_format',
        'time_format',
        'language',
        'notify_organization_registration',
        'notify_approval_requests',
        'notify_system_activity',
        'notify_security_alerts',
        'session_timeout',
        'max_login_attempts',
        'require_strong_passwords',
        'enable_activity_logging',
    ];

    protected $casts = [
        'notify_organization_registration' => 'boolean',
        'notify_approval_requests' => 'boolean',
        'notify_system_activity' => 'boolean',
        'notify_security_alerts' => 'boolean',
        'session_timeout' => 'integer',
        'max_login_attempts' => 'integer',
        'require_strong_passwords' => 'boolean',
        'enable_activity_logging' => 'boolean',
    ];
}

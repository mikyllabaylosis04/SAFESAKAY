<?php

namespace App\Support;

class DemoDatabaseGuard
{
    public static function isProtectedMysql(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        if (config('database.default') !== 'mysql') {
            return false;
        }

        $database = (string) config('database.connections.mysql.database', '');

        return $database !== '' && $database === (string) config('safesakay.demo_database');
    }
}

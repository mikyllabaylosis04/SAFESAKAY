<?php

return [
    'boarding_wait_minutes' => max(1, (int) env('SAFESAKAY_BOARDING_WAIT_MINUTES', 30)),
    'super_admin_email' => env('SUPER_ADMIN_EMAIL', 'superadmin@safesakay.com'),
    /** On local: ask before migrate:fresh, db:wipe, etc. */
    'confirm_destructive_db_commands' => env('CONFIRM_DESTRUCTIVE_DB', true),

    /**
     * Must be true to run destructive DB commands non-interactively or with --force on local
     * (e.g. you intentionally run migrate:fresh in PowerShell). Leave false while using Cursor Agent.
     */
    'allow_destructive_db_commands' => env('ALLOW_DESTRUCTIVE_DB', false),

    /** MySQL database name treated as your real demo data on local. */
    'demo_database' => env('SAFESAKAY_DEMO_DATABASE', env('DB_DATABASE', 'safesakayvue')),

    /**
     * Must be true before running Restore* seeders or restore-demo-from-snapshots.php via automation.
     * Leave false while using Cursor Agent.
     */
    'allow_restore_seeders' => env('ALLOW_RESTORE_SCRIPTS', false),
];

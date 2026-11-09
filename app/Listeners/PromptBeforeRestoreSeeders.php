<?php

namespace App\Listeners;

use App\Support\DemoDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Style\SymfonyStyle;

class PromptBeforeRestoreSeeders
{
    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'db:seed' || ! DemoDatabaseGuard::isProtectedMysql()) {
            return;
        }

        if (config('safesakay.allow_restore_seeders', false)) {
            return;
        }

        $class = (string) $event->input->getOption('class');

        if ($class === '' || ! str_contains($class, 'Restore')) {
            return;
        }

        $database = (string) config('database.connections.mysql.database', 'database');
        $io = new SymfonyStyle($event->input, $event->output);

        $io->error([
            'Blocked: Restore* seeders can overwrite demo data and are disabled while ALLOW_RESTORE_SCRIPTS=false.',
            "Database: {$database}",
            'Run restore yourself in PowerShell with ALLOW_RESTORE_SCRIPTS=true in .env, then set it back to false.',
        ]);

        exit(1);
    }
}

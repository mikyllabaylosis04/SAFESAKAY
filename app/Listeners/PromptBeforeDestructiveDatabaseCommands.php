<?php

namespace App\Listeners;

use App\Support\DemoDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Style\SymfonyStyle;

class PromptBeforeDestructiveDatabaseCommands
{
    /**
     * @var list<string>
     */
    private const COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'schema:drop',
    ];

    private static bool $confirmedThisRun = false;

    public function handle(CommandStarting $event): void
    {
        if (! $this->shouldPrompt()) {
            return;
        }

        if (! in_array($event->command, self::COMMANDS, true)) {
            return;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database', 'database');

        $io = new SymfonyStyle($event->input, $event->output);

        $allowDestructive = (bool) config('safesakay.allow_destructive_db_commands', false);

        if (DemoDatabaseGuard::isProtectedMysql() && ! $allowDestructive) {
            $io->error([
                'Blocked: wipe/reset commands are disabled for your demo MySQL database while ALLOW_DESTRUCTIVE_DB=false.',
                "Database: {$database}",
                'This stops Cursor Agent from erasing data. Set ALLOW_DESTRUCTIVE_DB=true only when you intentionally reset schema, then set it back to false.',
            ]);

            exit(1);
        }

        // Cursor/CI run commands without a TTY; never wipe real MySQL unless you opt in explicitly.
        if (! $io->isInteractive() && ! $allowDestructive) {
            $io->error([
                'Blocked: this command can erase your database and was run non-interactively (e.g. AI agent or script).',
                "Database: {$database}",
                'To allow once, set ALLOW_DESTRUCTIVE_DB=true in .env, run the command, then set it back to false.',
            ]);

            exit(1);
        }

        if ($event->input->getOption('force') && $allowDestructive) {
            return;
        }

        if ($event->input->getOption('force') && ! $allowDestructive) {
            $io->error([
                'Blocked: --force does not bypass the safety check on local.',
                'Use an interactive terminal and confirm, or set ALLOW_DESTRUCTIVE_DB=true if you really intend to wipe data.',
            ]);

            exit(1);
        }

        if (self::$confirmedThisRun) {
            return;
        }

        $io->warning([
            'This command can erase or reset data in your database.',
            "Database: {$database}",
            'Command: '.$event->command,
        ]);

        if (! $io->confirm('Do you really want to continue?', false)) {
            $io->error('Cancelled. No database changes were made.');

            exit(1);
        }

        self::$confirmedThisRun = true;
    }

    private function shouldPrompt(): bool
    {
        if (! config('safesakay.confirm_destructive_db_commands', true)) {
            return false;
        }

        return app()->environment('local');
    }
}

# SafeSakay — rules for AI agents

**Default: code-only.** Edit Vue/PHP/CSS. Do not run database reset or restore commands unless the user explicitly asks to restore/reset data in the same message.

## Never run on this project (demo data lives in MySQL `safesakayvue`)

- `php artisan migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `schema:drop`
- `php artisan db:seed --class=Restore*` (any class name containing `Restore`)
- `php storage/scripts/restore-demo-from-snapshots.php`
- One-off PHP scripts that call `migrate:fresh` or `db:wipe`

These are **blocked in `.env`** while `ALLOW_DESTRUCTIVE_DB=false` and `ALLOW_RESTORE_SCRIPTS=false`.

## Safe

- `php artisan test` (uses SQLite in-memory via `phpunit.xml`)
- `npm run build` / `npm run dev`
- `php artisan config:clear`, `route:clear`, `view:clear`

## User restores data themselves

1. Optional: `php storage/scripts/backup-safesakay.bat`
2. Set `ALLOW_RESTORE_SCRIPTS=true` in `.env`, run restore, set back to `false`.

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One-shot diagnostic. Run `php artisan helm:health` after `php artisan migrate`
 * to confirm every piece of infrastructure is reachable and every table exists.
 *
 * Exit code 0 = all green. Non-zero = something is wrong.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'helm:health';
    protected $description = 'Verify DB connection, Redis, migration state, and table presence.';

    private const EXPECTED_TABLES = [
        'users',
        'brands',
        'platform_connections',
        'daily_metrics',
        'sync_logs',
        'currency_rates',
        'platform_credentials',
        'audit_logs',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=white;options=bold>Helm health check</>');
        $this->newLine();

        $allGreen = true;

        $allGreen = $this->checkDatabase()             && $allGreen;
        $allGreen = $this->checkPostgresVersion()      && $allGreen;
        $allGreen = $this->checkTables()               && $allGreen;
        $allGreen = $this->checkAuthTables()           && $allGreen;
        $allGreen = $this->checkMigrationState()       && $allGreen;
        $allGreen = $this->checkRedis()                && $allGreen;
        $allGreen = $this->checkAppKey()               && $allGreen;
        $allGreen = $this->checkTimezone()             && $allGreen;

        $this->newLine();
        if ($allGreen) {
            $this->line('  <bg=green;fg=black> ALL CHECKS PASSED </> Helm is ready.');
            $this->newLine();
            return self::SUCCESS;
        }

        $this->line('  <bg=red;fg=white> CHECKS FAILED </> See errors above.');
        $this->newLine();
        return self::FAILURE;
    }

    private function checkDatabase(): bool
    {
        $connection = config('database.default');
        $host = config("database.connections.{$connection}.host");
        $database = config("database.connections.{$connection}.database");

        try {
            $pdo = DB::connection()->getPdo();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $this->printOk('Database connection', "{$driver} → {$host}/{$database}");
            return true;
        } catch (Throwable $e) {
            $this->printFail('Database connection', $e->getMessage());
            $this->printHint('Set DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD in .env. '
                . 'Try: psql -h $DB_HOST -U $DB_USERNAME -d $DB_DATABASE');
            return false;
        }
    }

    private function checkPostgresVersion(): bool
    {
        if (config('database.default') !== 'pgsql') {
            $this->printFail('Database driver', 'Expected pgsql, got ' . config('database.default'));
            $this->printHint('Spec §3 requires PostgreSQL 16. Set DB_CONNECTION=pgsql in .env.');
            return false;
        }

        try {
            $version = DB::selectOne('SHOW server_version')->server_version ?? null;
            if (! $version) {
                $this->printWarn('Postgres version', 'unknown');
                return true;
            }
            $major = (int) explode('.', $version)[0];
            if ($major < 14) {
                $this->printFail('Postgres version', "{$version} — need 14+, ideally 16");
                return false;
            }
            $this->printOk('Postgres version', $version);
            return true;
        } catch (Throwable $e) {
            $this->printFail('Postgres version', $e->getMessage());
            return false;
        }
    }

    private function checkTables(): bool
    {
        $missing = [];
        foreach (self::EXPECTED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            $this->printOk('Phase 1 tables', count(self::EXPECTED_TABLES) . ' tables present');
            return true;
        }

        $this->printFail('Phase 1 tables', count($missing) . ' missing: ' . implode(', ', $missing));
        $this->printHint('Run: php artisan migrate');
        return false;
    }

    /**
     * Login needs Sanctum's personal_access_tokens table. Sessions need
     * the `sessions` table if SESSION_DRIVER=database. Both ship as
     * package/published migrations — easy to miss.
     */
    private function checkAuthTables(): bool
    {
        $sessionDriver = config('session.driver');
        $missing = [];

        if (! Schema::hasTable('personal_access_tokens')) {
            $missing[] = 'personal_access_tokens';
        }
        if ($sessionDriver === 'database' && ! Schema::hasTable('sessions')) {
            $missing[] = 'sessions';
        }

        if (empty($missing)) {
            $this->printOk('Auth tables', 'Sanctum + session storage present');
            return true;
        }

        $this->printFail('Auth tables', 'missing: ' . implode(', ', $missing));
        $hints = [];
        if (in_array('personal_access_tokens', $missing, true)) {
            $hints[] = 'php artisan vendor:publish --provider="Laravel\\Sanctum\\SanctumServiceProvider"';
        }
        if (in_array('sessions', $missing, true)) {
            $hints[] = 'php artisan session:table  (or set SESSION_DRIVER=file in .env)';
        }
        $hints[] = 'php artisan migrate';
        foreach ($hints as $hint) {
            $this->printHint($hint);
        }
        return false;
    }

    private function checkMigrationState(): bool
    {
        try {
            $pending = collect(app('migrator')->paths())
                ->flatMap(fn ($path) => glob("{$path}/*.php"))
                ->map(fn ($file) => pathinfo($file, PATHINFO_FILENAME))
                ->diff(DB::table('migrations')->pluck('migration')->all())
                ->values();

            if ($pending->isEmpty()) {
                $count = DB::table('migrations')->count();
                $this->printOk('Migrations', "{$count} applied, 0 pending");
                return true;
            }

            $this->printFail('Migrations', "{$pending->count()} pending");
            $this->printHint('Run: php artisan migrate');
            return false;
        } catch (QueryException $e) {
            $this->printFail('Migrations', 'migrations table missing');
            $this->printHint('Run: php artisan migrate');
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            $pong = Redis::connection()->ping();
            $this->printOk('Redis', is_string($pong) ? $pong : 'PONG');
            return true;
        } catch (Throwable $e) {
            $this->printFail('Redis', $e->getMessage());
            $this->printHint('macOS: brew install redis && brew services start redis');
            return false;
        }
    }

    private function checkAppKey(): bool
    {
        $key = config('app.key');
        if (! $key || ! str_starts_with($key, 'base64:')) {
            $this->printFail('APP_KEY', 'missing or invalid');
            $this->printHint('Run: php artisan key:generate');
            return false;
        }
        $this->printOk('APP_KEY', 'set (encrypts platform_credentials)');
        return true;
    }

    private function checkTimezone(): bool
    {
        $tz = config('app.timezone');
        if ($tz !== 'UTC') {
            $this->printWarn('App timezone', "{$tz} — spec wants UTC. Brand timezones are stored per-brand and resolved at query time.");
            return true;
        }
        $this->printOk('App timezone', 'UTC');
        return true;
    }

    /* -------- output helpers --------
       Prefixed `print*` to avoid colliding with Illuminate\Console\Command's
       public methods (ok, fail, warn, info, etc. — some added across 11.x). */

    private function printOk(string $check, string $detail = ''): void
    {
        $this->line("  <fg=green>✓</> {$check}" . ($detail ? "  <fg=gray>{$detail}</>" : ''));
    }

    private function printFail(string $check, string $detail): void
    {
        $this->line("  <fg=red>✗</> {$check}  <fg=red>{$detail}</>");
    }

    private function printWarn(string $check, string $detail): void
    {
        $this->line("  <fg=yellow>!</> {$check}  <fg=yellow>{$detail}</>");
    }

    private function printHint(string $msg): void
    {
        $this->line("    <fg=gray>→ {$msg}</>");
    }
}

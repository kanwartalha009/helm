<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WorkspaceSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot data fix: any workspace_settings row whose raw JSONB value is a
 * double-encoded string (e.g. `"\"Foo\""`) gets re-saved cleanly through the
 * model cast, which writes it as proper JSONB.
 *
 * Run once after the allAsMap()/pluck fix: `php artisan helm:fix-workspace-settings`.
 */
class FixWorkspaceSettingsCommand extends Command
{
    protected $signature = 'helm:fix-workspace-settings';
    protected $description = 'Normalize any double-encoded workspace_settings rows.';

    public function handle(): int
    {
        $rows = DB::table('workspace_settings')->get();
        $fixed = 0;

        foreach ($rows as $row) {
            // Raw value comes back as the JSONB string. If it looks like
            // `"something"` (starts and ends with double quote), decode it
            // and re-save through the model so the cast handles it.
            $raw = (string) $row->value;
            $decoded = json_decode($raw, true);

            if (is_string($decoded)) {
                $model = WorkspaceSetting::find($row->id);
                if ($model) {
                    $model->value = $decoded;
                    $model->save();
                    $fixed++;
                    $this->line("  ✓ Fixed {$row->key}: " . json_encode($decoded));
                }
            }
        }

        $this->newLine();
        $this->info($fixed === 0 ? 'Nothing to fix.' : "Fixed {$fixed} row(s).");
        return self::SUCCESS;
    }
}

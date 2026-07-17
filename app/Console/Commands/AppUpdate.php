<?php

namespace App\Console\Commands;

use App\Services\OnlineLicenseCheck;
use App\Services\UpdateService;
use Illuminate\Console\Command;

class AppUpdate extends Command
{
    protected $signature = 'app:update {--check : only report status, do not apply}';

    protected $description = 'Check for and apply the latest release';

    public function handle(): int
    {
        // Clear any pending admin "Update Now" request now that we're running.
        \App\Models\Setting::put('update_requested', '0');

        // A fresh online license check refreshes the signed version info.
        (new OnlineLicenseCheck)->check();

        $st = UpdateService::status();
        $this->line('Current: ' . $st['current'] . '   Latest: ' . ($st['latest'] ?: 'unknown'));

        if ($this->option('check')) {
            $this->info($st['available'] ? 'Update available.' : 'Up to date.');

            return self::SUCCESS;
        }

        if (! $st['available']) {
            $this->info('Already up to date.');

            return self::SUCCESS;
        }

        $res = (new UpdateService)->apply(fn ($m) => $this->line($m));
        $res['ok'] ? $this->info($res['message']) : $this->error($res['message']);

        return $res['ok'] ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\S3\LifecycleEngine;
use Illuminate\Console\Command;

class StorageLifecycle extends Command
{
    protected $signature = 'storage:lifecycle';

    protected $description = 'Apply bucket lifecycle rules (expiry, old versions, stale uploads)';

    public function handle(LifecycleEngine $engine): int
    {
        $s = $engine->run();
        $this->info("Lifecycle: {$s['expired']} expired, {$s['versions']} old versions removed, "
            ."{$s['uploads']} stale uploads aborted, {$s['locked_skipped']} skipped (object lock).");

        return self::SUCCESS;
    }
}

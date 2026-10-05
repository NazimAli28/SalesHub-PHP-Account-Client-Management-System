<?php

namespace App\Actions\Demo;

use App\Imports\ImportFiles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Puts the public demo back to its seeded state: rebuilds the database with the demo data (which also
 * ends every session and empties the queue), clears the cache (rate limiters, two-factor timesteps)
 * and deletes every uploaded import file.
 */
class ResetDemo
{
    public function handle(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Artisan::call('cache:clear');

        $this->deleteImportFiles();
    }

    public function deleteImportFiles(): void
    {
        Storage::disk(ImportFiles::DISK)->deleteDirectory('imports');
    }
}

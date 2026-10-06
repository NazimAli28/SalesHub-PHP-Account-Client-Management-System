<?php

namespace App\Console\Commands;

use App\Support\StaticDemoExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the data set of the static browser demo (GitHub Pages): a fresh, seeded SQLite database
 * rendered through the real API Resources, so the in-browser mock API returns exactly the API's shapes.
 */
#[Signature('demo:export-static
    {--path=../frontend/src/demo/data : Output directory, relative to the backend folder}
    {--force : Run outside the local and testing environments}')]
#[Description('Export the seeded demo data as JSON for the static browser demo (no credentials, no password hashes)')]
class ExportStaticDemoCommand extends Command
{
    public const FILE = 'demo-data.json';

    public function handle(StaticDemoExporter $exporter): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('This command seeds a temporary database and is meant for local use. Pass --force to run it anyway.');

            return self::FAILURE;
        }

        $directory = (string) $this->option('path');
        if (! str_starts_with($directory, '/') && preg_match('/^[A-Za-z]:[\\\\\\/]/', $directory) !== 1) {
            $directory = base_path($directory);
        }

        $this->info('Seeding a temporary SQLite database...');
        $data = $exporter->export();

        File::ensureDirectoryExists($directory);
        $file = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.self::FILE;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($file, $json."\n");

        $this->info(sprintf('Wrote %s (%s KB).', realpath($file) ?: $file, number_format(strlen($json) / 1024, 1)));

        return self::SUCCESS;
    }
}

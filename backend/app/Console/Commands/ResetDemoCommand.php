<?php

namespace App\Console\Commands;

use App\Actions\Demo\ResetDemo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:reset {--force : Run even when demo mode is off (wipes the whole database)}')]
#[Description('Reset the public demo: fresh database with the demo data, empty cache, no uploaded import files')]
class ResetDemoCommand extends Command
{
    public function handle(ResetDemo $resetDemo): int
    {
        if (! config('saleshub.demo_mode') && ! $this->option('force')) {
            $this->error('Demo mode is off (DEMO_MODE=false). This command wipes the database; pass --force to run it anyway.');

            return self::FAILURE;
        }

        $resetDemo->handle();

        $this->info('The demo was reset.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyPixelforgeUpdate;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * Safety net for lost webhooks: Pixelforge only guarantees "at least once" if
 * our endpoint is up, so anything that has gone quiet is polled instead.
 */
#[Signature('generations:sync {--limit=10 : Most generations to poll in one run}')]
#[Description('Poll Pixelforge for generations that should have finished by now')]
class SyncUnfinishedGenerations extends Command
{
    /** Generations normally finish within ~30s (90s with [slow]); give webhooks time first. */
    private const QUIET_MINUTES = 3;

    public function handle(PixelforgeClient $client, ApplyPixelforgeUpdate $applyUpdate): int
    {
        $generations = Generation::query()
            ->unfinished()
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', Date::now()->subMinutes(self::QUIET_MINUTES))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            try {
                $applyUpdate($client->generation((string) $generation->pixelforge_id));
            } catch (PixelforgeException $e) {
                $this->warn("{$generation->pixelforge_id}: {$e->getMessage()}");

                if ($e->status === 429) {
                    break; // Leave the remaining requests for real work.
                }

                continue;
            }

            // Touch so a generation that's genuinely still running goes to the back of the line.
            $generation->touch();
        }

        $this->info("Polled {$generations->count()} generation(s).");

        return self::SUCCESS;
    }
}

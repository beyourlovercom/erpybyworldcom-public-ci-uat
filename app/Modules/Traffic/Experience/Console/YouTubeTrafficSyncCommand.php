<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\YouTubeTrafficSyncService;
use Illuminate\Console\Command;
use Throwable;

final class YouTubeTrafficSyncCommand extends Command
{
    protected $signature = 'traffic:youtube:sync
        {--project= : Business project key}
        {--channel= : YouTube channel ID}
        {--from= : Inclusive start date YYYY-MM-DD}
        {--to= : Inclusive end date YYYY-MM-DD}
        {--dry-run : Explicitly run without writes}
        {--apply : Apply idempotent YouTube native metric writes}';

    protected $description = 'Read YouTube Analytics channel and video daily metrics into Traffic OS';

    public function handle(YouTubeTrafficSyncService $service): int
    {
        $project = trim((string) $this->option('project'));
        $channel = trim((string) $this->option('channel'));
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';

        if ($this->option('apply') && $this->option('dry-run')) {
            return $this->emit(['mode' => $mode, 'blocked' => ['option_conflict']]);
        }
        if ($project === '' || $channel === '' || $from === '' || $to === '') {
            return $this->emit(['mode' => $mode, 'blocked' => ['missing_required_option']]);
        }

        try {
            return $this->emit($service->sync($project, $channel, $from, $to, $mode));
        } catch (Throwable) {
            return $this->emit(['mode' => $mode, 'blocked' => ['unexpected_error']]);
        }
    }

    /** @param array<string, mixed> $summary */
    private function emit(array $summary): int
    {
        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ($summary['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}

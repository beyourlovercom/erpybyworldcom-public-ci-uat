<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\MetaTrafficSyncService;
use Illuminate\Console\Command;
use Throwable;

final class MetaTrafficSyncCommand extends Command
{
    protected $signature = 'traffic:meta:sync
        {--project= : Business project key}
        {--source= : Meta source type: page or ads}
        {--account= : Meta Page ID or Ad Account ID}
        {--from= : Inclusive start date YYYY-MM-DD}
        {--to= : Inclusive end date YYYY-MM-DD}
        {--dry-run : Explicitly run without writes}
        {--apply : Apply idempotent Meta native metric writes}';

    protected $description = 'Read Meta Page or Ads native daily metrics into Traffic OS';

    public function handle(MetaTrafficSyncService $service): int
    {
        $project = trim((string) $this->option('project'));
        $source = trim((string) $this->option('source'));
        $account = trim((string) $this->option('account'));
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';

        if ($this->option('apply') && $this->option('dry-run')) {
            return $this->emit(['mode' => $mode, 'blocked' => ['option_conflict']]);
        }
        if ($project === '' || $source === '' || $account === '' || $from === '' || $to === '') {
            return $this->emit(['mode' => $mode, 'blocked' => ['missing_required_option']]);
        }

        try {
            return $this->emit($service->sync($project, $source, $account, $from, $to, $mode));
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

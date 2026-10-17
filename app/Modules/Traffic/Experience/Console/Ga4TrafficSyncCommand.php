<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\Ga4TrafficSyncService;
use Illuminate\Console\Command;
use Throwable;

final class Ga4TrafficSyncCommand extends Command
{
    protected $signature = 'traffic:ga4:sync
        {--project= : Business project key}
        {--property= : Numeric GA4 property id}
        {--from= : Inclusive start date YYYY-MM-DD}
        {--to= : Inclusive end date YYYY-MM-DD}
        {--dry-run : Explicitly run without writes}
        {--apply : Apply idempotent GA4 daily metric writes}';

    protected $description = 'Read GA4 site, channel and landing-page daily metrics into Traffic OS';

    public function handle(Ga4TrafficSyncService $service): int
    {
        $project = trim((string) ($this->option('project') ?: config('services.google_analytics.sync_project', '')));
        $property = trim((string) ($this->option('property') ?: config('services.google_analytics.property_id', '')));
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';

        if ($this->option('apply') && $this->option('dry-run')) {
            return $this->emit(['mode' => $mode, 'blocked' => ['option_conflict']]);
        }
        if ($project === '' || $property === '' || $from === '' || $to === '') {
            return $this->emit(['mode' => $mode, 'blocked' => ['missing_required_option']]);
        }

        try {
            return $this->emit($service->sync($project, $property, $from, $to, $mode));
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

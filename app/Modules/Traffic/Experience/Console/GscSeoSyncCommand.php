<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\GscSeoSyncService;
use Illuminate\Console\Command;
use Throwable;

final class GscSeoSyncCommand extends Command
{
    protected $signature = 'traffic:seo-gsc:sync
        {--project= : Business project key}
        {--site= : Business project site key}
        {--property= : GSC property, e.g. sc-domain:ybyirrigation.com}
        {--from= : Inclusive start date YYYY-MM-DD}
        {--to= : Inclusive end date YYYY-MM-DD}
        {--dry-run : Explicitly run without writes}
        {--apply : Apply idempotent SEO metric writes}';

    protected $description = 'Read GSC Query x Page daily metrics into SEO Traffic OS';

    public function handle(GscSeoSyncService $service): int
    {
        $project = trim((string) $this->option('project'));
        $site = trim((string) $this->option('site'));
        $property = trim((string) $this->option('property'));
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        if ($this->option('apply') && $this->option('dry-run')) {
            return $this->emit($this->blocked($mode, $project, $site, $property, $from, $to, 'option_conflict'));
        }
        if ($project === '' || $site === '' || $property === '' || $from === '' || $to === '') {
            return $this->emit($this->blocked($mode, $project, $site, $property, $from, $to, 'missing_required_option'));
        }
        try {
            return $this->emit($service->sync($project, $site, $property, $from, $to, $mode));
        } catch (Throwable) {
            return $this->emit($this->blocked($mode, $project, $site, $property, $from, $to, 'unexpected_error'));
        }
    }

    /** @param array<string, mixed> $summary */
    private function emit(array $summary): int
    {
        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ($summary['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function blocked(string $mode, string $project, string $site, string $property, string $from, string $to, string $reason): array
    {
        return [
            'mode' => $mode, 'project_key' => $project, 'site_key' => $site, 'site_property' => $property,
            'date_from' => $from, 'date_to' => $to, 'query_rows_seen' => 0, 'keywords_seen' => 0,
            'keywords_missing' => 0, 'keywords_created' => 0, 'daily_rows_applied' => 0, 'blocked' => [$reason],
        ];
    }
}

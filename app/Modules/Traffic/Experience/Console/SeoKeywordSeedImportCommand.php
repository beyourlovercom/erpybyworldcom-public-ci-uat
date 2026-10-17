<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoKeywordSeedImportService;
use Illuminate\Console\Command;
use Throwable;

final class SeoKeywordSeedImportCommand extends Command
{
    protected $signature = 'traffic:seo-keywords:import
        {--project= : Business project key}
        {--file= : Normalized keyword seed CSV path}
        {--dry-run : Validate and summarize without writes}
        {--apply : Apply idempotent keyword/evidence writes}';

    protected $description = 'Import a normalized project keyword intelligence seed into Traffic OS';

    public function handle(SeoKeywordSeedImportService $service): int
    {
        $project = trim((string) $this->option('project'));
        $file = trim((string) $this->option('file'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';

        if ($this->option('apply') && $this->option('dry-run')) {
            return $this->emit(['mode' => $mode, 'blocked' => ['option_conflict']]);
        }
        if ($project === '' || $file === '') {
            return $this->emit(['mode' => $mode, 'blocked' => ['missing_required_option']]);
        }

        try {
            return $this->emit($service->import($project, $file, $mode));
        } catch (Throwable) {
            return $this->emit(['mode' => $mode, 'project_key' => $project, 'blocked' => ['unexpected_error']]);
        }
    }

    /** @param array<string, mixed> $summary */
    private function emit(array $summary): int
    {
        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return ($summary['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}

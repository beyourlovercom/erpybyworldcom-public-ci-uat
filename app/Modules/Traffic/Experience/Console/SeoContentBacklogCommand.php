<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoContentBacklogService;
use Illuminate\Console\Command;

final class SeoContentBacklogCommand extends Command
{
    protected $signature = 'traffic:seo-content-backlog
        {--project= : Business project key}
        {--dry-run : Preview without writes}
        {--apply : Apply idempotent backlog writes}';

    protected $description = 'Generate SEO content/optimization backlog from P0 opportunities';

    public function handle(SeoContentBacklogService $service): int
    {
        $project = trim((string) $this->option('project'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        if ($project === '' || ($this->option('apply') && $this->option('dry-run'))) return self::FAILURE;
        $result = $service->generate($project, $mode);
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return ($result['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoOpportunityRefeedService;
use Illuminate\Console\Command;

final class SeoOpportunityRefeedCommand extends Command
{
    protected $signature = 'traffic:seo-opportunities:refeed {--project=} {--dry-run} {--apply}';
    protected $description = 'Reclassify unclassified live-GSC SEO opportunities without overwriting curated seed actions';

    public function handle(SeoOpportunityRefeedService $service): int
    {
        $project = trim((string)$this->option('project'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        if ($project === '' || ($this->option('apply') && $this->option('dry-run'))) return self::FAILURE;
        $result = $service->run($project, $mode);
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return ($result['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}

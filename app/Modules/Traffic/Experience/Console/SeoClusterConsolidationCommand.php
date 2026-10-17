<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoClusterConsolidationService;
use Illuminate\Console\Command;

final class SeoClusterConsolidationCommand extends Command
{
    protected $signature = 'traffic:seo-clusters:consolidate {--project=} {--dry-run} {--apply}';
    protected $description = 'Consolidate SEO backlog by target URL and audit new-content clusters';

    public function handle(SeoClusterConsolidationService $service): int
    {
        $project = trim((string)$this->option('project'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        if ($project === '' || ($this->option('apply') && $this->option('dry-run'))) {
            $this->line(json_encode(['mode'=>$mode,'project_key'=>$project,'blocked'=>['invalid_options']]));
            return self::FAILURE;
        }
        $result = $service->consolidate($project,$mode);
        $this->line(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return ($result['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}

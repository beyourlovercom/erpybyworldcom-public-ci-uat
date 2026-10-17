<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoOptimizationWorkflowService;
use Illuminate\Console\Command;

final class SeoOptimizationWorkflowCommand extends Command
{
    protected $signature = 'traffic:seo-optimize:work-orders
        {--project=yby-irrigation : Business project key}
        {--apply : Persist work orders locally}
        {--dry-run : Preview only}';

    protected $description = 'Build page-level SEO optimization work orders from consolidated backlog';

    public function handle(SeoOptimizationWorkflowService $service): int
    {
        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        $result = $service->build((string)$this->option('project'), $mode);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return $result['blocked'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

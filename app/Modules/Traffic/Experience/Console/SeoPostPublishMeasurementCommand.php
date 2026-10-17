<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoPostPublishMeasurementService;
use Illuminate\Console\Command;

final class SeoPostPublishMeasurementCommand extends Command
{
    protected $signature = 'traffic:seo-post-publish
        {action : register|snapshot}
        {--project=yby-irrigation : Business project key}
        {--snapshot=T0 : Snapshot label, e.g. T0 or T28}
        {--work-order= : Optional work order id scope}
        {--apply : Persist changes}
        {--dry-run : Preview only}';

    protected $description = 'Register SEO publications and capture post-publish GSC measurements';

    public function handle(SeoPostPublishMeasurementService $service): int
    {
        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        $action = (string) $this->argument('action');
        $workOrderId = $this->option('work-order') !== null && $this->option('work-order') !== ''
            ? (int) $this->option('work-order')
            : null;

        $result = $action === 'register'
            ? $service->register((string) $this->option('project'), $mode, $workOrderId)
            : ($action === 'snapshot'
                ? $service->snapshot(
                    (string) $this->option('project'),
                    (string) $this->option('snapshot'),
                    $mode,
                    $workOrderId
                )
                : ['blocked' => ['invalid_action']]);

        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($result['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}

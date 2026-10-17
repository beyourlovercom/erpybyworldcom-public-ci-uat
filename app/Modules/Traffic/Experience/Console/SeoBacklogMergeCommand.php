<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoBacklogMergeService;
use Illuminate\Console\Command;

final class SeoBacklogMergeCommand extends Command
{
    protected $signature = 'traffic:seo-backlog:merge
        {--project=yby-irrigation : Business project key}
        {--source= : Planned source backlog id}
        {--target= : Existing canonical target backlog id}
        {--apply : Persist merge}
        {--dry-run : Preview only}';

    protected $description = 'Merge one planned SEO backlog into an existing canonical backlog without changing the active publication';

    public function handle(SeoBacklogMergeService $service): int
    {
        if (
            $this->option('source') === null || $this->option('source') === ''
            || $this->option('target') === null || $this->option('target') === ''
            || ($this->option('apply') && $this->option('dry-run'))
        ) {
            $this->error('Invalid options. Provide --source and --target; choose only one of --apply/--dry-run.');

            return self::FAILURE;
        }

        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        $result = $service->merge(
            (string) $this->option('project'),
            (int) $this->option('source'),
            (int) $this->option('target'),
            $mode
        );
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result['blocked'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

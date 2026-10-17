<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoGrowthExistingPublicationService;
use Illuminate\Console\Command;

final class SeoGrowthExistingPublicationCommand extends Command
{
    protected $signature = 'traffic:seo-growth-existing:publish
        {--project=yby-irrigation : Business project key}
        {--baseline-url= : Actionable Growth Engine existing-page URL}
        {--published-url= : Published canonical URL}
        {--production-post-id= : Production WordPress post id}
        {--publish-date= : Publish date YYYY-MM-DD}
        {--release-sha= : Website release commit}
        {--apply : Persist published work order}
        {--dry-run : Preview only}';

    protected $description = 'Create one scoped published SEO work order from an actionable Growth Engine existing-page candidate';

    public function handle(SeoGrowthExistingPublicationService $service): int
    {
        foreach (['baseline-url', 'published-url', 'production-post-id', 'publish-date', 'release-sha'] as $option) {
            if ($this->option($option) === null || $this->option($option) === '') {
                $this->error('Missing required option --'.$option);

                return self::FAILURE;
            }
        }
        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        $result = $service->publish(
            (string) $this->option('project'),
            (string) $this->option('baseline-url'),
            (string) $this->option('published-url'),
            (int) $this->option('production-post-id'),
            (string) $this->option('publish-date'),
            (string) $this->option('release-sha'),
            $mode
        );
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result['blocked'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\SeoNewContentPublicationService;
use Illuminate\Console\Command;

final class SeoNewContentPublicationCommand extends Command
{
    protected $signature = 'traffic:seo-new-content:publish
        {--project=yby-irrigation : Business project key}
        {--backlog= : New-content backlog id}
        {--published-url= : Published canonical URL}
        {--production-post-id= : Production WordPress post id}
        {--publish-date= : Publish date YYYY-MM-DD}
        {--release-sha= : Website release commit}
        {--apply : Persist work order and backlog status}
        {--dry-run : Preview only}';

    protected $description = 'Create a zero-baseline published SEO work order from one new-content backlog';

    public function handle(SeoNewContentPublicationService $service): int
    {
        foreach (['backlog', 'published-url', 'production-post-id', 'publish-date', 'release-sha'] as $option) {
            if ($this->option($option) === null || $this->option($option) === '') {
                $this->error('Missing required option --'.$option);

                return self::FAILURE;
            }
        }

        $mode = $this->option('apply') ? 'apply' : 'dry_run';
        $result = $service->publish(
            (string) $this->option('project'),
            (int) $this->option('backlog'),
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

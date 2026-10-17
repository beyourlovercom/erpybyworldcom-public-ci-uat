<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeoNewContentPublicationService
{
    /** @return array<string,mixed> */
    public function publish(
        string $projectKey,
        int $backlogId,
        string $publishedUrl,
        int $productionPostId,
        string $publishDate,
        string $releaseSha,
        string $mode = 'dry_run'
    ): array {
        $summary = [
            'mode' => $mode, 'project_key' => $projectKey, 'backlog_id' => $backlogId,
            'seen' => 0, 'applied' => 0, 'work_order_id' => null, 'payload' => null, 'blocked' => [],
        ];
        $project = BusinessProject::query()
            ->where('project_key', $projectKey)->where('status', 'active')->first();
        if (! $project) {
            return $this->blocked($summary, 'project_not_found');
        }
        if (! in_array($mode, ['dry_run', 'apply'], true)) {
            return $this->blocked($summary, 'invalid_mode');
        }
        if ($productionPostId < 1) {
            return $this->blocked($summary, 'invalid_production_post_id');
        }
        if (! preg_match('/^[0-9a-f]{7,64}$/i', $releaseSha)) {
            return $this->blocked($summary, 'invalid_release_sha');
        }

        try {
            $retestAfter = Carbon::parse($publishDate)->addDays(28)->toDateString();
            $publishDate = Carbon::parse($publishDate)->toDateString();
        } catch (Throwable) {
            return $this->blocked($summary, 'invalid_publish_date');
        }

        $host = strtolower((string) parse_url($publishedUrl, PHP_URL_HOST));
        $allowed = DB::table('business_project_sites')
            ->where('business_project_id', $project->id)->where('status', 'active')
            ->pluck('domain_normalized')->map(fn ($x) => strtolower((string) $x))->all();
        $allowed = array_values(array_unique(array_merge($allowed, array_map(fn ($x) => 'www.'.$x, $allowed))));
        if ($host === '' || ! in_array($host, $allowed, true)) {
            return $this->blocked($summary, 'published_url_outside_project');
        }

        $backlog = DB::table('seo_content_backlog')
            ->where('id', $backlogId)->where('business_project_id', $project->id)->first();
        if (! $backlog) {
            return $this->blocked($summary, 'backlog_not_found');
        }
        if ((string) $backlog->status !== 'planned') {
            return $this->blocked($summary, 'backlog_not_planned');
        }
        if (! in_array((string) $backlog->content_type, ['blog', 'commercial_page', 'comparison_blog'], true)) {
            return $this->blocked($summary, 'backlog_not_new_content');
        }
        if (! $backlog->seo_keyword_id) {
            return $this->blocked($summary, 'backlog_keyword_missing');
        }

        $keyword = DB::table('seo_keywords')->where('id', $backlog->seo_keyword_id)->first();
        if (! $keyword) {
            return $this->blocked($summary, 'keyword_not_found');
        }
        if ((string) $keyword->suggested_action !== 'NEW_CONTENT_P0') {
            return $this->blocked($summary, 'keyword_not_new_content_p0');
        }

        $exists = DB::table('seo_optimization_work_orders')
            ->where('business_project_id', $project->id)
            ->where(function ($q) use ($backlogId, $publishedUrl): void {
                $q->where('seo_content_backlog_id', $backlogId)
                    ->orWhere('target_url_hash', hash('sha256', $publishedUrl));
            })->exists();
        if ($exists) {
            return $this->blocked($summary, 'work_order_exists');
        }

        $supporting = DB::table('seo_content_backlog as b')
            ->join('seo_keywords as k', 'k.id', '=', 'b.seo_keyword_id')
            ->where('b.business_project_id', $project->id)
            ->where('b.content_type', 'cluster_member')
            ->where('b.status', 'retired')
            ->where('b.rationale', 'like', '%#'.$backlogId.'%')
            ->pluck('k.keyword')->values()->all();

        $recommendations = [
            'title_h1' => 'Keep one canonical page around primary keyword: '.$keyword->keyword.'.',
            'content' => 'Cover the consolidated new-content intent without creating competing brand pages.',
            'internal_links' => 'Add contextual links to the relevant product/category canonical.',
            'validation' => 'Measure published URL impressions, clicks, CTR and average position after the retest window.',
            'new_content' => [
                'source_backlog_id' => $backlogId,
                'content_type' => (string) $backlog->content_type,
                'no_legacy_baseline' => true,
            ],
            'production' => [
                'post_id' => $productionPostId,
                'publish_date' => $publishDate,
                'retest_after' => $retestAfter,
                'canonical_url' => $publishedUrl,
                'url' => $publishedUrl,
                'release_sha' => $releaseSha,
                'owner_uat' => 'pass',
            ],
        ];

        $payload = [
            'business_project_id' => $project->id,
            'seo_content_backlog_id' => $backlogId,
            'primary_seo_keyword_id' => (int) $backlog->seo_keyword_id,
            'target_url_hash' => hash('sha256', $publishedUrl),
            'target_url' => $publishedUrl,
            'supporting_keywords' => json_encode($supporting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'gsc_impressions' => 0, 'gsc_clicks' => 0, 'gsc_ctr' => 0, 'gsc_average_position' => null,
            'priority_score' => (float) ($keyword->opportunity_score ?? 0),
            'recommendations' => json_encode($recommendations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'workflow_status' => 'published',
            'baseline_from' => null, 'baseline_to' => null, 'retest_after' => $retestAfter,
        ];
        $summary['seen'] = 1;
        $summary['payload'] = $payload;

        if ($mode === 'apply') {
            DB::transaction(function () use ($project, $backlogId, $publishedUrl, $payload, &$summary): void {
                $locked = DB::table('seo_content_backlog')
                    ->where('id', $backlogId)->where('business_project_id', $project->id)
                    ->lockForUpdate()->first();
                if (! $locked || (string) $locked->status !== 'planned') {
                    throw new \RuntimeException('backlog_state_changed');
                }
                $exists = DB::table('seo_optimization_work_orders')
                    ->where('business_project_id', $project->id)
                    ->where(function ($q) use ($backlogId, $publishedUrl): void {
                        $q->where('seo_content_backlog_id', $backlogId)
                            ->orWhere('target_url_hash', hash('sha256', $publishedUrl));
                    })->exists();
                if ($exists) {
                    throw new \RuntimeException('work_order_exists');
                }

                $summary['work_order_id'] = DB::table('seo_optimization_work_orders')
                    ->insertGetId($payload + ['created_at' => now(), 'updated_at' => now()]);
                DB::table('seo_content_backlog')->where('id', $backlogId)->update([
                    'status' => 'published', 'updated_at' => now(),
                ]);
                $summary['applied'] = 1;
            });
        }

        return $summary;
    }

    /** @param array<string,mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];

        return $summary;
    }
}

<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeoGrowthExistingPublicationService
{
    private const GROWTH_ACTIONS = [
        'PAGE1_P0', 'TOP3_CTR_P0', 'TOP3_P1', 'CTR_P1', 'OPTIMIZE_P2', 'DEFEND',
    ];

    /** @return array<string,mixed> */
    public function publish(
        string $projectKey,
        string $baselineUrl,
        string $publishedUrl,
        int $productionPostId,
        string $publishDate,
        string $releaseSha,
        string $mode = 'dry_run'
    ): array {
        $summary = [
            'mode' => $mode, 'project_key' => $projectKey, 'baseline_url' => $baselineUrl,
            'published_url' => $publishedUrl, 'seen' => 0, 'applied' => 0,
            'work_order_id' => null, 'payload' => null, 'blocked' => [],
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
            $publishDate = Carbon::parse($publishDate)->toDateString();
            $retestAfter = Carbon::parse($publishDate)->addDays(28)->toDateString();
        } catch (Throwable) {
            return $this->blocked($summary, 'invalid_publish_date');
        }

        $growth = app(SeoGrowthEngineService::class)->build($projectKey);
        $baselineKey = $this->normalizeUrl($baselineUrl);
        $candidate = collect($growth['queue'] ?? [])->first(
            fn (array $row) => ($row['kind'] ?? null) === 'existing_page'
                && $this->normalizeUrl((string) ($row['target_url'] ?? '')) === $baselineKey
        );
        if (! $candidate) {
            return $this->blocked($summary, 'growth_candidate_not_actionable');
        }
        $allowedHosts = DB::table('business_project_sites')
            ->where('business_project_id', $project->id)->where('status', 'active')
            ->pluck('domain_normalized')->map(fn ($host) => strtolower((string) $host))->all();
        $allowedHosts = array_values(array_unique(array_merge(
            $allowedHosts,
            array_map(fn (string $host) => 'www.'.$host, $allowedHosts)
        )));
        $publishedHost = strtolower((string) parse_url($publishedUrl, PHP_URL_HOST));
        if ($publishedHost === '' || ! in_array($publishedHost, $allowedHosts, true)) {
            return $this->blocked($summary, 'published_url_outside_project');
        }

        $primary = DB::table('seo_keywords')
            ->where('business_project_id', $project->id)
            ->where('keyword', (string) $candidate['keyword'])
            ->whereIn('suggested_action', self::GROWTH_ACTIONS)
            ->first();
        if (! $primary) {
            return $this->blocked($summary, 'primary_keyword_not_found');
        }

        $clusterRows = DB::table('seo_gsc_daily_metrics as g')
            ->join('seo_keywords as k', 'k.id', '=', 'g.seo_keyword_id')
            ->where('k.business_project_id', $project->id)
            ->whereIn('k.suggested_action', self::GROWTH_ACTIONS)
            ->whereNotNull('g.page_url')
            ->get(['k.id', 'k.keyword', 'g.page_url', 'g.metric_date']);
        $clusterRows = $clusterRows->filter(
            fn ($row) => $this->normalizeUrl((string) $row->page_url) === $baselineKey
        );
        $keywordIds = $clusterRows->pluck('id')->map('intval')->unique()->values()->all();
        if (! in_array((int) $primary->id, $keywordIds, true)) {
            $keywordIds[] = (int) $primary->id;
        }
        $supporting = DB::table('seo_keywords')
            ->whereIn('id', $keywordIds)->where('id', '<>', $primary->id)
            ->pluck('keyword')->unique()->values()->all();
        $baselineFrom = $clusterRows->min('metric_date');
        $baselineTo = $clusterRows->max('metric_date');

        $exists = DB::table('seo_optimization_work_orders')
            ->where('business_project_id', $project->id)
            ->where('target_url_hash', hash('sha256', $baselineUrl))
            ->exists();
        if ($exists) {
            return $this->blocked($summary, 'work_order_exists');
        }

        $recommendations = [
            'title_h1' => 'Align Title and H1 around primary keyword: '.$candidate['keyword'].'; keep one canonical page.',
            'content' => 'Improve the existing-page intent without creating a competing canonical.',
            'internal_links' => 'Add contextual internal links from related product/category/article pages with natural anchors.',
            'validation' => 'Compare the legacy baseline and published canonical after the retest window.',
            'growth_existing' => [
                'decision' => $candidate['decision'],
                'priority' => $candidate['priority'],
                'source' => 'SEO7-GROWTH-V1',
                'no_backlog_required' => true,
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

        $impressions = (int) $candidate['impressions'];
        $clicks = (int) $candidate['clicks'];
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;
        $payload = [
            'business_project_id' => $project->id,
            'seo_content_backlog_id' => null,
            'primary_seo_keyword_id' => (int) $primary->id,
            'target_url_hash' => hash('sha256', $baselineUrl),
            'target_url' => $baselineUrl,
            'supporting_keywords' => json_encode($supporting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'gsc_impressions' => $impressions,
            'gsc_clicks' => $clicks,
            'gsc_ctr' => $ctr,
            'gsc_average_position' => $candidate['average_position'],
            'priority_score' => $this->priorityScore($impressions, $ctr, $candidate['average_position']),
            'recommendations' => json_encode($recommendations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'workflow_status' => 'published',
            'baseline_from' => $baselineFrom,
            'baseline_to' => $baselineTo,
            'retest_after' => $retestAfter,
        ];
        $summary['seen'] = 1;
        $summary['payload'] = $payload;

        if ($mode === 'apply') {
            DB::transaction(function () use ($project, $baselineUrl, $payload, &$summary): void {
                $exists = DB::table('seo_optimization_work_orders')
                    ->where('business_project_id', $project->id)
                    ->where('target_url_hash', hash('sha256', $baselineUrl))
                    ->lockForUpdate()->exists();
                if ($exists) {
                    throw new \RuntimeException('work_order_exists');
                }

                $summary['work_order_id'] = DB::table('seo_optimization_work_orders')
                    ->insertGetId($payload + ['created_at' => now(), 'updated_at' => now()]);
                $summary['applied'] = 1;
            });
        }

        return $summary;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($host === '') {
            return '';
        }
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return ($scheme ?: 'https').'://'.$host.$path;
    }

    private function priorityScore(int $impressions, float $ctr, ?float $position): float
    {
        $positionFactor = $position !== null && $position >= 4 && $position <= 30 ? 1.5 : 1.0;
        $ctrFactor = 1 + max(0, 0.03 - $ctr) * 10;

        return round($impressions * $positionFactor * $ctrFactor, 4);
    }

    /** @param array<string,mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];

        return $summary;
    }
}

<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SeoGrowthEngineService
{
    /** @return array<string,mixed> */
    public function build(string $projectKey): array
    {
        $project = BusinessProject::query()
            ->where('project_key', $projectKey)
            ->where('status', 'active')
            ->first();

        if (! $project) {
            throw new InvalidArgumentException('Business project not found.');
        }

        $hosts = $this->growthHosts((int) $project->id);
        $published = $this->publishedUrlSet((int) $project->id);
        $publishedKeywordIds = $this->publishedPrimaryKeywordIdSet((int) $project->id);
        $migrationKeys = $this->migrationBacklogKeySet((int) $project->id);
        $pageCandidates = $this->existingPageCandidates((int) $project->id, $hosts, $published, $publishedKeywordIds, $migrationKeys);
        $newContent = $this->newContentCandidates((int) $project->id, $hosts, $published);

        $queue = $pageCandidates['rows']
            ->concat($newContent['rows'])
            ->sortBy([
                fn (array $a, array $b) => $this->priorityRank($a['priority']) <=> $this->priorityRank($b['priority']),
                fn (array $a, array $b) => $b['opportunity_score'] <=> $a['opportunity_score'],
                fn (array $a, array $b) => $b['impressions'] <=> $a['impressions'],
            ])
            ->values();

        $monitoring = $this->monitoringRows((int) $project->id);
        $scopeHealth = $this->scopeHealth((int) $project->id, $hosts);
        $international = $this->internationalWorkOrders((int) $project->id, $hosts);

        return [
            'version' => 'SEO7-GROWTH-V1',
            'scope' => [
                'mode' => 'english_production_growth',
                'hosts' => $hosts,
                'rule' => 'Only exact English root hosts are eligible. Legacy language subdomains stay outside the English Growth Queue.',
            ],
            'summary' => [
                'actionable' => $queue->count(),
                'existing_page' => $queue->where('kind', 'existing_page')->count(),
                'new_content' => $queue->where('kind', 'new_content')->count(),
                'p0' => $queue->where('priority', 'P0')->count(),
                'monitoring' => $monitoring->where('state', 'monitoring')->count(),
                'retest_due' => $monitoring->where('state', 'retest_due')->count(),
                'winner' => $monitoring->where('state', 'winner')->count(),
                'loser' => $monitoring->where('state', 'loser')->count(),
                'mixed' => $monitoring->where('state', 'mixed')->count(),
                'international_planned' => $international['planned'],
                'published_pages_filtered' => $pageCandidates['published_filtered'],
                'published_keyword_filtered' => $pageCandidates['published_keyword_filtered'],
                'low_evidence_filtered' => $pageCandidates['low_evidence_filtered'],
                'existing_page_migration_filtered' => $pageCandidates['migration_filtered'],
                'migration_content_planned' => $this->migrationContentPlanned((int) $project->id),
                'new_content_publication_mapped_filtered' => $newContent['publication_mapped_filtered'],
            ],
            'queue' => $queue->take(40)->values()->all(),
            'monitoring' => $monitoring->sortBy([
                fn (array $a, array $b) => $this->monitoringRank($a['state']) <=> $this->monitoringRank($b['state']),
                fn (array $a, array $b) => $a['retest_after'] <=> $b['retest_after'],
            ])->take(40)->values()->all(),
            'data_health' => [
                'gsc_live_connected' => $scopeHealth['rows'] > 0,
                'scoped_gsc_rows' => $scopeHealth['rows'],
                'scoped_gsc_keywords' => $scopeHealth['keywords'],
                'scoped_impressions' => $scopeHealth['impressions'],
                'latest_metric_date' => $scopeHealth['latest_metric_date'],
                'international_gsc_rows_excluded' => $scopeHealth['international_rows'],
                'international_impressions_excluded' => $scopeHealth['international_impressions'],
                'raw_keyword_count' => DB::table('seo_keywords')->where('business_project_id', $project->id)->count(),
                'consolidated_new_content_truth' => true,
            ],
            'international' => $international,
        ];
    }

    /** @return string[] */
    private function growthHosts(int $projectId): array
    {
        $domains = DB::table('business_project_sites')
            ->where('business_project_id', $projectId)
            ->where('status', 'active')
            ->pluck('domain_normalized')
            ->filter()
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->unique()
            ->values();

        return $domains
            ->flatMap(fn (string $host) => [$host, 'www.'.$host])
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string,true> */
    private function publishedUrlSet(int $projectId): array
    {
        $rows = DB::table('seo_optimization_publications as p')
            ->join('seo_optimization_work_orders as w', 'w.id', '=', 'p.seo_optimization_work_order_id')
            ->where('w.business_project_id', $projectId)
            ->get(['p.baseline_url', 'p.published_url']);

        $set = [];
        foreach ($rows as $row) {
            foreach ([$row->baseline_url, $row->published_url] as $url) {
                $normalized = $this->normalizeUrl((string) $url);
                if ($normalized !== '') {
                    $set[$normalized] = true;
                }
            }
        }

        return $set;
    }

    /** @return array<int,true> */
    private function publishedPrimaryKeywordIdSet(int $projectId): array
    {
        $ids = DB::table('seo_optimization_work_orders as w')
            ->join('seo_optimization_publications as p', 'p.seo_optimization_work_order_id', '=', 'w.id')
            ->where('w.business_project_id', $projectId)
            ->whereNotNull('w.primary_seo_keyword_id')
            ->distinct()
            ->pluck('w.primary_seo_keyword_id');

        $set = [];
        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }

    /** @return array{rows:Collection<int,array<string,mixed>>,published_filtered:int,published_keyword_filtered:int,low_evidence_filtered:int,migration_filtered:int} */
    private function existingPageCandidates(int $projectId, array $hosts, array $published, array $publishedKeywordIds, array $migrationKeys): array
    {
        $actions = ['PAGE1_P0', 'TOP3_CTR_P0', 'TOP3_P1', 'CTR_P1', 'OPTIMIZE_P2', 'DEFEND'];
        $query = DB::table('seo_gsc_daily_metrics as g')
            ->join('seo_keywords as k', 'k.id', '=', 'g.seo_keyword_id')
            ->where('k.business_project_id', $projectId)
            ->whereIn('k.suggested_action', $actions)
            ->whereNotNull('g.page_url');

        $this->applyHostScope($query, 'g.page_url', $hosts);

        $rows = $query
            ->select(
                'k.id as keyword_id',
                'k.keyword',
                'k.topic_cluster',
                'k.suggested_action',
                'k.suggested_target_type',
                'k.opportunity_score',
                'g.page_url'
            )
            ->selectRaw(
                'SUM(g.impressions) impressions, SUM(g.clicks) clicks,
                 SUM(g.average_position * g.impressions) / NULLIF(SUM(g.impressions), 0) average_position,
                 MAX(g.metric_date) latest_metric_date'
            )
            ->groupBy(
                'k.id',
                'k.keyword',
                'k.topic_cluster',
                'k.suggested_action',
                'k.suggested_target_type',
                'k.opportunity_score',
                'g.page_url'
            )
            ->get();

        $publishedFiltered = 0;
        $publishedKeywordFiltered = 0;
        $lowEvidenceFiltered = 0;
        $migrationFiltered = 0;
        $result = collect();

        foreach ($rows->groupBy(fn ($row) => $this->normalizeUrl((string) $row->page_url)) as $normalizedUrl => $pageRows) {
            if ($normalizedUrl === '') {
                continue;
            }
            if (isset($published[$normalizedUrl])) {
                $publishedFiltered++;

                continue;
            }
            $migrationKey = $this->legacyMigrationKeyFromUrl($normalizedUrl);
            if ($migrationKey !== null && isset($migrationKeys[$migrationKey])) {
                $migrationFiltered++;

                continue;
            }

            $primary = $pageRows->sortByDesc(fn ($row) => [
                (float) ($row->opportunity_score ?? 0),
                (int) ($row->impressions ?? 0),
            ])->first();
            if (! $primary) {
                continue;
            }
            if (isset($publishedKeywordIds[(int) $primary->keyword_id])) {
                $publishedKeywordFiltered++;

                continue;
            }

            $impressions = (int) $pageRows->sum(fn ($row) => (int) ($row->impressions ?? 0));
            $clicks = (int) $pageRows->sum(fn ($row) => (int) ($row->clicks ?? 0));
            $weightedPosition = $impressions > 0
                ? $pageRows->sum(fn ($row) => (float) ($row->average_position ?? 0) * (int) ($row->impressions ?? 0)) / $impressions
                : null;

            $quality = $this->qualityGate($impressions, $clicks, $weightedPosition);
            if ($quality === null) {
                $lowEvidenceFiltered++;

                continue;
            }

            $decision = $quality['decision'];
            $priority = $quality['priority'];

            $result->push([
                'key' => 'page:'.sha1($normalizedUrl),
                'kind' => 'existing_page',
                'priority' => $priority,
                'decision' => $decision,
                'keyword' => (string) $primary->keyword,
                'topic_cluster' => $primary->topic_cluster,
                'suggested_action' => (string) $primary->suggested_action,
                'suggested_target_type' => $primary->suggested_target_type,
                'target_url' => (string) $primary->page_url,
                'opportunity_score' => (float) ($primary->opportunity_score ?? 0),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
                'average_position' => $weightedPosition,
                'keyword_count' => $pageRows->pluck('keyword_id')->unique()->count(),
                'latest_metric_date' => $pageRows->max('latest_metric_date'),
                'rationale' => $this->rationaleForAction(
                    (string) $primary->suggested_action,
                    $impressions,
                    $clicks,
                    $weightedPosition
                ),
            ]);
        }

        return [
            'rows' => $result,
            'published_filtered' => $publishedFiltered,
            'published_keyword_filtered' => $publishedKeywordFiltered,
            'low_evidence_filtered' => $lowEvidenceFiltered,
            'migration_filtered' => $migrationFiltered,
        ];
    }

    /** @return array{rows:Collection<int,array<string,mixed>>,publication_mapped_filtered:int} */
    private function newContentCandidates(int $projectId, array $hosts, array $published): array
    {
        $rows = DB::table('seo_content_backlog as b')
            ->leftJoin('seo_keywords as k', 'k.id', '=', 'b.seo_keyword_id')
            ->where('b.business_project_id', $projectId)
            ->whereIn('b.content_type', ['blog', 'commercial_page', 'comparison_blog'])
            ->where('b.status', 'planned')
            ->where('k.suggested_action', 'NEW_CONTENT_P0')
            ->orderBy('b.id')
            ->get([
                'b.id',
                'b.content_type',
                'b.priority',
                'b.title',
                'b.rationale',
                'k.id as keyword_id',
                'k.keyword',
                'k.topic_cluster',
                'k.suggested_action',
                'k.suggested_target_type',
                'k.opportunity_score',
            ]);

        $result = collect();
        $publicationMappedFiltered = 0;

        foreach ($rows as $row) {
            $keywordId = $row->keyword_id === null ? null : (int) $row->keyword_id;
            $metrics = $keywordId
                ? $this->keywordMetrics($keywordId, $hosts)
                : ['impressions' => 0, 'clicks' => 0, 'average_position' => null, 'latest_metric_date' => null];

            if (
                $keywordId
                && $metrics['impressions'] > 0
                && $this->keywordMapsToPublishedUrl($keywordId, $hosts, $published)
            ) {
                $publicationMappedFiltered++;

                continue;
            }

            $result->push([
                'key' => 'content:'.$row->id,
                'kind' => 'new_content',
                'priority' => (string) ($row->priority ?: 'P0'),
                'decision' => match ((string) $row->content_type) {
                    'comparison_blog' => 'comparison_content',
                    'commercial_page' => 'new_commercial_page',
                    default => 'new_blog',
                },
                'keyword' => (string) ($row->keyword ?: $row->title),
                'topic_cluster' => $row->topic_cluster,
                'suggested_action' => (string) ($row->suggested_action ?: 'NEW_CONTENT_P0'),
                'suggested_target_type' => $row->suggested_target_type,
                'target_url' => null,
                'opportunity_score' => (float) ($row->opportunity_score ?? 0),
                'impressions' => $metrics['impressions'],
                'clicks' => $metrics['clicks'],
                'ctr' => $metrics['impressions'] > 0 ? $metrics['clicks'] / $metrics['impressions'] : 0.0,
                'average_position' => $metrics['average_position'],
                'keyword_count' => 1,
                'latest_metric_date' => $metrics['latest_metric_date'],
                'rationale' => (string) $row->rationale,
            ]);
        }

        return [
            'rows' => $result,
            'publication_mapped_filtered' => $publicationMappedFiltered,
        ];
    }

    private function keywordMapsToPublishedUrl(int $keywordId, array $hosts, array $published): bool
    {
        $query = DB::table('seo_gsc_daily_metrics')
            ->where('seo_keyword_id', $keywordId)
            ->where('impressions', '>', 0)
            ->whereNotNull('page_url');
        $this->applyHostScope($query, 'page_url', $hosts);

        foreach ($query->distinct()->pluck('page_url') as $url) {
            $normalized = $this->normalizeUrl((string) $url);
            if ($normalized !== '' && isset($published[$normalized])) {
                return true;
            }
        }

        return false;
    }

    /** @return array{impressions:int,clicks:int,average_position:?float,latest_metric_date:?string} */
    private function keywordMetrics(int $keywordId, array $hosts): array
    {
        $query = DB::table('seo_gsc_daily_metrics')
            ->where('seo_keyword_id', $keywordId)
            ->whereNotNull('page_url');
        $this->applyHostScope($query, 'page_url', $hosts);

        $row = $query->selectRaw(
            'SUM(impressions) impressions, SUM(clicks) clicks,
             SUM(average_position * impressions) / NULLIF(SUM(impressions),0) average_position,
             MAX(metric_date) latest_metric_date'
        )->first();

        return [
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'average_position' => $row?->average_position === null ? null : (float) $row->average_position,
            'latest_metric_date' => $row?->latest_metric_date,
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function monitoringRows(int $projectId): Collection
    {
        $publications = DB::table('seo_optimization_publications as p')
            ->join('seo_optimization_work_orders as w', 'w.id', '=', 'p.seo_optimization_work_order_id')
            ->leftJoin('seo_keywords as k', 'k.id', '=', 'w.primary_seo_keyword_id')
            ->where('w.business_project_id', $projectId)
            ->where('p.status', 'monitoring')
            ->orderBy('p.retest_after')
            ->get([
                'p.id',
                'p.seo_optimization_work_order_id',
                'p.baseline_url',
                'p.published_url',
                'p.production_post_id',
                'p.publish_date',
                'p.retest_after',
                'p.baseline_impressions',
                'p.baseline_clicks',
                'p.baseline_ctr',
                'p.baseline_average_position',
                'w.baseline_from',
                'w.baseline_to',
                'w.recommendations',
                'k.keyword',
                'k.topic_cluster',
            ]);

        if ($publications->isEmpty()) {
            return collect();
        }

        $measurements = DB::table('seo_optimization_measurements')
            ->whereIn('seo_optimization_publication_id', $publications->pluck('id')->all())
            ->where('url_role', 'published')
            ->whereIn('snapshot_label', ['T0', 'T28'])
            ->get()
            ->groupBy('seo_optimization_publication_id');

        $today = now()->startOfDay();

        return $publications->map(function ($publication) use ($measurements, $today): array {
            $byLabel = ($measurements[$publication->id] ?? collect())->keyBy('snapshot_label');
            $t0 = $byLabel->get('T0');
            $t28 = $byLabel->get('T28');
            $retest = Carbon::parse($publication->retest_after)->startOfDay();
            $daysRemaining = (int) $today->diffInDays($retest, false);
            $signal = $t28
                ? $this->retestSignal($publication, $t28)
                : ['state' => $daysRemaining <= 0 ? 'retest_due' : 'monitoring', 'position_delta' => null, 'impressions_daily_change' => null, 'clicks_daily_change' => null];

            $meta = json_decode($publication->recommendations ?? '{}', true) ?: [];
            $production = $meta['production'] ?? [];

            return [
                'publication_id' => (int) $publication->id,
                'work_order_id' => (int) $publication->seo_optimization_work_order_id,
                'keyword' => (string) ($publication->keyword ?: '—'),
                'topic_cluster' => $publication->topic_cluster,
                'baseline_url' => (string) $publication->baseline_url,
                'published_url' => (string) $publication->published_url,
                'production_post_id' => $publication->production_post_id === null ? null : (int) $publication->production_post_id,
                'publish_date' => (string) $publication->publish_date,
                'retest_after' => (string) $publication->retest_after,
                'days_remaining' => $daysRemaining,
                'owner_uat' => $production['owner_uat'] ?? null,
                'state' => $signal['state'],
                'baseline' => [
                    'impressions' => (int) $publication->baseline_impressions,
                    'clicks' => (int) $publication->baseline_clicks,
                    'average_position' => $publication->baseline_average_position === null ? null : (float) $publication->baseline_average_position,
                ],
                't0' => $this->measurementData($t0),
                't28' => $this->measurementData($t28),
                'signal' => [
                    'position_delta' => $signal['position_delta'],
                    'impressions_daily_change' => $signal['impressions_daily_change'],
                    'clicks_daily_change' => $signal['clicks_daily_change'],
                ],
            ];
        });
    }

    /** @return array{state:string,position_delta:?float,impressions_daily_change:?float,clicks_daily_change:?float} */
    private function retestSignal(object $publication, object $t28): array
    {
        $baselineDays = $this->dateSpanDays($publication->baseline_from, $publication->baseline_to);
        $t28Days = $this->dateSpanDays($t28->metric_from, $t28->metric_to);

        $baselineImpressionsDaily = (int) $publication->baseline_impressions / $baselineDays;
        $baselineClicksDaily = (int) $publication->baseline_clicks / $baselineDays;
        $t28ImpressionsDaily = (int) $t28->impressions / $t28Days;
        $t28ClicksDaily = (int) $t28->clicks / $t28Days;

        $impressionsChange = $baselineImpressionsDaily > 0
            ? ($t28ImpressionsDaily - $baselineImpressionsDaily) / $baselineImpressionsDaily
            : null;
        $clicksChange = $baselineClicksDaily > 0
            ? ($t28ClicksDaily - $baselineClicksDaily) / $baselineClicksDaily
            : ((int) $t28->clicks > 0 ? 1.0 : null);

        $positionDelta = ($publication->baseline_average_position !== null && $t28->average_position !== null)
            ? (float) $publication->baseline_average_position - (float) $t28->average_position
            : null;

        $winner = ($positionDelta !== null && $positionDelta >= 3.0)
            || ($impressionsChange !== null && $impressionsChange >= 0.20 && ($positionDelta === null || $positionDelta >= 0))
            || ($clicksChange !== null && $clicksChange >= 0.20 && ($positionDelta === null || $positionDelta >= 0));

        $loser = $impressionsChange !== null
            && $impressionsChange <= -0.20
            && $positionDelta !== null
            && $positionDelta <= -1.0;

        return [
            'state' => $winner ? 'winner' : ($loser ? 'loser' : 'mixed'),
            'position_delta' => $positionDelta,
            'impressions_daily_change' => $impressionsChange,
            'clicks_daily_change' => $clicksChange,
        ];
    }

    /** @return array<string,mixed>|null */
    private function measurementData(?object $row): ?array
    {
        if (! $row) {
            return null;
        }

        return [
            'metric_from' => $row->metric_from,
            'metric_to' => $row->metric_to,
            'impressions' => (int) $row->impressions,
            'clicks' => (int) $row->clicks,
            'ctr' => (float) $row->ctr,
            'average_position' => $row->average_position === null ? null : (float) $row->average_position,
            'distinct_queries' => (int) $row->distinct_queries,
            'competing_pages' => (int) $row->competing_pages,
        ];
    }

    /** @return array{rows:int,keywords:int,impressions:int,latest_metric_date:?string,international_rows:int,international_impressions:int} */
    private function scopeHealth(int $projectId, array $hosts): array
    {
        $base = DB::table('seo_gsc_daily_metrics as g')
            ->join('seo_keywords as k', 'k.id', '=', 'g.seo_keyword_id')
            ->where('k.business_project_id', $projectId)
            ->whereNotNull('g.page_url');

        $scoped = clone $base;
        $this->applyHostScope($scoped, 'g.page_url', $hosts);
        $summary = $scoped->selectRaw(
            'COUNT(*) rows_count, COUNT(DISTINCT g.seo_keyword_id) keyword_count,
             SUM(g.impressions) impressions, MAX(g.metric_date) latest_metric_date'
        )->first();

        $allRows = $base->get(['g.page_url', 'g.impressions']);
        $international = $allRows->filter(fn ($row) => ! $this->hostAllowed((string) $row->page_url, $hosts));

        return [
            'rows' => (int) ($summary->rows_count ?? 0),
            'keywords' => (int) ($summary->keyword_count ?? 0),
            'impressions' => (int) ($summary->impressions ?? 0),
            'latest_metric_date' => $summary?->latest_metric_date,
            'international_rows' => $international->count(),
            'international_impressions' => (int) $international->sum(fn ($row) => (int) $row->impressions),
        ];
    }

    /** @return array{planned:int,top:array<int,array<string,mixed>>} */
    private function internationalWorkOrders(int $projectId, array $hosts): array
    {
        $rows = DB::table('seo_optimization_work_orders')
            ->where('business_project_id', $projectId)
            ->where('workflow_status', 'planned')
            ->orderByDesc('priority_score')
            ->get(['id', 'target_url', 'gsc_impressions', 'gsc_clicks', 'gsc_average_position', 'priority_score']);

        $international = $rows->filter(fn ($row) => ! $this->hostAllowed((string) $row->target_url, $hosts));

        return [
            'planned' => $international->count(),
            'top' => $international->take(8)->map(fn ($row) => [
                'work_order_id' => (int) $row->id,
                'target_url' => (string) $row->target_url,
                'impressions' => (int) $row->gsc_impressions,
                'clicks' => (int) $row->gsc_clicks,
                'average_position' => $row->gsc_average_position === null ? null : (float) $row->gsc_average_position,
                'priority_score' => (float) $row->priority_score,
            ])->values()->all(),
        ];
    }

    private function applyHostScope(Builder $query, string $column, array $hosts): void
    {
        if ($hosts === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $scope) use ($column, $hosts): void {
            foreach ($hosts as $host) {
                foreach (['https://', 'http://'] as $scheme) {
                    $root = $scheme.$host;
                    $scope->orWhere($column, $root)
                        ->orWhere($column, 'like', $root.'/%');
                }
            }
        });
    }

    private function hostAllowed(string $url, array $hosts): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, $hosts, true);
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

    /** @return array{priority:string,decision:string}|null */
    private function qualityGate(int $impressions, int $clicks, ?float $position): ?array
    {
        if ($impressions < 20 || $position === null || $position <= 0) {
            return null;
        }

        $ctr = $clicks / max(1, $impressions);

        if ($position <= 3.0) {
            if ($impressions >= 100 && $ctr <= 0.02) {
                return ['priority' => 'P0', 'decision' => 'push_top3_ctr'];
            }

            return ['priority' => 'P1', 'decision' => 'defend'];
        }

        if ($position <= 10.0) {
            if ($impressions >= 100 && $ctr <= 0.02) {
                return ['priority' => 'P0', 'decision' => 'push_top3_ctr'];
            }
            if ($impressions >= 50 && $ctr <= 0.03) {
                return ['priority' => 'P1', 'decision' => 'improve_ctr'];
            }

            return ['priority' => 'P2', 'decision' => 'defend'];
        }

        if ($position <= 30.0) {
            return [
                'priority' => $impressions >= 50 ? 'P0' : 'P1',
                'decision' => 'push_page1',
            ];
        }

        return [
            'priority' => 'P2',
            'decision' => $impressions >= 50 ? 'optimize_existing' : 'research_existing',
        ];
    }

    /** @return array<string,true> */
    private function migrationBacklogKeySet(int $projectId): array
    {
        $rows = DB::table('seo_content_backlog')
            ->where('business_project_id', $projectId)
            ->where('content_type', 'blog')
            ->where('status', 'planned')
            ->where('rationale', 'like', 'Legacy migration cluster;%')
            ->pluck('title');

        $set = [];
        foreach ($rows as $title) {
            $key = strtolower(trim((string) $title));
            $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?: $key;
            $key = trim($key, '-');
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }

    private function legacyMigrationKeyFromUrl(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if (! str_starts_with($path, 'info/') && ! str_starts_with($path, 'news/')) {
            return null;
        }

        $slug = preg_replace('#^(info|news)/#', '', $path) ?: $path;
        $slug = preg_replace('#-\d+\.html$#', '', $slug) ?: $slug;
        $slug = preg_replace('#\.html$#', '', $slug) ?: $slug;
        $slug = strtolower(trim($slug));

        return $slug === '' ? null : $slug;
    }

    private function migrationContentPlanned(int $projectId): int
    {
        return DB::table('seo_content_backlog as b')
            ->leftJoin('seo_keywords as k', 'k.id', '=', 'b.seo_keyword_id')
            ->where('b.business_project_id', $projectId)
            ->whereIn('b.content_type', ['blog', 'commercial_page', 'comparison_blog'])
            ->where('b.status', 'planned')
            ->where(function (Builder $query): void {
                $query->whereNull('k.suggested_action')
                    ->orWhere('k.suggested_action', '<>', 'NEW_CONTENT_P0');
            })
            ->count();
    }

    private function decisionForAction(string $action): string
    {
        return match ($action) {
            'PAGE1_P0' => 'push_page1',
            'TOP3_CTR_P0' => 'push_top3_ctr',
            'TOP3_P1' => 'protect_top3',
            'CTR_P1' => 'improve_ctr',
            'DEFEND' => 'defend',
            default => 'optimize_existing',
        };
    }

    private function priorityForAction(string $action): string
    {
        return match ($action) {
            'PAGE1_P0', 'TOP3_CTR_P0' => 'P0',
            'TOP3_P1', 'CTR_P1' => 'P1',
            default => 'P2',
        };
    }

    private function rationaleForAction(string $action, int $impressions, int $clicks, ?float $position): string
    {
        $positionText = $position === null ? 'n/a' : number_format($position, 2);
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;

        return sprintf(
            '%s; scoped GSC impressions=%d clicks=%d CTR=%.2f%% avg_position=%s.',
            $action,
            $impressions,
            $clicks,
            $ctr * 100,
            $positionText
        );
    }

    private function dateSpanDays(?string $from, ?string $to): int
    {
        if (! $from || ! $to) {
            return 1;
        }

        return max(1, Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1);
    }

    private function priorityRank(string $priority): int
    {
        return match ($priority) {
            'P0' => 0,
            'P1' => 1,
            default => 2,
        };
    }

    private function monitoringRank(string $state): int
    {
        return match ($state) {
            'retest_due' => 0,
            'loser' => 1,
            'winner' => 2,
            'mixed' => 3,
            default => 4,
        };
    }
}

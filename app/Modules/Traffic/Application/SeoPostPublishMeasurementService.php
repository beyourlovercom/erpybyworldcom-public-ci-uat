<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class SeoPostPublishMeasurementService
{
    /** @return array<string,mixed> */
    public function register(string $projectKey, string $mode = 'dry_run', ?int $workOrderId = null): array
    {
        $project = BusinessProject::query()
            ->where('project_key', $projectKey)->where('status', 'active')->first();

        $summary = [
            'mode' => $mode, 'project_key' => $projectKey, 'work_order_id' => $workOrderId,
            'seen' => 0, 'applied' => 0, 'publications' => [], 'blocked' => [],
        ];
        if (! $project) {
            return $this->blocked($summary, 'project_not_found');
        }
        if (! in_array($mode, ['dry_run', 'apply'], true)) {
            return $this->blocked($summary, 'invalid_mode');
        }

        $orders = DB::table('seo_optimization_work_orders')
            ->where('business_project_id', $project->id)
            ->where('workflow_status', 'published')
            ->when($workOrderId, fn ($query) => $query->where('id', $workOrderId))
            ->orderBy('id')->get();
        foreach ($orders as $order) {
            $production = $this->productionMeta($order);
            if (! $production['post_id']) {
                continue;
            }

            $row = [
                'seo_optimization_work_order_id' => $order->id,
                'baseline_url' => $order->target_url,
                'published_url' => $production['published_url'],
                'production_post_id' => $production['post_id'],
                'publish_date' => $production['publish_date'],
                'retest_after' => $production['retest_after'],
                'baseline_impressions' => $order->gsc_impressions,
                'baseline_clicks' => $order->gsc_clicks,
                'baseline_ctr' => $order->gsc_ctr,
                'baseline_average_position' => $order->gsc_average_position,
                'status' => 'monitoring',
            ];
            $summary['publications'][] = $row;
        }

        $summary['seen'] = count($summary['publications']);
        if ($mode === 'apply') {
            DB::transaction(function () use (&$summary): void {
                foreach ($summary['publications'] as $row) {
                    DB::table('seo_optimization_publications')->updateOrInsert(
                        ['seo_optimization_work_order_id' => $row['seo_optimization_work_order_id']],
                        $row + ['updated_at' => now(), 'created_at' => now()]
                    );
                    $summary['applied']++;
                }
            });
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    public function snapshot(
        string $projectKey,
        string $label = 'T0',
        string $mode = 'dry_run',
        ?int $workOrderId = null
    ): array {
        $project = BusinessProject::query()
            ->where('project_key', $projectKey)->where('status', 'active')->first();

        $summary = [
            'mode' => $mode, 'project_key' => $projectKey, 'snapshot_label' => $label, 'work_order_id' => $workOrderId,
            'seen' => 0, 'applied' => 0, 'rows' => [], 'blocked' => [],
        ];
        if (! $project) {
            return $this->blocked($summary, 'project_not_found');
        }

        $publications = DB::table('seo_optimization_publications as p')
            ->join('seo_optimization_work_orders as w',
                'w.id', '=', 'p.seo_optimization_work_order_id')
            ->where('w.business_project_id', $project->id)
            ->where('p.status', 'monitoring')
            ->when($workOrderId, fn ($query) => $query->where('w.id', $workOrderId))
            ->select('p.*', 'w.primary_seo_keyword_id', 'w.supporting_keywords')
            ->orderBy('p.id')->get();

        foreach ($publications as $publication) {
            $keywordIds = $this->keywordIds($publication);
            $roles = (string)$publication->baseline_url === (string)$publication->published_url
                ? ['published' => $publication->published_url]
                : ['legacy' => $publication->baseline_url, 'published' => $publication->published_url];
            foreach ($roles as $role => $url) {
                $row = $this->measurement($publication, $keywordIds, $label, $role, $url);
                $summary['rows'][] = $row;
            }
        }
        $summary['seen'] = count($summary['rows']);
        if ($mode === 'apply') {
            DB::transaction(function () use (&$summary): void {
                foreach ($summary['rows'] as $row) {
                    $key = [
                        'seo_optimization_publication_id' => $row['seo_optimization_publication_id'],
                        'snapshot_label' => $row['snapshot_label'],
                        'url_role' => $row['url_role'],
                    ];
                    DB::table('seo_optimization_measurements')->updateOrInsert(
                        $key,
                        $row + ['updated_at' => now(), 'created_at' => now()]
                    );
                    $summary['applied']++;
                }
            });
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    private function measurement(
        object $publication, array $keywordIds, string $label, string $role, string $url
    ): array {
        [$from, $to] = $this->window($publication, $label);

        $q = DB::table('seo_gsc_daily_metrics')
            ->whereIn('seo_keyword_id', $keywordIds)
            ->where('page_url', $url);
        if ($from) {
            $q->whereDate('metric_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('metric_date', '<=', $to);
        }

        $metric = $q->selectRaw(
            'SUM(impressions) impressions, SUM(clicks) clicks,
             SUM(average_position * impressions) / NULLIF(SUM(impressions),0) avg_pos,
             MIN(metric_date) metric_from, MAX(metric_date) metric_to'
        )->first();
        $impressions = (int) ($metric->impressions ?? 0);
        $clicks = (int) ($metric->clicks ?? 0);
        $distinctQueries = DB::table('seo_gsc_daily_metrics')
            ->whereIn('seo_keyword_id', $keywordIds)->where('page_url', $url)
            ->when($from, fn ($x) => $x->whereDate('metric_date', '>=', $from))
            ->when($to, fn ($x) => $x->whereDate('metric_date', '<=', $to))
            ->distinct()->count('seo_keyword_id');

        $competingPages = DB::query()->fromSub(
            DB::table('seo_gsc_daily_metrics')
                ->whereIn('seo_keyword_id', $keywordIds)
                ->where('page_url', '<>', $url)
                ->when($from, fn ($x) => $x->whereDate('metric_date', '>=', $from))
                ->when($to, fn ($x) => $x->whereDate('metric_date', '<=', $to))
                ->select('page_url')->selectRaw('SUM(impressions) total_impressions')
                ->groupBy('page_url')->havingRaw('SUM(impressions) >= 10'),
            'competing'
        )->count();

        return [
            'seo_optimization_publication_id' => $publication->id,
            'snapshot_label' => $label,
            'url_role' => $role,
            'page_url' => $url,
            'metric_from' => $metric->metric_from ?? $from,
            'metric_to' => $metric->metric_to ?? $to,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
            'average_position' => $metric?->avg_pos === null ? null : (float) $metric->avg_pos,
            'distinct_queries' => $distinctQueries,
            'competing_pages' => $competingPages,
            'captured_at' => now(),
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function window(object $publication, string $label): array
    {
        $publish = Carbon::parse($publication->publish_date);
        if (strtoupper($label) === 'T0') {
            return [$publish->toDateString(), now()->toDateString()];
        }
        if (strtoupper($label) === 'T28') {
            return [$publish->toDateString(), Carbon::parse($publication->retest_after)->toDateString()];
        }

        return [$publish->toDateString(), now()->toDateString()];
    }

    /** @return int[] */
    private function keywordIds(object $publication): array
    {
        $ids = array_filter([(int) $publication->primary_seo_keyword_id]);
        $names = json_decode($publication->supporting_keywords ?? '[]', true) ?: [];
        if ($names) {
            $supporting = DB::table('seo_keywords')->whereIn('keyword', $names)->pluck('id')->all();
            $ids = array_merge($ids, array_map('intval', $supporting));
        }

        return array_values(array_unique($ids));
    }

    /** @return array{post_id:?int,publish_date:string,retest_after:string,published_url:string} */
    private function productionMeta(object $order): array
    {
        $meta = json_decode($order->recommendations ?? '{}', true) ?: [];
        $production = $meta['production'] ?? [];
        $publish = (string) ($production['publish_date'] ?? now()->toDateString());
        $retest = (string) ($production['retest_after'] ?? Carbon::parse($publish)->addDays(28)->toDateString());
        $explicitUrl = trim((string) ($production['canonical_url'] ?? $production['url'] ?? ''));
        $publishedUrl = $explicitUrl !== ''
            ? $explicitUrl
            : preg_replace(
                '#^https://www\.yby-irrigation\.com#',
                'https://ybyirrigation.com',
                (string) $order->target_url
            );

        return [
            'post_id' => isset($production['post_id']) ? (int) $production['post_id'] : null,
            'publish_date' => $publish,
            'retest_after' => $retest,
            'published_url' => $publishedUrl ?: (string) $order->target_url,
        ];
    }

    /** @param array<string,mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];

        return $summary;
    }
}

<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Support\Facades\DB;

final class SeoOptimizationWorkflowService
{
    /** @return array<string,mixed> */
    public function build(string $projectKey, string $mode = 'dry_run'): array
    {
        $project = BusinessProject::query()->where('project_key', $projectKey)->where('status', 'active')->first();
        $summary = ['mode'=>$mode,'project_key'=>$projectKey,'seen'=>0,'applied'=>0,'top'=>[],'blocked'=>[]];
        if (! $project) return $this->blocked($summary, 'project_not_found');
        if (! in_array($mode, ['dry_run','apply'], true)) return $this->blocked($summary, 'invalid_mode');

        $backlogs = DB::table('seo_content_backlog')
            ->where('business_project_id', $project->id)
            ->where('content_type', 'optimize_existing')
            ->where('status', 'optimize')
            ->whereNotNull('target_url')
            ->orderBy('id')->get();

        $orders = [];
        foreach ($backlogs as $backlog) {
            $orders[] = $this->orderPayload($project->id, $backlog);
        }
        $summary['seen'] = count($orders);
        $summary['top'] = collect($orders)->sortByDesc('priority_score')->take(10)
            ->map(fn(array $row) => [
                'target_url'=>$row['target_url'],
                'primary_keyword'=>$row['primary_keyword'],
                'impressions'=>$row['gsc_impressions'],
                'clicks'=>$row['gsc_clicks'],
                'ctr'=>$row['gsc_ctr'],
                'average_position'=>$row['gsc_average_position'],
                'priority_score'=>$row['priority_score'],
            ])->values()->all();

        if ($mode === 'apply') {
            DB::transaction(function () use ($project, $orders, &$summary): void {
                foreach ($orders as $row) {
                    $primaryKeyword = $row['primary_keyword'];
                    unset($row['primary_keyword']);
                    DB::table('seo_optimization_work_orders')->updateOrInsert(
                        ['business_project_id'=>$project->id,'target_url_hash'=>$row['target_url_hash']],
                        $row + ['updated_at'=>now(),'created_at'=>now()]
                    );
                    $summary['applied']++;
                }
            });
        }
        return $summary;
    }

    /** @return array<string,mixed> */
    private function orderPayload(int $projectId, object $backlog): array
    {
        $members = DB::table('seo_content_backlog as b')
            ->join('seo_keywords as k', 'k.id', '=', 'b.seo_keyword_id')
            ->where('b.business_project_id', $projectId)
            ->where('b.target_url', $backlog->target_url)
            ->whereIn('b.content_type', ['optimize_existing','cluster_member'])
            ->select('k.id','k.keyword')->get();
        $keywordIds = $members->pluck('id')->all();

        $metrics = DB::table('seo_gsc_daily_metrics')
            ->whereIn('seo_keyword_id', $keywordIds)
            ->where('page_url', $backlog->target_url)
            ->select('seo_keyword_id', DB::raw('SUM(impressions) impressions'), DB::raw('SUM(clicks) clicks'),
                DB::raw('SUM(average_position * impressions) / NULLIF(SUM(impressions),0) avg_pos'))
            ->groupBy('seo_keyword_id')->get()->keyBy('seo_keyword_id');

        $ranked = $members->map(function ($member) use ($metrics) {
            $metric = $metrics->get($member->id);
            return ['id'=>$member->id,'keyword'=>$member->keyword,
                'impressions'=>(int)($metric->impressions ?? 0),'clicks'=>(int)($metric->clicks ?? 0),
                'avg_pos'=>$metric?->avg_pos === null ? null : (float)$metric->avg_pos];
        })->sortByDesc('impressions')->values();
        $primary = $ranked->first();
        $supporting = $ranked->skip(1)->pluck('keyword')->values()->all();

        $totals = $this->totals($keywordIds, (string)$backlog->target_url);
        $ctr = $totals['impressions'] > 0 ? $totals['clicks'] / $totals['impressions'] : 0.0;
        $score = $this->priorityScore($totals['impressions'], $ctr, $totals['avg_pos']);
        return [
            'business_project_id'=>$projectId,
            'seo_content_backlog_id'=>$backlog->id,
            'primary_seo_keyword_id'=>$primary['id'] ?? $backlog->seo_keyword_id,
            'target_url_hash'=>hash('sha256', (string)$backlog->target_url),
            'target_url'=>(string)$backlog->target_url,
            'supporting_keywords'=>json_encode($supporting, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'gsc_impressions'=>$totals['impressions'],
            'gsc_clicks'=>$totals['clicks'],
            'gsc_ctr'=>$ctr,
            'gsc_average_position'=>$totals['avg_pos'],
            'priority_score'=>$score,
            'recommendations'=>json_encode($this->recommendations($primary['keyword'] ?? (string)$backlog->title, $supporting), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'workflow_status'=>'planned',
            'baseline_from'=>$totals['baseline_from'],
            'baseline_to'=>$totals['baseline_to'],
            'retest_after'=>$totals['baseline_to'] ? \Carbon\Carbon::parse($totals['baseline_to'])->addDays(28)->toDateString() : now()->addDays(28)->toDateString(),
            'primary_keyword'=>$primary['keyword'] ?? (string)$backlog->title,
        ];
    }

    /** @return array{impressions:int,clicks:int,avg_pos:?float,baseline_from:?string,baseline_to:?string} */
    private function totals(array $keywordIds, string $url): array
    {
        $row = DB::table('seo_gsc_daily_metrics')->whereIn('seo_keyword_id', $keywordIds)->where('page_url', $url)
            ->selectRaw('SUM(impressions) impressions, SUM(clicks) clicks, SUM(average_position * impressions) / NULLIF(SUM(impressions),0) avg_pos, MIN(metric_date) baseline_from, MAX(metric_date) baseline_to')->first();
        return ['impressions'=>(int)($row->impressions ?? 0),'clicks'=>(int)($row->clicks ?? 0),
            'avg_pos'=>$row?->avg_pos === null ? null : (float)$row->avg_pos,
            'baseline_from'=>$row?->baseline_from,'baseline_to'=>$row?->baseline_to];
    }
    private function priorityScore(int $impressions, float $ctr, ?float $position): float
    {
        $positionFactor = $position !== null && $position >= 4 && $position <= 30 ? 1.5 : 1.0;
        $ctrFactor = 1 + max(0, 0.03 - $ctr) * 10;
        return round($impressions * $positionFactor * $ctrFactor, 4);
    }

    /** @return array<string,string> */
    private function recommendations(string $primary, array $supporting): array
    {
        $support = $supporting ? implode(', ', array_slice($supporting, 0, 8)) : 'none';
        return [
            'title_h1'=>"Align Title and H1 around primary keyword: {$primary}; keep one canonical page.",
            'content'=>"Expand commercial/content coverage for supporting intents: {$support}.",
            'internal_links'=>'Add contextual internal links from related category/product/article pages with varied natural anchors.',
            'validation'=>'After publish, compare the same page cluster on impressions, clicks, CTR and average position after the retest window.',
        ];
    }

    /** @param array<string,mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];
        return $summary;
    }
}

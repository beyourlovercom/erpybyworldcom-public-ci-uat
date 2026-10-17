<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Support\Facades\DB;

final class SeoOpportunityRefeedService
{
    public function run(string $projectKey, string $mode = 'dry_run'): array
    {
        $project = BusinessProject::query()->where('project_key', $projectKey)->where('status', 'active')->first();
        if (! $project) return $this->blocked($mode, $projectKey, 'project_not_found');
        if (! in_array($mode, ['dry_run', 'apply'], true)) return $this->blocked($mode, $projectKey, 'invalid_mode');

        $rows = DB::table('seo_keywords as k')
            ->join('seo_gsc_daily_metrics as g', 'g.seo_keyword_id', '=', 'k.id')
            ->where('k.business_project_id', $project->id)
            ->whereNull('k.suggested_action')
            ->groupBy('k.id', 'k.keyword')
            ->select('k.id', 'k.keyword', DB::raw('SUM(g.impressions) impressions'), DB::raw('SUM(g.clicks) clicks'),
                DB::raw('SUM(g.average_position*g.impressions)/NULLIF(SUM(g.impressions),0) avg_position'),
                DB::raw("EXISTS(SELECT 1 FROM seo_keyword_evidence e WHERE e.seo_keyword_id=k.id AND e.source_type='google_ads' AND (e.clicks>0 OR e.conversions>0 OR e.cost>0)) has_commercial"))
            ->get();

        $summary = ['mode'=>$mode,'project_key'=>$projectKey,'seen'=>$rows->count(),'applied'=>0,'actions'=>[],'blocked'=>[]];
        $updates = [];
        foreach ($rows as $row) {
            $action = $this->classify((int)$row->impressions, (int)$row->clicks, (float)$row->avg_position, (bool)$row->has_commercial);
            $summary['actions'][$action] = ($summary['actions'][$action] ?? 0) + 1;
            $updates[] = [(int)$row->id, $action, $this->score((int)$row->impressions, (int)$row->clicks, (float)$row->avg_position)];
        }
        ksort($summary['actions']);
        if ($mode === 'dry_run') return $summary;

        DB::transaction(function () use ($updates, &$summary): void {
            foreach ($updates as [$id, $action, $score]) {
                DB::table('seo_keywords')->where('id', $id)->whereNull('suggested_action')->update([
                    'suggested_action' => $action,
                    'opportunity_score' => $score,
                    'updated_at' => now(),
                ]);
                $summary['applied']++;
            }
        });
        return $summary;
    }

    private function classify(int $impressions, int $clicks, float $position, bool $hasCommercial): string
    {
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;
        if ($hasCommercial && $position >= 11 && $position <= 30 && $impressions >= 50) return 'PAGE1_P0';
        if ($hasCommercial && $position > 0 && $position <= 10 && $impressions >= 100 && $ctr <= 0.02) return 'TOP3_CTR_P0';
        if ($impressions >= 20) return 'RESEARCH_P2';
        return 'BACKLOG_P3';
    }

    private function score(int $impressions, int $clicks, float $position): float
    {
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;
        $positionFactor = $position > 0 ? max(0.0, 40.0 - min($position, 40.0)) : 0.0;
        return round(min(100.0, log10(max(1, $impressions)) * 20.0 + $positionFactor + (1.0 - min(1.0, $ctr)) * 10.0), 2);
    }

    private function blocked(string $mode, string $projectKey, string $reason): array
    {
        return ['mode'=>$mode,'project_key'=>$projectKey,'seen'=>0,'applied'=>0,'actions'=>[],'blocked'=>[$reason]];
    }
}

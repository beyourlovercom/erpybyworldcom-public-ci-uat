<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Support\Facades\DB;

final class SeoContentBacklogService
{
    /** @return array<string,mixed> */
    public function generate(string $projectKey, string $mode = 'dry_run'): array
    {
        $project = BusinessProject::query()->where('project_key', $projectKey)->where('status', 'active')->first();
        $summary = ['mode'=>$mode,'project_key'=>$projectKey,'seen'=>0,'applied'=>0,'types'=>[],'blocked'=>[]];
        if (! $project) return $this->blocked($summary, 'project_not_found');
        if (! in_array($mode, ['dry_run','apply'], true)) return $this->blocked($summary, 'invalid_mode');

        $keywords = DB::table('seo_keywords as k')
            ->where('k.business_project_id', $project->id)
            ->whereIn('k.suggested_action', ['NEW_CONTENT_P0','PAGE1_P0','TOP3_CTR_P0'])
            ->orderByDesc('k.opportunity_score')->get();

        foreach ($keywords as $keyword) {
            $item = $this->item($project->id, $keyword);
            $summary['seen']++;
            $summary['types'][$item['content_type']] = ($summary['types'][$item['content_type']] ?? 0) + 1;
            if ($mode === 'apply') {
                DB::table('seo_content_backlog')->updateOrInsert(
                    ['business_project_id'=>$project->id,'seo_keyword_id'=>$keyword->id],
                    $item + ['updated_at'=>now(),'created_at'=>now()]
                );
                $summary['applied']++;
            }
        }
        ksort($summary['types']);
        return $summary;
    }

    /** @return array<string,mixed> */
    private function item(int $projectId, object $keyword): array
    {
        $existing = in_array($keyword->suggested_action, ['PAGE1_P0','TOP3_CTR_P0'], true);
        $gsc = DB::table('seo_gsc_daily_metrics')->where('seo_keyword_id', $keyword->id)
            ->select('page_url', DB::raw('SUM(impressions) impressions'), DB::raw('SUM(clicks) clicks'),
                DB::raw('SUM(average_position * impressions) / NULLIF(SUM(impressions),0) avg_pos'))
            ->groupBy('page_url')->orderByDesc('impressions')->first();

        $contentType = $existing ? 'optimize_existing' : $this->newContentType((string) $keyword->suggested_target_type);
        $priority = $keyword->suggested_action === 'NEW_CONTENT_P0' ? 'P0' : 'P0';
        $rationale = $gsc
            ? sprintf('%s; GSC impressions=%d clicks=%d avg_position=%.2f.', $keyword->suggested_action, $gsc->impressions, $gsc->clicks, $gsc->avg_pos ?? 0)
            : $keyword->suggested_action.'; no live GSC page mapped.';
        return [
            'topic_cluster'=>$keyword->topic_cluster,
            'content_type'=>$contentType,
            'priority'=>$priority,
            'status'=>$existing ? 'optimize' : 'planned',
            'title'=>(string) $keyword->keyword,
            'target_url'=>$existing ? ($gsc->page_url ?? null) : null,
            'rationale'=>$rationale,
        ];
    }

    private function newContentType(string $suggestedTarget): string
    {
        $value = strtolower(trim($suggestedTarget));
        return str_contains($value, 'blog') ? 'blog'
            : (str_contains($value, 'product') || str_contains($value, 'category') ? 'commercial_page' : 'content_review');
    }

    /** @param array<string,mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];
        return $summary;
    }
}

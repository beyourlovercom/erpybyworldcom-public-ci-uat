<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SeoTrafficWorkspaceService
{
    /** @return array<string, mixed> */
    public function build(string $projectKey, array $filters = []): array
    {
        $project = BusinessProject::query()
            ->where('project_key', $projectKey)
            ->where('status', 'active')
            ->first();

        if (! $project) {
            throw new InvalidArgumentException('Business project not found.');
        }

        $base = SeoKeyword::query()->where('business_project_id', $project->id);
        $query = clone $base;
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('keyword', 'like', "%{$search}%")
                    ->orWhere('topic_cluster', 'like', "%{$search}%");
            });
        }
        if ($action = trim((string) ($filters['action'] ?? ''))) {
            $query->where('suggested_action', $action);
        }
        if ($source = trim((string) ($filters['source'] ?? ''))) {
            $query->whereHas('evidence', fn ($evidence) => $evidence->where('source_type', $source));
        }

        $keywords = $query->with('evidence')
            ->orderByRaw('COALESCE(opportunity_score, 0) DESC')
            ->orderBy('keyword')
            ->paginate(50)
            ->withQueryString();

        $actionCounts = (clone $base)->select('suggested_action', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('suggested_action')->pluck('aggregate', 'suggested_action')->map(fn ($v) => (int) $v)->all();

        $evidenceCounts = DB::table('seo_keyword_evidence')
            ->join('seo_keywords', 'seo_keywords.id', '=', 'seo_keyword_evidence.seo_keyword_id')
            ->where('seo_keywords.business_project_id', $project->id)
            ->select('seo_keyword_evidence.source_type', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('seo_keyword_evidence.source_type')
            ->pluck('aggregate', 'source_type')->map(fn ($v) => (int) $v)->all();

        $gscLiveRows = DB::table('seo_gsc_daily_metrics')
            ->join('seo_keywords', 'seo_keywords.id', '=', 'seo_gsc_daily_metrics.seo_keyword_id')
            ->where('seo_keywords.business_project_id', $project->id)
            ->count();

        $priorityActions = ['NEW_CONTENT_P0', 'PAGE1_P0', 'TOP3_CTR_P0'];

        return [
            'project' => ['key' => $project->project_key, 'name' => $project->name],
            'filters' => ['search' => $search, 'action' => $filters['action'] ?? '', 'source' => $filters['source'] ?? ''],
            'summary' => [
                'keywords' => (clone $base)->count(),
                'evidence_rows' => array_sum($evidenceCounts),
                'priority_opportunities' => collect($priorityActions)->sum(fn ($key) => $actionCounts[$key] ?? 0),
                'gsc_live_rows' => $gscLiveRows,
                'content_backlog' => DB::table('seo_content_backlog')->where('business_project_id', $project->id)->count(),
            ],
            'action_counts' => $actionCounts,
            'evidence_counts' => $evidenceCounts,
            'actions' => array_values(array_filter(array_keys($actionCounts))),
            'sources' => array_keys($evidenceCounts),
            'keywords' => $this->keywordPaginator($keywords),
            'data_health' => [
                'seed_loaded' => (clone $base)->exists(),
                'gsc_live_connected' => $gscLiveRows > 0,
                'attribution_state' => $gscLiveRows > 0 ? 'live_query_page' : 'keyword_evidence_only',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function keywordPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn (SeoKeyword $keyword) => $this->keywordData($keyword))->values()->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private function keywordData(SeoKeyword $keyword): array
    {
        $sources = $keyword->evidence->keyBy('source_type');
        $ads = $sources->get('google_ads');
        $gsc = $sources->get('google_search_console');
        $alibaba = $sources->get('alibaba');

        return [
            'id' => $keyword->id,
            'keyword' => $keyword->keyword,
            'topic_cluster' => $keyword->topic_cluster,
            'suggested_action' => $keyword->suggested_action,
            'suggested_target_type' => $keyword->suggested_target_type,
            'opportunity_score' => $keyword->opportunity_score,
            'ads' => $ads ? [
                'clicks' => (int) $ads->clicks,
                'conversions' => (float) $ads->conversions,
                'cost' => (float) $ads->cost,
            ] : null,
            'gsc' => $gsc ? [
                'impressions' => (int) $gsc->impressions,
                'clicks' => (int) $gsc->clicks,
                'average_position' => $gsc->average_position === null ? null : (float) $gsc->average_position,
            ] : null,
            'alibaba' => $alibaba ? [
                'impressions' => (int) $alibaba->impressions,
                'clicks' => (int) $alibaba->clicks,
            ] : null,
        ];
    }
}

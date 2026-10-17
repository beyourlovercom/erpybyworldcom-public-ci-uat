<?php

namespace App\Modules\Content\Infrastructure\Yby;

use Illuminate\Support\Facades\DB;

final class YbyContentOpportunityQuery
{
    /** @return list<array<string, mixed>> */
    public function list(int $limit = 50): array
    {
        $existing = DB::table('content_articles')
            ->where('source_system', 'yby_seo_content_backlog')
            ->pluck('source_ref')
            ->map(fn ($value): string => (string) $value)
            ->all();

        return DB::table('seo_content_backlog as b')
            ->join('business_projects as p', 'p.id', '=', 'b.business_project_id')
            ->leftJoin('seo_keywords as k', 'k.id', '=', 'b.seo_keyword_id')
            ->where('p.status', 'active')
            ->where('b.status', 'planned')
            ->whereIn('b.content_type', ['blog', 'guide', 'comparison', 'comparison_blog'])
            ->when($existing !== [], fn ($query) => $query->whereNotIn('b.id', array_map('intval', $existing)))
            ->orderByRaw("CASE b.priority WHEN 'P0' THEN 0 WHEN 'P1' THEN 1 ELSE 2 END")
            ->orderByDesc('k.opportunity_score')
            ->orderBy('b.id')
            ->limit($limit)
            ->get([
                'b.id',
                'b.content_type',
                'b.priority',
                'b.title',
                'b.topic_cluster',
                'b.rationale',
                'p.project_key',
                'p.name as project_name',
                'k.keyword as primary_keyword',
                'k.intent',
                'k.opportunity_score',
            ])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'project_key' => (string) $row->project_key,
                'project_name' => (string) $row->project_name,
                'content_type' => (string) $row->content_type,
                'priority' => (string) $row->priority,
                'title' => (string) ($row->title ?: $row->primary_keyword ?: 'Untitled opportunity'),
                'topic_cluster' => $row->topic_cluster ? (string) $row->topic_cluster : null,
                'primary_keyword' => $row->primary_keyword ? (string) $row->primary_keyword : null,
                'intent' => $row->intent ? (string) $row->intent : null,
                'opportunity_score' => $row->opportunity_score !== null ? (float) $row->opportunity_score : null,
                'rationale' => $row->rationale ? (string) $row->rationale : null,
            ])
            ->all();
    }
}

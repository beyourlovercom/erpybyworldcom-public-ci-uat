<?php

namespace App\Modules\Content\Infrastructure\Yby;

use App\Modules\Content\Application\Contracts\ContentSourceAdapter;
use App\Modules\Content\Application\Data\ArticleBaseData;
use App\Modules\Content\Application\Exceptions\ContentSourceRejected;
use Illuminate\Support\Facades\DB;

final class YbySeoContentSourceAdapter implements ContentSourceAdapter
{
    public function createArticleBase(string $sourceRef): ArticleBaseData
    {
        if (! ctype_digit($sourceRef) || (int) $sourceRef < 1) {
            throw new ContentSourceRejected('invalid_source_ref');
        }

        $backlogId = (int) $sourceRef;
        $source = DB::table('seo_content_backlog as b')
            ->join('business_projects as p', 'p.id', '=', 'b.business_project_id')
            ->leftJoin('seo_keywords as k', 'k.id', '=', 'b.seo_keyword_id')
            ->where('b.id', $backlogId)
            ->select([
                'b.id',
                'b.business_project_id',
                'b.seo_keyword_id',
                'b.topic_cluster',
                'b.content_type',
                'b.priority',
                'b.status',
                'b.title',
                'b.target_url',
                'b.rationale',
                'p.project_key',
                'p.name as project_name',
                'p.status as project_status',
                'k.keyword as primary_keyword',
                'k.intent as search_intent',
                'k.suggested_action',
                'k.opportunity_score',
            ])
            ->first();

        if (! $source) {
            throw new ContentSourceRejected('source_not_found');
        }
        if ((string) $source->project_status !== 'active') {
            throw new ContentSourceRejected('project_not_active');
        }
        if ((string) $source->status !== 'planned') {
            throw new ContentSourceRejected('source_not_planned');
        }

        $contentType = $this->articleContentType((string) $source->content_type);

        if (DB::table('seo_optimization_work_orders')
            ->where('seo_content_backlog_id', $backlogId)
            ->exists()) {
            throw new ContentSourceRejected('source_already_has_work_order');
        }

        $site = DB::table('business_project_sites')
            ->where('business_project_id', $source->business_project_id)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if (! $site) {
            throw new ContentSourceRejected('target_site_missing');
        }

        $supportingKeywords = DB::table('seo_content_backlog as b')
            ->join('seo_keywords as k', 'k.id', '=', 'b.seo_keyword_id')
            ->where('b.business_project_id', $source->business_project_id)
            ->where('b.content_type', 'cluster_member')
            ->where('b.status', 'retired')
            ->where('b.rationale', 'like', '%#'.$backlogId.'%')
            ->orderBy('k.keyword')
            ->pluck('k.keyword')
            ->map(fn ($keyword): string => (string) $keyword)
            ->values()
            ->all();

        $evidence = $this->evidenceSummary($source);
        $references = $this->referenceSources($source->seo_keyword_id);

        return new ArticleBaseData(
            sourceSystem: 'yby_seo_content_backlog',
            sourceRef: (string) $source->id,
            projectKey: (string) $source->project_key,
            targetSite: (string) $site->domain_normalized,
            language: 'en',
            contentType: $contentType,
            titleDirection: (string) ($source->title ?: $source->primary_keyword ?: 'Untitled content opportunity'),
            brandKey: (string) $source->project_key,
            primaryKeyword: $source->primary_keyword ? (string) $source->primary_keyword : null,
            supportingKeywords: $supportingKeywords,
            searchIntent: $source->search_intent ? (string) $source->search_intent : null,
            topicCluster: $source->topic_cluster ? (string) $source->topic_cluster : null,
            canonicalPath: null,
            evidenceSummary: $evidence,
            referenceSources: $references,
        );
    }

    private function articleContentType(string $sourceType): string
    {
        return match ($sourceType) {
            'blog' => 'blog',
            'guide' => 'guide',
            'comparison', 'comparison_blog' => 'comparison',
            'commercial_page' => throw new ContentSourceRejected('product_flow_required'),
            'optimize_existing' => throw new ContentSourceRejected('existing_page_flow_required'),
            default => throw new ContentSourceRejected('unsupported_content_type'),
        };
    }

    /** @return array<string, mixed> */
    private function evidenceSummary(object $source): array
    {
        $summary = [
            'source_backlog_id' => (int) $source->id,
            'priority' => (string) $source->priority,
            'rationale' => $source->rationale ? (string) $source->rationale : null,
            'suggested_action' => $source->suggested_action ? (string) $source->suggested_action : null,
            'opportunity_score' => $source->opportunity_score !== null ? (float) $source->opportunity_score : null,
        ];

        if (! $source->seo_keyword_id) {
            return $summary;
        }

        $rows = DB::table('seo_gsc_daily_metrics')
            ->where('seo_keyword_id', $source->seo_keyword_id)
            ->get(['impressions', 'clicks', 'average_position']);

        $impressions = (int) $rows->sum('impressions');
        $clicks = (int) $rows->sum('clicks');
        $weightedPosition = 0.0;
        $weightedImpressions = 0;

        foreach ($rows as $row) {
            if ($row->average_position === null || (int) $row->impressions < 1) {
                continue;
            }
            $weightedPosition += (float) $row->average_position * (int) $row->impressions;
            $weightedImpressions += (int) $row->impressions;
        }

        $summary['gsc'] = [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'average_position' => $weightedImpressions > 0
                ? round($weightedPosition / $weightedImpressions, 4)
                : null,
        ];

        return $summary;
    }

    /** @return list<array{source_type: string, source_key: string|null}> */
    private function referenceSources(?int $keywordId): array
    {
        if (! $keywordId) {
            return [];
        }

        return DB::table('seo_keyword_evidence')
            ->where('seo_keyword_id', $keywordId)
            ->orderBy('source_type')
            ->orderBy('source_key')
            ->get(['source_type', 'source_key'])
            ->map(fn ($row): array => [
                'source_type' => (string) $row->source_type,
                'source_key' => $row->source_key !== null ? (string) $row->source_key : null,
            ])
            ->values()
            ->all();
    }
}

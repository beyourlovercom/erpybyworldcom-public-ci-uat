<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\Contracts\GuidelineRepository;
use App\Modules\Content\Application\Data\ArticleBaseData;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use DomainException;

final class EloquentGuidelineRepository implements GuidelineRepository
{
    public function articleBase(int $articleId): ArticleBaseData
    {
        $article = ContentArticle::query()->find($articleId);
        if (! $article) {
            throw new DomainException("Content article [{$articleId}] does not exist.");
        }

        return new ArticleBaseData(
            sourceSystem: (string) $article->source_system,
            sourceRef: (string) $article->source_ref,
            projectKey: (string) $article->project_key,
            targetSite: (string) $article->target_site,
            language: (string) $article->language,
            contentType: (string) $article->content_type,
            titleDirection: (string) $article->title_direction,
            brandKey: $article->brand_key ? (string) $article->brand_key : null,
            primaryKeyword: $article->primary_keyword ? (string) $article->primary_keyword : null,
            supportingKeywords: $article->supporting_keywords ?? [],
            searchIntent: $article->search_intent ? (string) $article->search_intent : null,
            topicCluster: $article->topic_cluster ? (string) $article->topic_cluster : null,
            canonicalPath: $article->canonical_path ? (string) $article->canonical_path : null,
            contentObjective: $article->content_objective ? (string) $article->content_objective : null,
            targetAudience: $article->target_audience ? (string) $article->target_audience : null,
            evidenceSummary: $article->evidence_summary ?? [],
            internalLinkTargets: $article->internal_link_targets ?? [],
            referenceSources: $article->reference_sources ?? [],
            prohibitedClaims: $article->prohibited_claims ?? [],
            recommendedStructure: $article->recommended_structure ?? [],
            recommendedWordCount: $article->recommended_word_count ? (int) $article->recommended_word_count : null,
        );
    }

    public function existing(int $articleId): ?array
    {
        $article = ContentArticle::query()->find($articleId);
        if (! $article) {
            throw new DomainException("Content article [{$articleId}] does not exist.");
        }

        return $article->guideline_json;
    }

    public function saveIfMissing(int $articleId, array $guideline): void
    {
        ContentArticle::query()
            ->whereKey($articleId)
            ->whereNull('guideline_json')
            ->update([
                'guideline_json' => json_encode($guideline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
}

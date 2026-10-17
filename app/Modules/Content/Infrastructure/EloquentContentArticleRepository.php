<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\ContentArticleRepository;
use App\Modules\Content\Application\Data\ArticleBaseData;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use DomainException;

final class EloquentContentArticleRepository implements ContentArticleRepository
{
    public function createFromBase(ArticleBaseData $article): int
    {
        $record = ContentArticle::query()->firstOrCreate(
            ['source_identity_hash' => $article->sourceIdentityHash()],
            [
                'source_system' => $article->sourceSystem,
                'source_ref' => $article->sourceRef,
                'project_key' => $article->projectKey,
                'brand_key' => $article->brandKey,
                'target_site' => $article->targetSite,
                'language' => $article->language,
                'content_type' => $article->contentType,
                'primary_keyword' => $article->primaryKeyword,
                'supporting_keywords' => $article->supportingKeywords,
                'search_intent' => $article->searchIntent,
                'topic_cluster' => $article->topicCluster,
                'title_direction' => $article->titleDirection,
                'canonical_path' => $article->canonicalPath,
                'content_objective' => $article->contentObjective,
                'target_audience' => $article->targetAudience,
                'evidence_summary' => $article->evidenceSummary,
                'internal_link_targets' => $article->internalLinkTargets,
                'reference_sources' => $article->referenceSources,
                'prohibited_claims' => $article->prohibitedClaims,
                'recommended_structure' => $article->recommendedStructure,
                'recommended_word_count' => $article->recommendedWordCount,
                'workflow_status' => ContentWorkflowStatus::ArticleBaseReady,
            ],
        );

        return (int) $record->getKey();
    }

    public function status(int $articleId): ContentWorkflowStatus
    {
        $status = ContentArticle::query()->whereKey($articleId)->value('workflow_status');

        if ($status === null) {
            throw new DomainException("Content article [{$articleId}] does not exist.");
        }

        return $status instanceof ContentWorkflowStatus
            ? $status
            : ContentWorkflowStatus::from((string) $status);
    }

    public function chooseAuthoringMode(
        int $articleId,
        ContentAuthoringMode $mode,
        ContentWorkflowStatus $nextStatus,
    ): void {
        $updated = ContentArticle::query()
            ->whereKey($articleId)
            ->whereNull('authoring_mode')
            ->where('workflow_status', ContentWorkflowStatus::ArticleBaseReady->value)
            ->update([
                'authoring_mode' => $mode->value,
                'workflow_status' => $nextStatus->value,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new DomainException("Content article [{$articleId}] authoring mode gate is already closed.");
        }
    }
}

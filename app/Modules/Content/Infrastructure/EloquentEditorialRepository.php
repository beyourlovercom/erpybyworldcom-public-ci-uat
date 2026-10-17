<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflow;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\EditorialRepository;
use App\Modules\Content\Application\Data\EditorialContextData;
use App\Modules\Content\Application\Data\EditorialRequestData;
use App\Modules\Content\Application\Data\EditorialResultData;
use App\Modules\Content\Application\Exceptions\EditorialException;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use Illuminate\Support\Facades\DB;

final class EloquentEditorialRepository implements EditorialRepository
{
    public function context(int $articleId): EditorialContextData
    {
        $article = ContentArticle::query()->find($articleId);
        if (! $article) {
            throw new EditorialException('article_not_found', "Content article [{$articleId}] does not exist.");
        }
        if (! $article->authoring_mode) {
            throw new EditorialException('authoring_mode_missing', 'Article authoring mode has not been selected.');
        }

        $sourceType = $article->authoring_mode === ContentAuthoringMode::Human
            ? 'writer_original'
            : 'ai_draft';
        $source = DB::table('content_snapshots')
            ->where('article_id', $articleId)
            ->where('type', $sourceType)
            ->orderByDesc('version')
            ->first();

        if (! $source) {
            throw new EditorialException('source_snapshot_missing', "Latest {$sourceType} snapshot is required.");
        }

        $normalized = json_decode((string) $source->normalized_json, true, flags: JSON_THROW_ON_ERROR);

        return new EditorialContextData(
            articleId: (int) $article->id,
            authoringMode: $article->authoring_mode,
            workflowStatus: $article->workflow_status,
            language: (string) $article->language,
            contentType: (string) $article->content_type,
            titleDirection: (string) $article->title_direction,
            guideline: $article->guideline_json ?? [],
            sourceType: $sourceType,
            sourceVersion: (int) $source->version,
            sourceHash: (string) $source->content_hash,
            sourceNormalizedJson: is_array($normalized) ? $normalized : [],
        );
    }

    public function existingVersionForSource(int $articleId, string $sourceHash): ?int
    {
        $snapshots = DB::table('content_snapshots')
            ->where('article_id', $articleId)
            ->where('type', 'editorial')
            ->orderByDesc('version')
            ->get(['version', 'normalized_json']);

        foreach ($snapshots as $snapshot) {
            $payload = json_decode((string) $snapshot->normalized_json, true);
            if (is_array($payload) && ($payload['source']['hash'] ?? null) === $sourceHash) {
                return (int) $snapshot->version;
            }
        }

        return null;
    }

    public function store(
        EditorialContextData $context,
        EditorialResultData $result,
        int $actorId,
    ): int {
        return DB::transaction(function () use ($context, $result, $actorId): int {
            $article = ContentArticle::query()->whereKey($context->articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== $context->workflowStatus) {
                throw new EditorialException('state_changed', 'Article state changed while AI editorial was running.');
            }

            $source = DB::table('content_snapshots')
                ->where('article_id', $context->articleId)
                ->where('type', $context->sourceType)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            if (! $source
                || (int) $source->version !== $context->sourceVersion
                || ! hash_equals((string) $source->content_hash, $context->sourceHash)) {
                throw new EditorialException('source_changed', 'Accepted source snapshot changed while AI editorial was running.');
            }

            $existing = $this->existingVersionForSource($context->articleId, $context->sourceHash);
            if ($existing !== null) {
                return $existing;
            }

            ContentWorkflow::assertTransition($article->workflow_status, ContentWorkflowStatus::EditorialReview);
            $from = $article->workflow_status;
            $article->forceFill(['workflow_status' => ContentWorkflowStatus::EditorialReview])->save();
            $this->event(
                $context->articleId,
                'editorial_review_started',
                $actorId,
                $from,
                ContentWorkflowStatus::EditorialReview,
                ['source_type' => $context->sourceType, 'source_version' => $context->sourceVersion],
            );

            $version = (int) DB::table('content_snapshots')
                ->where('article_id', $context->articleId)
                ->where('type', 'editorial')
                ->max('version') + 1;
            $request = EditorialRequestData::fromContext($context);
            $payload = $result->snapshot($request);

            DB::table('content_snapshots')->insert([
                'article_id' => $context->articleId,
                'type' => 'editorial',
                'version' => $version,
                'normalized_json' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                'content_hash' => hash(
                    'sha256',
                    json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ),
                'created_by_type' => 'ai',
                'created_by_id' => $result->provider.':'.$result->model,
                'created_at' => now(),
            ]);

            $this->event(
                $context->articleId,
                'editorial_snapshot_created',
                $actorId,
                null,
                null,
                [
                    'version' => $version,
                    'source_type' => $context->sourceType,
                    'source_version' => $context->sourceVersion,
                    'provider' => $result->provider,
                    'model' => $result->model,
                    'verification_required' => count($result->verificationRequired),
                ],
            );

            ContentWorkflow::assertTransition(ContentWorkflowStatus::EditorialReview, ContentWorkflowStatus::EditorialReady);
            $article->forceFill(['workflow_status' => ContentWorkflowStatus::EditorialReady])->save();
            $this->event(
                $context->articleId,
                'editorial_ready',
                $actorId,
                ContentWorkflowStatus::EditorialReview,
                ContentWorkflowStatus::EditorialReady,
                ['editorial_version' => $version],
            );

            return $version;
        }, 3);
    }

    /** @param array<string,mixed>|null $metadata */
    private function event(
        int $articleId,
        string $eventType,
        ?int $actorId,
        ?ContentWorkflowStatus $from,
        ?ContentWorkflowStatus $to,
        ?array $metadata = null,
    ): void {
        DB::table('content_article_events')->insert([
            'article_id' => $articleId,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'metadata' => $metadata
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created_at' => now(),
        ]);
    }
}

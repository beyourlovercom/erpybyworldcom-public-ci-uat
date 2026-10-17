<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflow;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\PublicationRepository;
use App\Modules\Content\Application\Exceptions\PublicationException;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use Illuminate\Support\Facades\DB;

final class EloquentPublicationRepository implements PublicationRepository
{
    public function previewContext(int $articleId): array
    {
        $article = ContentArticle::query()->find($articleId);
        if (! $article) {
            throw new PublicationException('article_not_found', "Content article [{$articleId}] does not exist.");
        }

        $editorial = DB::table('content_snapshots')
            ->where('article_id', $articleId)
            ->where('type', 'editorial')
            ->orderByDesc('version')
            ->first();

        if (! $editorial) {
            throw new PublicationException('editorial_snapshot_missing', 'Editorial snapshot is required before layout preview.');
        }

        return [
            'workflow_status' => $article->workflow_status->value,
            'editorial_snapshot_id' => (int) $editorial->id,
            'editorial_version' => (int) $editorial->version,
            'editorial_hash' => (string) $editorial->content_hash,
        ];
    }

    public function storePreview(int $articleId, int $editorialSnapshotId, array $preview, int $actorId): int
    {
        $encoded = json_encode(
            $preview,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $previewHash = hash('sha256', $encoded);

        return DB::transaction(function () use ($articleId, $editorialSnapshotId, $preview, $encoded, $previewHash, $actorId): int {
            $existing = DB::table('content_publications')
                ->where('article_id', $articleId)
                ->where('preview_hash', $previewHash)
                ->first();

            if ($existing) {
                return (int) DB::table('content_snapshots')
                    ->where('id', $existing->layout_snapshot_id)
                    ->value('version');
            }

            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::EditorialReady) {
                throw new PublicationException('state_changed', 'Article must still be editorial_ready when preview is stored.');
            }

            $editorial = DB::table('content_snapshots')
                ->where('id', $editorialSnapshotId)
                ->where('article_id', $articleId)
                ->where('type', 'editorial')
                ->lockForUpdate()
                ->first();

            if (! $editorial || (int) ($preview['source']['snapshot_id'] ?? 0) !== (int) $editorial->id) {
                throw new PublicationException('editorial_snapshot_changed', 'Editorial source changed while preview was being prepared.');
            }

            $version = (int) DB::table('content_snapshots')
                ->where('article_id', $articleId)
                ->where('type', 'layout')
                ->max('version') + 1;

            $layoutId = DB::table('content_snapshots')->insertGetId([
                'article_id' => $articleId,
                'type' => 'layout',
                'version' => $version,
                'normalized_json' => $encoded,
                'content_hash' => $previewHash,
                'created_by_type' => 'system',
                'created_by_id' => (string) $actorId,
                'created_at' => now(),
            ]);

            $verification = is_array($preview['verification_required'] ?? null)
                ? $preview['verification_required']
                : [];
            $resolvedAt = $verification === [] ? now() : null;
            $resolvedBy = $verification === [] ? $actorId : null;

            DB::table('content_publications')->insert([
                'article_id' => $articleId,
                'layout_snapshot_id' => $layoutId,
                'target_site' => (string) $article->target_site,
                'status' => 'preview_ready',
                'preview_hash' => $previewHash,
                'preview_json' => $encoded,
                'verification_resolved_at' => $resolvedAt,
                'verification_resolved_by' => $resolvedBy,
                'idempotency_key' => null,
                'provider' => null,
                'provider_request_id' => null,
                'external_id' => null,
                'published_url' => null,
                'published_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ContentWorkflow::assertTransition(ContentWorkflowStatus::EditorialReady, ContentWorkflowStatus::LayoutReady);
            $article->forceFill(['workflow_status' => ContentWorkflowStatus::LayoutReady])->save();

            $this->event(
                $articleId,
                'layout_preview_created',
                $actorId,
                ContentWorkflowStatus::EditorialReady,
                ContentWorkflowStatus::LayoutReady,
                [
                    'layout_version' => $version,
                    'editorial_snapshot_id' => $editorialSnapshotId,
                    'preview_hash' => $previewHash,
                    'verification_required' => count($verification),
                ],
            );

            return $version;
        }, 3);
    }

    public function resolveVerification(int $articleId, int $actorId): void
    {
        DB::transaction(function () use ($articleId, $actorId): void {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::LayoutReady) {
                throw new PublicationException('invalid_workflow_state', 'Verification may be resolved only while layout is ready.');
            }

            $publication = $this->latestPublication($articleId, true);
            if ($publication->verification_resolved_at !== null) {
                return;
            }

            DB::table('content_publications')->where('id', $publication->id)->update([
                'verification_resolved_at' => now(),
                'verification_resolved_by' => $actorId,
                'updated_at' => now(),
            ]);

            $this->event($articleId, 'publication_verification_resolved', $actorId, null, null, [
                'layout_snapshot_id' => (int) $publication->layout_snapshot_id,
            ]);
        }, 3);
    }

    public function ownerUatContext(int $articleId): array
    {
        return DB::transaction(function () use ($articleId): array {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::LayoutReady) {
                throw new PublicationException('invalid_workflow_state', 'Article must be layout_ready before Owner UAT.');
            }

            $publication = $this->latestPublication($articleId, true);
            $preview = json_decode((string) $publication->preview_json, true, flags: JSON_THROW_ON_ERROR);
            $verification = is_array($preview['verification_required'] ?? null)
                ? $preview['verification_required']
                : [];

            if ($verification !== [] && $publication->verification_resolved_at === null) {
                throw new PublicationException(
                    'verification_required',
                    'All publication verification items must be explicitly resolved before Owner UAT.',
                );
            }

            $draftIdempotencyKey = trim((string) ($publication->draft_idempotency_key ?? ''));
            if ($draftIdempotencyKey === '') {
                $draftIdempotencyKey = 'content-draft:'.$articleId.':'.(int) $publication->layout_snapshot_id.':'.substr((string) $publication->preview_hash, 0, 24);
                DB::table('content_publications')->where('id', $publication->id)->update([
                    'draft_idempotency_key' => $draftIdempotencyKey,
                    'updated_at' => now(),
                ]);
            }

            return [
                'layout_snapshot_id' => (int) $publication->layout_snapshot_id,
                'draft_idempotency_key' => $draftIdempotencyKey,
                'target_site' => (string) $publication->target_site,
            ];
        }, 3);
    }

    public function markDraftReady(int $articleId, array $result, int $actorId): void
    {
        DB::transaction(function () use ($articleId, $result, $actorId): void {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::LayoutReady) {
                throw new PublicationException('state_changed', 'Article state changed before draft result was recorded.');
            }

            $publication = $this->latestPublication($articleId, true);
            $externalId = trim((string) ($result['external_id'] ?? ''));
            if ($externalId === '' || (string) ($result['status'] ?? '') !== 'draft') {
                throw new PublicationException('provider_draft_invalid', 'Provider did not return a valid WordPress draft identity.');
            }
            if (isset($result['layout_snapshot_id']) && (int) $result['layout_snapshot_id'] !== (int) $publication->layout_snapshot_id) {
                throw new PublicationException('provider_draft_mismatch', 'Provider draft layout identity does not match the requested layout.');
            }
            if (isset($result['preview_hash']) && ! hash_equals((string) $publication->preview_hash, (string) $result['preview_hash'])) {
                throw new PublicationException('provider_draft_mismatch', 'Provider draft preview hash does not match the requested preview.');
            }
            if ($publication->external_id !== null && (string) $publication->external_id !== $externalId) {
                throw new PublicationException('provider_identity_mismatch', 'Provider returned a different WordPress post for the same publication.');
            }

            ContentWorkflow::assertTransition(ContentWorkflowStatus::LayoutReady, ContentWorkflowStatus::OwnerUat);

            DB::table('content_publications')->where('id', $publication->id)->update([
                'status' => 'owner_uat',
                'provider' => isset($result['provider']) ? (string) $result['provider'] : null,
                'draft_provider_request_id' => isset($result['request_id']) ? (string) $result['request_id'] : null,
                'external_id' => $externalId,
                'provider_status' => 'draft',
                'draft_url' => isset($result['url']) ? (string) $result['url'] : null,
                'draft_synced_at' => now(),
                'requested_indexing' => 'noindex',
                'updated_at' => now(),
            ]);

            $article->forceFill(['workflow_status' => ContentWorkflowStatus::OwnerUat])->save();

            $this->event(
                $articleId,
                'owner_uat_draft_ready',
                $actorId,
                ContentWorkflowStatus::LayoutReady,
                ContentWorkflowStatus::OwnerUat,
                [
                    'layout_snapshot_id' => (int) $publication->layout_snapshot_id,
                    'provider' => $result['provider'] ?? null,
                    'external_id' => $externalId,
                    'url' => $result['url'] ?? null,
                ],
            );
        }, 3);
    }

    public function approveWriter(int $articleId, int $actorId): void
    {
        DB::transaction(function () use ($articleId, $actorId): void {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::OwnerUat) {
                throw new PublicationException('invalid_workflow_state', 'Writer approval is available only for the final WordPress draft in Owner UAT.');
            }
            if (! $article->writer_id || (int) $article->writer_id !== $actorId) {
                throw new PublicationException('writer_approval_forbidden', 'Writer PASS must be recorded by the assigned human writer.');
            }

            $publication = $this->latestPublication($articleId, true);
            if ($publication->writer_approved_at !== null) {
                return;
            }

            DB::table('content_publications')->where('id', $publication->id)->update([
                'writer_approved_at' => now(),
                'writer_approved_by' => $actorId,
                'updated_at' => now(),
            ]);
            $this->event($articleId, 'writer_publication_approved', $actorId, null, null, ['layout_snapshot_id' => (int) $publication->layout_snapshot_id]);
        }, 3);
    }

    public function approveFact(int $articleId, int $actorId): void
    {
        $this->approvePublicationGate($articleId, $actorId, 'fact');
    }

    public function approveSeo(int $articleId, int $actorId): void
    {
        $this->approvePublicationGate($articleId, $actorId, 'seo');
    }

    public function publishContext(int $articleId): array
    {
        return DB::transaction(function () use ($articleId): array {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::OwnerUat) {
                throw new PublicationException('invalid_workflow_state', 'Article must be in owner_uat before publish.');
            }

            $publication = $this->latestPublication($articleId, true);
            if ((string) $publication->status !== 'owner_uat') {
                throw new PublicationException('publication_state_invalid', 'Publication record is not ready for Owner publish.');
            }
            if ($publication->draft_synced_at === null || trim((string) ($publication->external_id ?? '')) === '') {
                throw new PublicationException('provider_draft_missing', 'A real provider draft is required before final publish.');
            }
            $requiredGates = $article->authoring_mode === ContentAuthoringMode::Human
                ? ['writer', 'fact', 'seo']
                : ['fact', 'seo'];
            $missingGates = [];
            foreach ($requiredGates as $gate) {
                if ($publication->{$gate.'_approved_at'} === null) {
                    $missingGates[] = $gate;
                }
            }
            if ($missingGates !== []) {
                throw new PublicationException(
                    'publication_gates_required',
                    'Required publication approvals are missing: '.implode(', ', $missingGates).'.',
                );
            }

            $idempotencyKey = trim((string) ($publication->idempotency_key ?? ''));
            if ($idempotencyKey === '') {
                $idempotencyKey = 'content-publish:'.$articleId.':'.(int) $publication->layout_snapshot_id.':'.substr((string) $publication->preview_hash, 0, 24);
                DB::table('content_publications')->where('id', $publication->id)->update([
                    'idempotency_key' => $idempotencyKey,
                    'updated_at' => now(),
                ]);
            }

            return [
                'layout_snapshot_id' => (int) $publication->layout_snapshot_id,
                'idempotency_key' => $idempotencyKey,
                'target_site' => (string) $publication->target_site,
            ];
        }, 3);
    }

    public function markPublished(int $articleId, array $result, int $actorId): void
    {
        DB::transaction(function () use ($articleId, $result, $actorId): void {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::OwnerUat) {
                throw new PublicationException('state_changed', 'Article state changed before publish result was recorded.');
            }

            $publication = $this->latestPublication($articleId, true);
            $externalId = trim((string) ($result['external_id'] ?? ''));
            if ($externalId === '' || (string) ($result['status'] ?? '') !== 'publish') {
                throw new PublicationException('provider_publish_invalid', 'Provider did not return a valid published WordPress post.');
            }
            if (trim((string) ($publication->external_id ?? '')) !== $externalId) {
                throw new PublicationException('provider_identity_mismatch', 'Final publish returned a different WordPress post identity.');
            }
            if (isset($result['layout_snapshot_id']) && (int) $result['layout_snapshot_id'] !== (int) $publication->layout_snapshot_id) {
                throw new PublicationException('provider_publish_mismatch', 'Final publish layout identity does not match the Owner-approved layout.');
            }
            if (isset($result['preview_hash']) && ! hash_equals((string) $publication->preview_hash, (string) $result['preview_hash'])) {
                throw new PublicationException('provider_publish_mismatch', 'Final publish preview hash does not match the Owner-approved preview.');
            }

            ContentWorkflow::assertTransition(ContentWorkflowStatus::OwnerUat, ContentWorkflowStatus::Published);

            DB::table('content_publications')->where('id', $publication->id)->update([
                'status' => 'published',
                'provider' => isset($result['provider']) ? (string) $result['provider'] : $publication->provider,
                'provider_request_id' => isset($result['request_id']) ? (string) $result['request_id'] : null,
                'external_id' => $externalId,
                'provider_status' => 'publish',
                'requested_indexing' => 'index',
                'published_url' => isset($result['url']) ? (string) $result['url'] : null,
                'published_at' => now(),
                'updated_at' => now(),
            ]);

            $article->forceFill(['workflow_status' => ContentWorkflowStatus::Published])->save();

            $this->event(
                $articleId,
                'content_published',
                $actorId,
                ContentWorkflowStatus::OwnerUat,
                ContentWorkflowStatus::Published,
                [
                    'layout_snapshot_id' => (int) $publication->layout_snapshot_id,
                    'provider' => $result['provider'] ?? null,
                    'external_id' => $result['external_id'] ?? null,
                    'url' => $result['url'] ?? null,
                ],
            );
        }, 3);
    }

    private function approvePublicationGate(int $articleId, int $actorId, string $gate): void
    {
        DB::transaction(function () use ($articleId, $actorId, $gate): void {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || $article->workflow_status !== ContentWorkflowStatus::OwnerUat) {
                throw new PublicationException('invalid_workflow_state', 'Publication approvals are available only for the final WordPress draft in Owner UAT.');
            }

            $publication = $this->latestPublication($articleId, true);
            $atColumn = $gate.'_approved_at';
            $byColumn = $gate.'_approved_by';
            if ($publication->{$atColumn} !== null) {
                return;
            }

            DB::table('content_publications')->where('id', $publication->id)->update([
                $atColumn => now(),
                $byColumn => $actorId,
                'updated_at' => now(),
            ]);
            $this->event($articleId, $gate.'_publication_approved', $actorId, null, null, ['layout_snapshot_id' => (int) $publication->layout_snapshot_id]);
        }, 3);
    }

    private function latestPublication(int $articleId, bool $lock): object
    {
        $query = DB::table('content_publications')
            ->where('article_id', $articleId)
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $publication = $query->first();
        if (! $publication) {
            throw new PublicationException('publication_missing', 'Publication preview has not been prepared.');
        }

        return $publication;
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

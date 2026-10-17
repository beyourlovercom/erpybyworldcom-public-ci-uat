<?php

namespace App\Modules\Content\Infrastructure;

use App\Modules\Content\Application\ContentWorkflow;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Contracts\DocumentImportRepository;
use App\Modules\Content\Application\Data\ExternalDocumentData;
use App\Modules\Content\Application\Exceptions\DocumentImportException;
use App\Modules\Content\Infrastructure\Models\ContentArticle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentDocumentImportRepository implements DocumentImportRepository
{
    public function state(int $articleId): array
    {
        $article = ContentArticle::query()->find($articleId);
        if (! $article) {
            throw new DocumentImportException('article_not_found', "Content article [{$articleId}] does not exist.");
        }

        $source = DB::table('content_document_sources')->where('article_id', $articleId)->first();

        return [
            'writer_id' => $article->writer_id ? (int) $article->writer_id : null,
            'status' => $article->workflow_status,
            'document_source' => $source ? $this->sourcePayload($source) : null,
        ];
    }

    public function storeInitial(int $articleId, int $userId, ExternalDocumentData $document): int
    {
        return DB::transaction(function () use ($articleId, $userId, $document): int {
            $article = ContentArticle::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article || (int) $article->writer_id !== $userId || $article->workflow_status !== ContentWorkflowStatus::Writing) {
                throw new DocumentImportException('state_changed', 'Article state changed before document import.');
            }
            if (DB::table('content_document_sources')->where('article_id', $articleId)->exists()) {
                throw new DocumentImportException('document_already_submitted', 'A document source already exists.');
            }
            if (DB::table('content_document_sources')
                ->where('provider', $document->provider)
                ->where('document_id', $document->documentId)
                ->exists()) {
                throw new DocumentImportException('document_already_bound', 'This Google Doc is already bound to another article.');
            }

            ContentWorkflow::assertTransition(ContentWorkflowStatus::Writing, ContentWorkflowStatus::DocSubmitted);
            $article->forceFill(['workflow_status' => ContentWorkflowStatus::DocSubmitted])->save();
            $this->event($articleId, 'document_submitted', $userId, ContentWorkflowStatus::Writing, ContentWorkflowStatus::DocSubmitted);

            $sourceId = DB::table('content_document_sources')->insertGetId([
                'article_id' => $articleId,
                'provider' => $document->provider,
                'document_id' => $document->documentId,
                'document_url' => $document->documentUrl,
                'modified_time' => $this->databaseTimestamp($document->modifiedTime),
                'revision_id' => $document->revisionId,
                'submitted_at' => now(),
                'imported_at' => null,
                'snapshot_hash' => null,
                'changed_after_submission' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $version = 1;
            $this->snapshot($articleId, $userId, $document, $version);

            DB::table('content_document_sources')->where('id', $sourceId)->update([
                'imported_at' => now(),
                'snapshot_hash' => $document->contentHash(),
                'updated_at' => now(),
            ]);

            ContentWorkflow::assertTransition(ContentWorkflowStatus::DocSubmitted, ContentWorkflowStatus::DocImported);
            $article->forceFill(['workflow_status' => ContentWorkflowStatus::DocImported])->save();
            $this->event(
                $articleId,
                'document_imported',
                $userId,
                ContentWorkflowStatus::DocSubmitted,
                ContentWorkflowStatus::DocImported,
                ['snapshot_type' => 'writer_original', 'version' => $version, 'document_source_id' => $sourceId],
            );

            return $version;
        }, 3);
    }

    public function markChanged(int $articleId, ExternalDocumentData $document): bool
    {
        $source = DB::table('content_document_sources')->where('article_id', $articleId)->first();
        if (! $source) {
            throw new DocumentImportException('document_source_missing', 'Article does not have a submitted document.');
        }
        if ((string) $source->provider !== $document->provider || (string) $source->document_id !== $document->documentId) {
            throw new DocumentImportException('document_identity_mismatch', 'Google Doc identity changed unexpectedly.');
        }

        $changed = ! hash_equals((string) ($source->snapshot_hash ?? ''), $document->contentHash());

        DB::table('content_document_sources')->where('id', $source->id)->update([
            'modified_time' => $this->databaseTimestamp($document->modifiedTime),
            'revision_id' => $document->revisionId,
            'changed_after_submission' => $changed,
            'updated_at' => now(),
        ]);

        return $changed;
    }

    public function storeReimport(int $articleId, int $userId, ExternalDocumentData $document): int
    {
        return DB::transaction(function () use ($articleId, $userId, $document): int {
            $source = DB::table('content_document_sources')->where('article_id', $articleId)->lockForUpdate()->first();
            if (! $source) {
                throw new DocumentImportException('document_source_missing', 'Article does not have a submitted document.');
            }
            if ((string) $source->provider !== $document->provider || (string) $source->document_id !== $document->documentId) {
                throw new DocumentImportException('document_identity_mismatch', 'Google Doc identity changed unexpectedly.');
            }
            if (hash_equals((string) ($source->snapshot_hash ?? ''), $document->contentHash())) {
                throw new DocumentImportException('document_unchanged', 'Google Doc content has not changed since the accepted snapshot.');
            }

            $version = (int) DB::table('content_snapshots')
                ->where('article_id', $articleId)
                ->where('type', 'writer_original')
                ->max('version') + 1;

            $this->snapshot($articleId, $userId, $document, $version);

            DB::table('content_document_sources')->where('id', $source->id)->update([
                'document_url' => $document->documentUrl,
                'modified_time' => $this->databaseTimestamp($document->modifiedTime),
                'revision_id' => $document->revisionId,
                'imported_at' => now(),
                'snapshot_hash' => $document->contentHash(),
                'changed_after_submission' => false,
                'updated_at' => now(),
            ]);

            $this->event(
                $articleId,
                'document_reimported',
                $userId,
                null,
                null,
                ['snapshot_type' => 'writer_original', 'version' => $version],
            );

            return $version;
        }, 3);
    }

    private function snapshot(int $articleId, int $userId, ExternalDocumentData $document, int $version): void
    {
        DB::table('content_snapshots')->insert([
            'article_id' => $articleId,
            'type' => 'writer_original',
            'version' => $version,
            'normalized_json' => json_encode(
                $document->normalizedJson,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'content_hash' => $document->contentHash(),
            'created_by_type' => 'user',
            'created_by_id' => (string) $userId,
            'created_at' => now(),
        ]);
    }

    private function databaseTimestamp(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->utc();
    }

    /** @return array<string, mixed> */
    private function sourcePayload(object $source): array
    {
        return [
            'id' => (int) $source->id,
            'provider' => (string) $source->provider,
            'document_id' => (string) $source->document_id,
            'document_url' => (string) $source->document_url,
            'modified_time' => $source->modified_time ? Carbon::parse($source->modified_time)->toIso8601String() : null,
            'revision_id' => $source->revision_id ? (string) $source->revision_id : null,
            'submitted_at' => $source->submitted_at ? Carbon::parse($source->submitted_at)->toIso8601String() : null,
            'imported_at' => $source->imported_at ? Carbon::parse($source->imported_at)->toIso8601String() : null,
            'snapshot_hash' => $source->snapshot_hash ? (string) $source->snapshot_hash : null,
            'changed_after_submission' => (bool) $source->changed_after_submission,
        ];
    }

    /** @param array<string, mixed>|null $metadata */
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
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
        ]);
    }
}

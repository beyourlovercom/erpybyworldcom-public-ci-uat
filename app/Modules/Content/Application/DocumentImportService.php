<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Application\Contracts\DocumentImportRepository;
use App\Modules\Content\Application\Contracts\DocumentReadClient;
use App\Modules\Content\Application\Exceptions\DocumentImportException;

final readonly class DocumentImportService
{
    public function __construct(
        private DocumentReadClient $documents,
        private DocumentImportRepository $repository,
    ) {
    }

    public function submit(int $articleId, int $userId, string $documentUrl): int
    {
        $state = $this->repository->state($articleId);
        $this->assertActor($state['writer_id'], $userId, false);

        if ($state['status'] !== ContentWorkflowStatus::Writing) {
            throw new DocumentImportException('invalid_workflow_state', 'Article must be in writing state before Google Doc submission.');
        }
        if ($state['document_source'] !== null) {
            throw new DocumentImportException('document_already_submitted', 'A document source already exists; use explicit re-import instead.');
        }

        $document = $this->documents->fetch($documentUrl);

        return $this->repository->storeInitial($articleId, $userId, $document);
    }

    public function checkChanged(int $articleId, int $userId, bool $canManage = false): bool
    {
        $state = $this->repository->state($articleId);
        $this->assertActor($state['writer_id'], $userId, $canManage);
        $source = $state['document_source'];

        if ($source === null) {
            throw new DocumentImportException('document_source_missing', 'Article does not have a submitted Google Doc.');
        }

        $document = $this->documents->fetch((string) $source['document_url']);

        return $this->repository->markChanged($articleId, $document);
    }

    public function reimport(int $articleId, int $userId, bool $canManage = false): int
    {
        $state = $this->repository->state($articleId);
        $this->assertActor($state['writer_id'], $userId, $canManage);
        $source = $state['document_source'];

        if ($source === null) {
            throw new DocumentImportException('document_source_missing', 'Article does not have a submitted Google Doc.');
        }
        if (! in_array($state['status'], [ContentWorkflowStatus::DocImported, ContentWorkflowStatus::ReturnedForRevision], true)) {
            throw new DocumentImportException('invalid_workflow_state', 'Re-import is allowed only before the article leaves the writer revision stage.');
        }

        $document = $this->documents->fetch((string) $source['document_url']);
        $changed = $this->repository->markChanged($articleId, $document);

        if (! $changed) {
            throw new DocumentImportException('document_unchanged', 'Google Doc content has not changed since the accepted snapshot.');
        }

        return $this->repository->storeReimport($articleId, $userId, $document);
    }

    private function assertActor(?int $writerId, int $userId, bool $canManage): void
    {
        if (! $canManage && $writerId !== $userId) {
            throw new DocumentImportException('article_not_owned', 'Only the assigned writer may submit or refresh this document.');
        }
    }
}

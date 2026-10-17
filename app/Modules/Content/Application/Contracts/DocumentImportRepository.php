<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Data\ExternalDocumentData;

interface DocumentImportRepository
{
    /** @return array{writer_id:int|null,status:ContentWorkflowStatus,document_source:array<string,mixed>|null} */
    public function state(int $articleId): array;

    public function storeInitial(int $articleId, int $userId, ExternalDocumentData $document): int;

    public function markChanged(int $articleId, ExternalDocumentData $document): bool;

    public function storeReimport(int $articleId, int $userId, ExternalDocumentData $document): int;
}

<?php

namespace App\Modules\Content\Application\Contracts;

interface PublicationRepository
{
    /** @return array<string,mixed> */
    public function previewContext(int $articleId): array;

    /** @param array<string,mixed> $preview */
    public function storePreview(int $articleId, int $editorialSnapshotId, array $preview, int $actorId): int;

    public function resolveVerification(int $articleId, int $actorId): void;

    /** @return array<string,mixed> */
    public function ownerUatContext(int $articleId): array;

    /** @param array<string,mixed> $result */
    public function markDraftReady(int $articleId, array $result, int $actorId): void;

    public function approveWriter(int $articleId, int $actorId): void;

    public function approveFact(int $articleId, int $actorId): void;

    public function approveSeo(int $articleId, int $actorId): void;

    /** @return array<string,mixed> */
    public function publishContext(int $articleId): array;

    /** @param array<string,mixed> $result */
    public function markPublished(int $articleId, array $result, int $actorId): void;
}

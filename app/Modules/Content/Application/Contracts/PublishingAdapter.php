<?php

namespace App\Modules\Content\Application\Contracts;

interface PublishingAdapter
{
    /** @return array<string, mixed> */
    public function preview(int $articleId, int $snapshotId): array;

    /** @return array<string, mixed> */
    public function publish(int $articleId, int $snapshotId, string $idempotencyKey): array;
}

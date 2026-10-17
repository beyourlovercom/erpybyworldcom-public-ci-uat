<?php

namespace App\Modules\Content\Application\Contracts;

interface DraftPublishingAdapter
{
    /** @return array<string,mixed> */
    public function draft(int $articleId, int $snapshotId, string $idempotencyKey): array;
}

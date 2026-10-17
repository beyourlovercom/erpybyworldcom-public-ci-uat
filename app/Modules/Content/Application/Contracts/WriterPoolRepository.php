<?php

namespace App\Modules\Content\Application\Contracts;

interface WriterPoolRepository
{
    /** @return list<array<string, mixed>> */
    public function claimableFor(int $userId, bool $allScopes = false): array;

    /** @return list<array<string, mixed>> */
    public function mineFor(int $userId): array;

    public function claimNext(int $userId, bool $allScopes = false, int $claimHours = 24): ?int;

    public function startWriting(int $articleId, int $userId): void;

    public function release(int $articleId, int $userId): void;
}

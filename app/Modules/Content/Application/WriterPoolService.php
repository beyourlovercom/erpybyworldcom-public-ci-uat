<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Application\Contracts\WriterPoolRepository;

final readonly class WriterPoolService
{
    public function __construct(private WriterPoolRepository $repository)
    {
    }

    /** @return list<array<string, mixed>> */
    public function pool(int $userId, bool $allScopes = false): array
    {
        return $this->repository->claimableFor($userId, $allScopes);
    }

    /** @return list<array<string, mixed>> */
    public function mine(int $userId): array
    {
        return $this->repository->mineFor($userId);
    }

    public function claimNext(int $userId, bool $allScopes = false): ?int
    {
        return $this->repository->claimNext($userId, $allScopes, 24);
    }

    public function startWriting(int $articleId, int $userId): void
    {
        $this->repository->startWriting($articleId, $userId);
    }

    public function release(int $articleId, int $userId): void
    {
        $this->repository->release($articleId, $userId);
    }
}

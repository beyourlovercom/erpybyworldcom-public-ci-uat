<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\Data\EditorialContextData;
use App\Modules\Content\Application\Data\EditorialResultData;

interface EditorialRepository
{
    public function context(int $articleId): EditorialContextData;

    public function existingVersionForSource(int $articleId, string $sourceHash): ?int;

    public function store(
        EditorialContextData $context,
        EditorialResultData $result,
        int $actorId,
    ): int;
}

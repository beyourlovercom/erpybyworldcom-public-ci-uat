<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\Data\ArticleBaseData;

interface GuidelineRepository
{
    public function articleBase(int $articleId): ArticleBaseData;

    /** @return array<string, mixed>|null */
    public function existing(int $articleId): ?array;

    /** @param array<string, mixed> $guideline */
    public function saveIfMissing(int $articleId, array $guideline): void;
}

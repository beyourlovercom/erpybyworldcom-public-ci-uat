<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\Data\ArticleBaseData;

interface BrandContextProvider
{
    /** @return array<string, mixed> */
    public function contextFor(ArticleBaseData $article): array;
}

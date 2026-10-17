<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\Data\ArticleBaseData;

interface ContentSourceAdapter
{
    public function createArticleBase(string $sourceRef): ArticleBaseData;
}

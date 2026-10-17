<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\Data\ArticleBaseData;

interface InternalLinkProvider
{
    /** @return list<array<string, mixed>> */
    public function linksFor(ArticleBaseData $article): array;
}

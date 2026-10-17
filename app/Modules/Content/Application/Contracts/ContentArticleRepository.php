<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflowStatus;
use App\Modules\Content\Application\Data\ArticleBaseData;

interface ContentArticleRepository
{
    public function createFromBase(ArticleBaseData $article): int;

    public function status(int $articleId): ContentWorkflowStatus;

    public function chooseAuthoringMode(
        int $articleId,
        ContentAuthoringMode $mode,
        ContentWorkflowStatus $nextStatus,
    ): void;
}

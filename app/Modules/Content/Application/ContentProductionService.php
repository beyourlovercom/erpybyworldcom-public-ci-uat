<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Application\Contracts\ContentArticleRepository;
use App\Modules\Content\Application\Contracts\ContentSourceAdapter;

final readonly class ContentProductionService
{
    public function __construct(private ContentArticleRepository $articles)
    {
    }

    public function createArticleBase(ContentSourceAdapter $source, string $sourceRef): int
    {
        return $this->articles->createFromBase($source->createArticleBase($sourceRef));
    }

    public function chooseAuthoringMode(int $articleId, ContentAuthoringMode $mode): ContentWorkflowStatus
    {
        $current = $this->articles->status($articleId);
        $next = ContentWorkflow::nextAfterAuthoringMode($mode);
        ContentWorkflow::assertTransition($current, $next);
        $this->articles->chooseAuthoringMode($articleId, $mode, $next);

        return $next;
    }
}

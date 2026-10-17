<?php

namespace App\Modules\Content\Application\Data;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflowStatus;

final readonly class EditorialContextData
{
    /** @param array<string,mixed> $guideline @param array<string,mixed> $sourceNormalizedJson */
    public function __construct(
        public int $articleId,
        public ContentAuthoringMode $authoringMode,
        public ContentWorkflowStatus $workflowStatus,
        public string $language,
        public string $contentType,
        public string $titleDirection,
        public array $guideline,
        public string $sourceType,
        public int $sourceVersion,
        public string $sourceHash,
        public array $sourceNormalizedJson,
    ) {}
}

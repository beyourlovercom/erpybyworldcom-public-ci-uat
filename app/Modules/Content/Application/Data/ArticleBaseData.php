<?php

namespace App\Modules\Content\Application\Data;

final readonly class ArticleBaseData
{
    /** @param list<string> $supportingKeywords */
    public function __construct(
        public string $sourceSystem,
        public string $sourceRef,
        public string $projectKey,
        public string $targetSite,
        public string $language,
        public string $contentType,
        public string $titleDirection,
        public ?string $brandKey = null,
        public ?string $primaryKeyword = null,
        public array $supportingKeywords = [],
        public ?string $searchIntent = null,
        public ?string $topicCluster = null,
        public ?string $canonicalPath = null,
        public ?string $contentObjective = null,
        public ?string $targetAudience = null,
        public array $evidenceSummary = [],
        public array $internalLinkTargets = [],
        public array $referenceSources = [],
        public array $prohibitedClaims = [],
        public array $recommendedStructure = [],
        public ?int $recommendedWordCount = null,
    ) {
    }

    public function sourceIdentityHash(): string
    {
        return hash('sha256', implode('|', [
            $this->projectKey,
            $this->targetSite,
            $this->sourceSystem,
            $this->sourceRef,
        ]));
    }
}

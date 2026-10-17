<?php

namespace App\Modules\Content\Application\Data;

final readonly class EditorialRequestData
{
    /** @param array<string,mixed> $guideline @param array<string,mixed> $sourceNormalizedJson */
    public function __construct(
        public int $articleId,
        public string $language,
        public string $contentType,
        public string $titleDirection,
        public array $guideline,
        public string $sourceType,
        public int $sourceVersion,
        public string $sourceHash,
        public array $sourceNormalizedJson,
    ) {}

    public static function fromContext(EditorialContextData $context): self
    {
        return new self(
            $context->articleId,
            $context->language,
            $context->contentType,
            $context->titleDirection,
            $context->guideline,
            $context->sourceType,
            $context->sourceVersion,
            $context->sourceHash,
            $context->sourceNormalizedJson,
        );
    }
}

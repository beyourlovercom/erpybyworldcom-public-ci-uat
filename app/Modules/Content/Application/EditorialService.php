<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Application\Contracts\EditorialAiClient;
use App\Modules\Content\Application\Contracts\EditorialRepository;
use App\Modules\Content\Application\Data\EditorialRequestData;
use App\Modules\Content\Application\Exceptions\EditorialException;

final readonly class EditorialService
{
    public function __construct(
        private EditorialRepository $repository,
        private EditorialAiClient $ai,
    ) {}

    public function available(): bool
    {
        return $this->ai->available();
    }

    public function run(int $articleId, int $actorId): int
    {
        $context = $this->repository->context($articleId);

        $existing = $this->repository->existingVersionForSource($articleId, $context->sourceHash);
        if ($existing !== null) {
            return $existing;
        }

        if (! in_array($context->workflowStatus, [
            ContentWorkflowStatus::DocImported,
            ContentWorkflowStatus::DraftReady,
        ], true)) {
            throw new EditorialException(
                'invalid_workflow_state',
                'Editorial may start only from an accepted human import or AI draft.',
            );
        }

        if ($context->guideline === []) {
            throw new EditorialException('guideline_missing', 'Article guideline is required before editorial.');
        }

        if (! $this->ai->available()) {
            throw new EditorialException('provider_not_configured', 'AI editorial provider is not configured.');
        }

        $result = $this->ai->edit(EditorialRequestData::fromContext($context));

        if ($result->blocks === []) {
            throw new EditorialException('provider_invalid_response', 'AI editorial response did not contain content blocks.');
        }

        return $this->repository->store($context, $result, $actorId);
    }
}

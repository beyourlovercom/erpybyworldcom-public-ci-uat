<?php

namespace App\Modules\Content\Application;

use App\Modules\Content\Application\Contracts\DraftPublishingAdapter;
use App\Modules\Content\Application\Contracts\PublicationRepository;
use App\Modules\Content\Application\Contracts\PublishingAdapter;
use App\Modules\Content\Application\Exceptions\PublicationException;

final readonly class PublicationService
{
    public function __construct(
        private PublicationRepository $repository,
        private PublishingAdapter $publishing,
        private DraftPublishingAdapter $draftPublishing,
    ) {}

    public function preparePreview(int $articleId, int $actorId): int
    {
        $context = $this->repository->previewContext($articleId);
        $status = ContentWorkflowStatus::from((string) $context['workflow_status']);

        if (! in_array($status, [
            ContentWorkflowStatus::EditorialReady,
            ContentWorkflowStatus::LayoutReady,
            ContentWorkflowStatus::OwnerUat,
        ], true)) {
            throw new PublicationException(
                'invalid_workflow_state',
                'Preview may be prepared only after Editorial is ready.',
            );
        }

        $preview = $this->publishing->preview($articleId, (int) $context['editorial_snapshot_id']);

        return $this->repository->storePreview(
            $articleId,
            (int) $context['editorial_snapshot_id'],
            $preview,
            $actorId,
        );
    }

    public function resolveVerification(int $articleId, int $actorId): void
    {
        $this->repository->resolveVerification($articleId, $actorId);
    }

    public function submitOwnerUat(int $articleId, int $actorId): void
    {
        $context = $this->repository->ownerUatContext($articleId);

        $result = $this->draftPublishing->draft(
            $articleId,
            (int) $context['layout_snapshot_id'],
            (string) $context['draft_idempotency_key'],
        );

        $this->repository->markDraftReady($articleId, $result, $actorId);
    }

    public function approveWriter(int $articleId, int $actorId): void
    {
        $this->repository->approveWriter($articleId, $actorId);
    }

    public function approveFact(int $articleId, int $actorId): void
    {
        $this->repository->approveFact($articleId, $actorId);
    }

    public function approveSeo(int $articleId, int $actorId): void
    {
        $this->repository->approveSeo($articleId, $actorId);
    }

    /** @return array<string,mixed> */
    public function publish(int $articleId, int $actorId): array
    {
        $context = $this->repository->publishContext($articleId);

        $result = $this->publishing->publish(
            $articleId,
            (int) $context['layout_snapshot_id'],
            (string) $context['idempotency_key'],
        );

        $this->repository->markPublished($articleId, $result, $actorId);

        return $result;
    }
}

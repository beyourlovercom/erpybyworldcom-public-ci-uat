<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflow;
use App\Modules\Content\Application\ContentWorkflowStatus;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ContentWorkflowTest extends TestCase
{
    public function test_authoring_mode_gate_routes_to_the_correct_branch(): void
    {
        self::assertSame(ContentWorkflowStatus::AiWriting, ContentWorkflow::nextAfterAuthoringMode(ContentAuthoringMode::Ai));
        self::assertSame(ContentWorkflowStatus::WaitingForWriter, ContentWorkflow::nextAfterAuthoringMode(ContentAuthoringMode::Human));
    }

    public function test_normal_human_path_transitions_are_allowed(): void
    {
        $path = [
            ContentWorkflowStatus::ArticleBaseReady,
            ContentWorkflowStatus::WaitingForWriter,
            ContentWorkflowStatus::WriterClaimed,
            ContentWorkflowStatus::Writing,
            ContentWorkflowStatus::DocSubmitted,
            ContentWorkflowStatus::DocImported,
            ContentWorkflowStatus::EditorialReview,
            ContentWorkflowStatus::EditorialReady,
            ContentWorkflowStatus::LayoutReady,
            ContentWorkflowStatus::OwnerUat,
            ContentWorkflowStatus::Published,
            ContentWorkflowStatus::Monitoring,
        ];

        for ($i = 0; $i < count($path) - 1; $i++) {
            self::assertTrue(ContentWorkflow::canTransition($path[$i], $path[$i + 1]));
        }
    }

    public function test_cross_branch_shortcut_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        ContentWorkflow::assertTransition(
            ContentWorkflowStatus::WaitingForWriter,
            ContentWorkflowStatus::EditorialReady,
        );
    }
}

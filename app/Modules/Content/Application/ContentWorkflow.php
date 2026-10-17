<?php

namespace App\Modules\Content\Application;

use DomainException;

final class ContentWorkflow
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'article_base_ready' => ['ai_writing', 'waiting_for_writer', 'cancelled'],
        'ai_writing' => ['draft_ready', 'blocked', 'cancelled'],
        'draft_ready' => ['editorial_review', 'blocked', 'cancelled'],
        'waiting_for_writer' => ['writer_claimed', 'cancelled'],
        'writer_claimed' => ['writing', 'waiting_for_writer', 'cancelled'],
        'writing' => ['doc_submitted', 'blocked', 'cancelled'],
        'doc_submitted' => ['doc_imported', 'blocked', 'returned_for_revision', 'cancelled'],
        'doc_imported' => ['editorial_review', 'blocked', 'cancelled'],
        'editorial_review' => ['editorial_ready', 'returned_for_revision', 'blocked', 'cancelled'],
        'editorial_ready' => ['layout_ready', 'returned_for_revision', 'blocked', 'cancelled'],
        'layout_ready' => ['owner_uat', 'returned_for_revision', 'blocked', 'cancelled'],
        'owner_uat' => ['published', 'returned_for_revision', 'blocked', 'cancelled'],
        'published' => ['monitoring', 'blocked'],
        'returned_for_revision' => ['writing', 'ai_writing', 'cancelled'],
        'monitoring' => [],
        'blocked' => [],
        'cancelled' => [],
    ];

    public static function initial(): ContentWorkflowStatus
    {
        return ContentWorkflowStatus::ArticleBaseReady;
    }

    public static function nextAfterAuthoringMode(ContentAuthoringMode $mode): ContentWorkflowStatus
    {
        return $mode === ContentAuthoringMode::Ai
            ? ContentWorkflowStatus::AiWriting
            : ContentWorkflowStatus::WaitingForWriter;
    }

    public static function canTransition(ContentWorkflowStatus $from, ContentWorkflowStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public static function assertTransition(ContentWorkflowStatus $from, ContentWorkflowStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new DomainException("Invalid Content workflow transition [{$from->value} -> {$to->value}].");
        }
    }
}

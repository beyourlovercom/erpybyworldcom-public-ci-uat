<?php

namespace App\Modules\Content\Application;

enum ContentWorkflowStatus: string
{
    case ArticleBaseReady = 'article_base_ready';
    case AiWriting = 'ai_writing';
    case DraftReady = 'draft_ready';
    case WaitingForWriter = 'waiting_for_writer';
    case WriterClaimed = 'writer_claimed';
    case Writing = 'writing';
    case DocSubmitted = 'doc_submitted';
    case DocImported = 'doc_imported';
    case EditorialReview = 'editorial_review';
    case EditorialReady = 'editorial_ready';
    case LayoutReady = 'layout_ready';
    case OwnerUat = 'owner_uat';
    case Published = 'published';
    case Monitoring = 'monitoring';
    case Blocked = 'blocked';
    case ReturnedForRevision = 'returned_for_revision';
    case Cancelled = 'cancelled';
}

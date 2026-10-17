<?php

namespace App\Modules\Content\Infrastructure\Models;

use App\Modules\Content\Application\ContentAuthoringMode;
use App\Modules\Content\Application\ContentWorkflowStatus;
use Illuminate\Database\Eloquent\Model;

final class ContentArticle extends Model
{
    protected $table = 'content_articles';

    protected $guarded = [];

    protected $casts = [
        'supporting_keywords' => 'array',
        'evidence_summary' => 'array',
        'internal_link_targets' => 'array',
        'reference_sources' => 'array',
        'prohibited_claims' => 'array',
        'recommended_structure' => 'array',
        'guideline_json' => 'array',
        'authoring_mode' => ContentAuthoringMode::class,
        'workflow_status' => ContentWorkflowStatus::class,
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
    ];
}

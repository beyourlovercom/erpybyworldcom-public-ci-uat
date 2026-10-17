<?php

namespace App\Modules\Inquiries\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CaseAttribution extends Model
{
    protected $table = 'case_attributions';

    protected $fillable = [
        'case_id', 'business_project_id', 'traffic_source_account_id', 'traffic_campaign_id', 'landing_page_id',
        'source_kind', 'attribution_state', 'campaign_match_state', 'landing_page_match_state', 'match_method',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'gclid', 'fbclid', 'source_url',
        'evidence_hash', 'evidence', 'resolved_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(InquiryCase::class, 'case_id');
    }
}
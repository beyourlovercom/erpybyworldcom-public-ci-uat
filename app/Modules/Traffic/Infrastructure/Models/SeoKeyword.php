<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SeoKeyword extends Model
{
    protected $table = 'seo_keywords';

    protected $fillable = [
        'business_project_id', 'keyword', 'normalized_keyword', 'normalized_hash',
        'topic_cluster', 'intent', 'suggested_target_type', 'suggested_action',
        'opportunity_score', 'is_competitor_term', 'status',
    ];

    protected $casts = [
        'opportunity_score' => 'decimal:2',
        'is_competitor_term' => 'boolean',
    ];

    public function evidence(): HasMany
    {
        return $this->hasMany(SeoKeywordEvidence::class, 'seo_keyword_id');
    }
}

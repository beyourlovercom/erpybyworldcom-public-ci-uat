<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SeoKeywordEvidence extends Model
{
    protected $table = 'seo_keyword_evidence';

    protected $fillable = [
        'seo_keyword_id', 'source_type', 'source_key', 'impressions', 'clicks',
        'conversions', 'cost', 'currency_code', 'average_position', 'search_volume',
        'competition', 'landing_page_url', 'dimensions', 'raw_payload',
    ];

    protected $casts = [
        'conversions' => 'decimal:4', 'cost' => 'decimal:4',
        'average_position' => 'decimal:4', 'competition' => 'decimal:4',
        'dimensions' => 'array', 'raw_payload' => 'array',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKeyword::class, 'seo_keyword_id');
    }
}

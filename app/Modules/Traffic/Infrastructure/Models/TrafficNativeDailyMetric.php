<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TrafficNativeDailyMetric extends Model
{
    protected $table = 'traffic_native_daily_metrics';

    protected $fillable = [
        'traffic_source_account_id', 'metric_date', 'scope', 'external_object_id', 'object_name',
        'impressions', 'reach', 'engagements', 'link_clicks', 'video_views', 'watch_time_seconds',
        'followers', 'followers_delta', 'spend_micros', 'provider_metrics',
    ];

    protected $casts = [
        'impressions' => 'integer',
        'reach' => 'integer',
        'engagements' => 'integer',
        'link_clicks' => 'integer',
        'video_views' => 'integer',
        'watch_time_seconds' => 'integer',
        'followers' => 'integer',
        'followers_delta' => 'integer',
        'spend_micros' => 'integer',
        'provider_metrics' => 'array',
    ];

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(TrafficSourceAccount::class, 'traffic_source_account_id');
    }
}

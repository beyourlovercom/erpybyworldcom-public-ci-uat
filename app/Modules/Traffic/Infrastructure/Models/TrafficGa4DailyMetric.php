<?php

namespace App\Modules\Traffic\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class TrafficGa4DailyMetric extends Model
{
    protected $table = 'traffic_ga4_daily_metrics';

    protected $fillable = [
        'business_project_id', 'metric_date', 'scope', 'dimension_value',
        'sessions', 'total_users', 'new_users', 'engaged_sessions', 'key_events', 'event_count',
    ];

    protected $casts = [
        'sessions' => 'integer',
        'total_users' => 'integer',
        'new_users' => 'integer',
        'engaged_sessions' => 'integer',
        'key_events' => 'decimal:4',
        'event_count' => 'integer',
    ];
}

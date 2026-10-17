<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesOpportunityStageEvent extends Model
{
    protected $fillable = [
        'opportunity_id', 'actor_id', 'from_stage', 'to_stage', 'entered_at', 'source', 'reason',
    ];

    protected $casts = [
        'entered_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'opportunity_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

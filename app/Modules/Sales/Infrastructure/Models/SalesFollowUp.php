<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesFollowUp extends Model
{
    protected $fillable = ['opportunity_id', 'stage_key', 'actor_id', 'channel', 'outcome', 'summary', 'next_plan', 'happened_at', 'next_follow_up_at'];

    protected $casts = ['happened_at' => 'datetime', 'next_follow_up_at' => 'datetime'];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'opportunity_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SalesFollowUpAttachment::class, 'follow_up_id')->orderBy('id');
    }
}

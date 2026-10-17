<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesFollowUpAttachment extends Model
{
    protected $fillable = [
        'follow_up_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(SalesFollowUp::class, 'follow_up_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

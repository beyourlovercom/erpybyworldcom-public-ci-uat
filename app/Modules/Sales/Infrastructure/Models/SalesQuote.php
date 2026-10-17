<?php

namespace App\Modules\Sales\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesQuote extends Model
{
    protected $fillable = [
        'quote_number', 'opportunity_id', 'version', 'status', 'currency', 'subtotal_micros',
        'discount_micros', 'tax_micros', 'total_micros', 'valid_until', 'line_items', 'notes',
        'sent_at', 'accepted_at',
    ];

    protected $casts = [
        'version' => 'integer', 'subtotal_micros' => 'integer', 'discount_micros' => 'integer',
        'tax_micros' => 'integer', 'total_micros' => 'integer', 'valid_until' => 'date',
        'line_items' => 'array', 'sent_at' => 'datetime', 'accepted_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo { return $this->belongsTo(SalesOpportunity::class, 'opportunity_id'); }
}

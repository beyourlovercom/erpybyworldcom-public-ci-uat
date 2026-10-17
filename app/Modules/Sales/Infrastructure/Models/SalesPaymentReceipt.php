<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesPaymentReceipt extends Model
{
    protected $fillable = [
        'receipt_number', 'business_project_id', 'opportunity_id', 'company_id', 'recorded_by',
        'amount_micros', 'currency', 'received_at', 'payment_method', 'entry_source',
        'provider_reference', 'evidence_reference', 'note', 'status',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'amount_micros' => 'integer',
        'received_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(BusinessProject::class, 'business_project_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'opportunity_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}

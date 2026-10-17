<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Inquiries\Infrastructure\Models\InquiryCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalesOpportunity extends Model
{
    protected $fillable = [
        'opportunity_number', 'business_project_id', 'case_id', 'company_id', 'contact_id', 'owner_id',
        'title', 'status', 'pipeline_stage', 'currency', 'expected_value_micros',
        'receivable_amount_micros', 'receivable_currency', 'receivable_source', 'receivable_quote_id',
        'receivable_confirmed_at', 'receivable_confirmed_by', 'receivable_note', 'probability_bps',
        'expected_close_date', 'next_follow_up_at', 'last_follow_up_at', 'won_at', 'lost_at',
        'lost_reason_code', 'lost_reason_note', 'attribution',
    ];

    protected $casts = [
        'expected_value_micros' => 'integer', 'receivable_amount_micros' => 'integer', 'probability_bps' => 'integer',
        'expected_close_date' => 'date', 'next_follow_up_at' => 'datetime', 'last_follow_up_at' => 'datetime',
        'receivable_confirmed_at' => 'datetime', 'won_at' => 'datetime', 'lost_at' => 'datetime', 'attribution' => 'array',
    ];

    public function project(): BelongsTo { return $this->belongsTo(BusinessProject::class, 'business_project_id'); }
    public function case(): BelongsTo { return $this->belongsTo(InquiryCase::class, 'case_id'); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Customer::class, 'contact_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function followUps(): HasMany { return $this->hasMany(SalesFollowUp::class, 'opportunity_id')->orderByDesc('happened_at'); }
    public function stageEvents(): HasMany { return $this->hasMany(SalesOpportunityStageEvent::class, 'opportunity_id')->orderBy('entered_at')->orderBy('id'); }
    public function quotes(): HasMany { return $this->hasMany(SalesQuote::class, 'opportunity_id')->orderByDesc('version'); }
    public function receivableQuote(): BelongsTo { return $this->belongsTo(SalesQuote::class, 'receivable_quote_id'); }
    public function receivableConfirmer(): BelongsTo { return $this->belongsTo(User::class, 'receivable_confirmed_by'); }
    public function paymentReceipts(): HasMany { return $this->hasMany(SalesPaymentReceipt::class, 'opportunity_id')->orderByDesc('received_at'); }
}

<?php

namespace App\Modules\Inquiries\Infrastructure\Models;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InquiryCase extends Model
{
    protected $table = 'cases';

    protected $fillable = [
        'case_number', 'business_project_id', 'company_id', 'owner_id', 'title',
        'contact_name', 'contact_email', 'contact_phone', 'company_name_snapshot', 'country_snapshot',
        'buyer_type', 'product_interest', 'quantity',
        'source_channel', 'source_connection_key', 'source_external_id', 'source_payload_hash',
        'brand_key', 'source_site_key', 'case_status', 'case_stage', 'handling_state',
        'identity_resolution_state', 'first_inbound_at', 'last_inbound_at', 'first_action_at',
        'last_action_at', 'attention_since_at', 'closed_at', 'loss_reason_code',
        'attribution', 'source_payload',
    ];

    protected $casts = [
        'first_inbound_at' => 'datetime', 'last_inbound_at' => 'datetime',
        'first_action_at' => 'datetime', 'last_action_at' => 'datetime',
        'attention_since_at' => 'datetime', 'closed_at' => 'datetime',
        'attribution' => 'array', 'source_payload' => 'array',
    ];

    public function project(): BelongsTo { return $this->belongsTo(BusinessProject::class, 'business_project_id'); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
}
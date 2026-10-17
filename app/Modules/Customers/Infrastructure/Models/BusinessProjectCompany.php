<?php

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BusinessProjectCompany extends Model
{
    protected $fillable = [
        'business_project_id', 'company_id', 'owner_id', 'business_location_id',
        'relationship_status', 'market_route', 'payment_terms', 'credit_limit_micros',
        'credit_currency', 'internal_notes', 'first_source_channel', 'last_source_channel',
        'inquiry_count', 'lifetime_revenue_micros', 'first_inquiry_at', 'last_inquiry_at',
        'last_follow_up_at', 'last_won_at',
    ];

    protected $casts = [
        'credit_limit_micros' => 'integer', 'lifetime_revenue_micros' => 'integer',
        'first_inquiry_at' => 'datetime', 'last_inquiry_at' => 'datetime',
        'last_follow_up_at' => 'datetime', 'last_won_at' => 'datetime',
    ];

    public function project(): BelongsTo { return $this->belongsTo(BusinessProject::class, 'business_project_id'); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function location(): BelongsTo { return $this->belongsTo(BusinessLocation::class, 'business_location_id'); }
}
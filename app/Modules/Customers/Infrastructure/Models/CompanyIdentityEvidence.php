<?php

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyIdentityEvidence extends Model
{
    protected $table = 'company_identity_evidence';

    protected $fillable = [
        'company_id', 'business_project_id', 'evidence_type', 'source_type', 'evidence_key',
        'evidence_value', 'review_state', 'confidence', 'source_url', 'observed_at',
        'verified_at', 'verified_by',
    ];

    protected $casts = [
        'evidence_value' => 'array', 'confidence' => 'decimal:4',
        'observed_at' => 'datetime', 'verified_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function project(): BelongsTo { return $this->belongsTo(BusinessProject::class, 'business_project_id'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
}

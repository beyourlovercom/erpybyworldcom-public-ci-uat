<?php

namespace App\Modules\Customers\Infrastructure\Models;

use App\Models\User;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name', 'legal_name', 'website_url', 'website_domain_normalized',
        'country_code', 'registration_number', 'industry', 'owner_id', 'status',
        'identity_status', 'scale_band', 'identity_reviewed_at', 'identity_reviewed_by',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'identity_reviewed_at' => 'datetime'];
    }

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function identityReviewer(): BelongsTo { return $this->belongsTo(User::class, 'identity_reviewed_by'); }
    public function projectRelationships(): HasMany { return $this->hasMany(BusinessProjectCompany::class); }
    public function identityEvidence(): HasMany { return $this->hasMany(CompanyIdentityEvidence::class); }
    public function projects(): BelongsToMany { return $this->belongsToMany(BusinessProject::class, 'business_project_companies')->withTimestamps(); }
    public function contacts(): BelongsToMany { return $this->belongsToMany(Customer::class, 'company_contacts')->withPivot(['job_title', 'department', 'is_primary', 'status', 'started_at', 'ended_at'])->withTimestamps(); }
}

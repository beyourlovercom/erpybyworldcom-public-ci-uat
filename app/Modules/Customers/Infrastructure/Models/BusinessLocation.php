<?php

namespace App\Modules\Customers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BusinessLocation extends Model
{
    protected $fillable = [
        'location_key', 'name', 'location_type', 'country_code', 'region', 'city', 'status',
    ];

    public function projectCompanies(): HasMany
    {
        return $this->hasMany(BusinessProjectCompany::class);
    }
}
<?php

namespace App\Modules\Products\Infrastructure\Models;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BusinessProjectProduct extends Model
{
    protected $fillable = [
        'business_project_id', 'product_id', 'project_product_name', 'category',
        'status', 'sales_status', 'marketing_status', 'primary_site',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(BusinessProject::class, 'business_project_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Modules\Content\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class ContentSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'content_snapshots';

    protected $guarded = [];

    protected $casts = [
        'normalized_json' => 'array',
        'version' => 'integer',
        'created_at' => 'datetime',
    ];
}

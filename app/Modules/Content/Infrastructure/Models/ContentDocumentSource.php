<?php

namespace App\Modules\Content\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class ContentDocumentSource extends Model
{
    protected $table = 'content_document_sources';

    protected $guarded = [];

    protected $casts = [
        'modified_time' => 'datetime',
        'submitted_at' => 'datetime',
        'imported_at' => 'datetime',
        'changed_after_submission' => 'boolean',
    ];
}

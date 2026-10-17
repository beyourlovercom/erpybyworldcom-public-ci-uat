<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\Data\ExternalDocumentData;

interface DocumentReadClient
{
    public function fetch(string $documentUrl): ExternalDocumentData;
}

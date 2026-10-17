<?php

namespace App\Modules\Content\Application\Contracts;

use App\Modules\Content\Application\Data\EditorialRequestData;
use App\Modules\Content\Application\Data\EditorialResultData;

interface EditorialAiClient
{
    public function edit(EditorialRequestData $request): EditorialResultData;

    public function available(): bool;
}

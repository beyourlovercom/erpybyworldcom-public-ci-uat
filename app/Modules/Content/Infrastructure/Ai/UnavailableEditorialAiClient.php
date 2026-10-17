<?php

namespace App\Modules\Content\Infrastructure\Ai;

use App\Modules\Content\Application\Contracts\EditorialAiClient;
use App\Modules\Content\Application\Data\EditorialRequestData;
use App\Modules\Content\Application\Data\EditorialResultData;
use App\Modules\Content\Application\Exceptions\EditorialException;

final class UnavailableEditorialAiClient implements EditorialAiClient
{
    public function available(): bool
    {
        return false;
    }

    public function edit(EditorialRequestData $request): EditorialResultData
    {
        throw new EditorialException('provider_not_configured', 'AI editorial provider is not configured.');
    }
}

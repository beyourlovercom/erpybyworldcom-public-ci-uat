<?php

namespace App\Modules\Traffic\Infrastructure\Meta;

use App\Modules\Traffic\Application\Contracts\MetaReadClient;
use App\Modules\Traffic\Application\Exceptions\MetaReadException;

final class UnavailableMetaReadClient implements MetaReadClient
{
    public function fetchAccount(string $sourceType, string $externalAccountId): array
    {
        throw new MetaReadException('credentials_missing', 'Meta Graph API connector is not configured.');
    }

    public function fetchDailyMetrics(string $sourceType, string $externalAccountId, string $dateFrom, string $dateTo): iterable
    {
        throw new MetaReadException('credentials_missing', 'Meta Graph API connector is not configured.');
    }
}

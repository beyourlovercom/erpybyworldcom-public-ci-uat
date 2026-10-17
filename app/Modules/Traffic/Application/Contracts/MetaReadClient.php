<?php

namespace App\Modules\Traffic\Application\Contracts;

interface MetaReadClient
{
    /** @return array{external_account_id:string,name:?string,currency_code:?string,account_timezone:?string} */
    public function fetchAccount(string $sourceType, string $externalAccountId): array;

    /** @return iterable<int, array<string, mixed>> */
    public function fetchDailyMetrics(string $sourceType, string $externalAccountId, string $dateFrom, string $dateTo): iterable;
}

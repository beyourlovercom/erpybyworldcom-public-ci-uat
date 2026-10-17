<?php

namespace App\Modules\Traffic\Application\Contracts;

interface YouTubeReadClient
{
    /** @return array{external_account_id:string,name:?string,currency_code:?string,account_timezone:?string} */
    public function fetchChannel(string $channelId): array;

    /** @return iterable<int, array<string, mixed>> */
    public function fetchDailyMetrics(string $channelId, string $dateFrom, string $dateTo): iterable;
}

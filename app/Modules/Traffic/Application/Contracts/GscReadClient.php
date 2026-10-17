<?php

namespace App\Modules\Traffic\Application\Contracts;

interface GscReadClient
{
    /** @return iterable<int, array<string, mixed>> */
    public function fetchQueryPageDailyMetrics(
        string $siteProperty,
        string $dateFrom,
        string $dateTo,
    ): iterable;
}

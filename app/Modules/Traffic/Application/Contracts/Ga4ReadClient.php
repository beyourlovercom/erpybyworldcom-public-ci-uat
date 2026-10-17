<?php

namespace App\Modules\Traffic\Application\Contracts;

interface Ga4ReadClient
{
    /** @return iterable<int, array<string, mixed>> */
    public function fetchDailyMetrics(string $propertyId, string $dateFrom, string $dateTo, string $scope): iterable;
}

<?php

namespace App\Modules\Traffic\Infrastructure\YouTube;

use App\Modules\Traffic\Application\Contracts\YouTubeReadClient;
use App\Modules\Traffic\Application\Exceptions\YouTubeReadException;

final class UnavailableYouTubeReadClient implements YouTubeReadClient
{
    public function fetchChannel(string $channelId): array
    {
        throw new YouTubeReadException('credentials_missing', 'YouTube Analytics connector is not configured.');
    }

    public function fetchDailyMetrics(string $channelId, string $dateFrom, string $dateTo): iterable
    {
        throw new YouTubeReadException('credentials_missing', 'YouTube Analytics connector is not configured.');
    }
}

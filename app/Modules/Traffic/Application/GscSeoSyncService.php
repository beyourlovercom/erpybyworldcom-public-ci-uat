<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use App\Modules\Traffic\Application\Contracts\GscReadClient;
use App\Modules\Traffic\Application\Exceptions\GscReadException;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class GscSeoSyncService
{
    public function __construct(private readonly GscReadClient $client) {}

    /** @return array<string, mixed> */
    public function sync(
        string $projectKey,
        string $siteKey,
        string $siteProperty,
        string $dateFrom,
        string $dateTo,
        string $mode = 'dry_run',
    ): array {
        $summary = $this->summary($mode, $projectKey, $siteKey, $siteProperty, $dateFrom, $dateTo);
        if (! in_array($mode, ['dry_run', 'apply'], true)) {
            return $this->blocked($summary, 'invalid_mode');
        }
        if (! $this->validDate($dateFrom) || ! $this->validDate($dateTo) || $dateFrom > $dateTo) {
            return $this->blocked($summary, 'invalid_date_range');
        }
        if (trim($siteProperty) === '') {
            return $this->blocked($summary, 'invalid_site_property');
        }

        $project = BusinessProject::query()->where('project_key', $projectKey)->where('status', 'active')->first();
        if (! $project) {
            return $this->blocked($summary, 'project_not_found');
        }

        $site = BusinessProjectSite::query()
            ->where('business_project_id', $project->id)
            ->where('site_key', $siteKey)
            ->where('status', 'active')
            ->first();
        if (! $site) {
            return $this->blocked($summary, 'site_not_found');
        }

        try {
            $rows = [];
            $queries = [];
            foreach ($this->client->fetchQueryPageDailyMetrics($siteProperty, $dateFrom, $dateTo) as $row) {
                if (! is_array($row) || ! $this->validRow($row, $dateFrom, $dateTo)) {
                    return $this->blocked($summary, 'invalid_metric_row');
                }
                $normalized = $this->normalizeQuery((string) $row['query']);
                if (mb_strlen((string) $row['query']) > 500 || mb_strlen($normalized) > 500 || mb_strlen((string) $row['page']) > 1000) {
                    $summary['query_rows_skipped_oversize']++;
                    continue;
                }
                $row['normalized_query'] = $normalized;
                $key = hash('sha256', implode('|', [
                    $normalized, (string) $row['page'], (string) $row['metric_date'],
                    (string) $row['country'], (string) $row['device'],
                ]));
                $rows[$key] = $row;
                $queries[$normalized] = (string) $row['query'];
            }
        } catch (GscReadException $e) {
            return $this->blocked($summary, $e->status);
        }

        ksort($rows);
        ksort($queries);
        $summary['query_rows_seen'] = count($rows);
        $summary['keywords_seen'] = count($queries);

        $hashes = array_map(static fn (string $query): string => hash('sha256', $query), array_keys($queries));
        $existingHashes = $hashes === [] ? [] : SeoKeyword::query()
            ->where('business_project_id', $project->id)
            ->whereIn('normalized_hash', $hashes)
            ->pluck('normalized_hash')->all();
        $summary['keywords_missing'] = count(array_diff($hashes, $existingHashes));

        if ($mode === 'dry_run') {
            return $summary;
        }

        return DB::transaction(function () use ($summary, $project, $site, $rows, $queries): array {
            $result = $summary;
            $keywordByNormalized = [];
            foreach ($queries as $normalized => $display) {
                $hash = hash('sha256', $normalized);
                $keyword = SeoKeyword::query()->where('business_project_id', $project->id)
                    ->where('normalized_hash', $hash)->first();
                if (! $keyword) {
                    $keyword = SeoKeyword::query()->create([
                        'business_project_id' => $project->id,
                        'keyword' => trim($display),
                        'normalized_keyword' => $normalized,
                        'normalized_hash' => $hash,
                        'status' => 'active',
                    ]);
                    $result['keywords_created']++;
                }
                $keywordByNormalized[$normalized] = $keyword;
            }

            foreach ($rows as $row) {
                $keyword = $keywordByNormalized[$row['normalized_query']];
                $identity = [
                    'seo_keyword_id' => $keyword->id,
                    'business_project_site_id' => $site->id,
                    'metric_date' => $row['metric_date'],
                    'page_url' => $row['page'],
                    'country' => $row['country'] ?: null,
                    'device' => $row['device'] ?: null,
                ];
                $values = [
                    'impressions' => $row['impressions'],
                    'clicks' => $row['clicks'],
                    'ctr' => $row['ctr'],
                    'average_position' => $row['average_position'],
                    'updated_at' => now(),
                ];
                if (! DB::table('seo_gsc_daily_metrics')->where($identity)->exists()) {
                    $values['created_at'] = now();
                }
                DB::table('seo_gsc_daily_metrics')->updateOrInsert($identity, $values);
                $result['daily_rows_applied']++;
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    private function summary(string $mode, string $projectKey, string $siteKey, string $siteProperty, string $from, string $to): array
    {
        return [
            'mode' => $mode, 'project_key' => $projectKey, 'site_key' => $siteKey,
            'site_property' => $siteProperty, 'date_from' => $from, 'date_to' => $to,
            'query_rows_seen' => 0, 'query_rows_skipped_oversize' => 0, 'keywords_seen' => 0, 'keywords_missing' => 0,
            'keywords_created' => 0, 'daily_rows_applied' => 0, 'blocked' => [],
        ];
    }

    /** @param array<string, mixed> $summary */
    private function blocked(array $summary, string $reason): array
    {
        $summary['blocked'] = [$reason];

        return $summary;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $row */
    private function validRow(array $row, string $from, string $to): bool
    {
        $query = trim((string) ($row['query'] ?? ''));
        $page = trim((string) ($row['page'] ?? ''));
        $date = (string) ($row['metric_date'] ?? '');
        if ($query === '' || $page === '' || ! str_starts_with($page, 'http') || ! $this->validDate($date) || $date < $from || $date > $to) {
            return false;
        }
        foreach (['clicks', 'impressions'] as $field) {
            if (! is_int($row[$field] ?? null) || $row[$field] < 0) {
                return false;
            }
        }
        $ctr = $row['ctr'] ?? null;
        $position = $row['average_position'] ?? null;

        return is_numeric($ctr) && (float) $ctr >= 0 && (float) $ctr <= 1
            && is_numeric($position) && (float) $position >= 0;
    }

    private function normalizeQuery(string $query): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($query))) ?? mb_strtolower(trim($query));
    }
}

<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\Traffic\Infrastructure\Models\SeoKeyword;
use App\Modules\Traffic\Infrastructure\Models\SeoKeywordEvidence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoKeywordSeedImportService
{
    /** @return array<string, mixed> */
    public function import(string $projectKey, string $file, string $mode = 'dry_run'): array
    {
        $project = BusinessProject::query()->where('project_key', $projectKey)->first();
        if (! $project) {
            return $this->blocked($mode, $projectKey, 'project_not_found');
        }
        if (! is_file($file) || ! is_readable($file)) {
            return $this->blocked($mode, $projectKey, 'file_not_readable');
        }
        if (! in_array($mode, ['dry_run', 'apply'], true)) {
            return $this->blocked($mode, $projectKey, 'invalid_mode');
        }

        $rows = $this->readRows($file);
        $summary = [
            'mode' => $mode, 'project_key' => $projectKey,
            'keywords_seen' => count($rows), 'keywords_applied' => 0,
            'evidence_rows_seen' => 0, 'evidence_rows_applied' => 0,
            'actions' => [], 'blocked' => [],
        ];

        foreach ($rows as $row) {
            $summary['evidence_rows_seen'] += count($this->evidencePayloads($row));
            $action = trim((string) ($row['suggested_action'] ?? '')) ?: 'UNCLASSIFIED';
            $summary['actions'][$action] = ($summary['actions'][$action] ?? 0) + 1;
        }

        if ($mode === 'dry_run') {
            ksort($summary['actions']);
            return $summary;
        }

        DB::transaction(function () use ($project, $rows, &$summary): void {
            foreach ($rows as $row) {
                $normalized = $this->normalize((string) $row['keyword']);
                $keyword = SeoKeyword::query()->updateOrCreate(
                    [
                        'business_project_id' => $project->id,
                        'normalized_hash' => hash('sha256', $normalized),
                    ],
                    [
                        'keyword' => trim((string) $row['keyword']),
                        'normalized_keyword' => $normalized,
                        'topic_cluster' => $this->nullable($row['topic_cluster'] ?? null),
                        'suggested_target_type' => $this->nullable($row['suggested_target'] ?? null),
                        'suggested_action' => $this->nullable($row['suggested_action'] ?? null),
                        'opportunity_score' => $this->nullableNumber($row['opportunity_score'] ?? null),
                        'is_competitor_term' => strtolower(trim((string) ($row['competitor_term'] ?? ''))) === 'yes',
                        'status' => 'active',
                    ]
                );
                $summary['keywords_applied']++;

                foreach ($this->evidencePayloads($row) as $sourceType => $payload) {
                    SeoKeywordEvidence::query()->updateOrCreate(
                        [
                            'seo_keyword_id' => $keyword->id,
                            'source_type' => $sourceType,
                            'source_key' => 'keyword-library-seed-v1',
                        ],
                        $payload
                    );
                    $summary['evidence_rows_applied']++;
                }
            }
        });

        ksort($summary['actions']);
        return $summary;
    }

    /** @return list<array<string, string>> */
    private function readRows(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open keyword seed file.');
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);
            throw new RuntimeException('Keyword seed file has no header.');
        }
        $header = array_map(static fn ($value): string => trim((string) $value), $header);
        $header[0] = ltrim($header[0] ?? '', "\xEF\xBB\xBF");
        if (! in_array('keyword', $header, true)) {
            fclose($handle);
            throw new RuntimeException('Keyword seed file is not normalized seed format.');
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            $values = array_pad($values, count($header), '');
            $row = array_combine($header, array_slice($values, 0, count($header)));
            if (! is_array($row) || trim((string) ($row['keyword'] ?? '')) === '') {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<string, array<string, mixed>> */
    private function evidencePayloads(array $row): array
    {
        $payloads = [];
        if ($this->hasEvidence($row, 'ads')) {
            $payloads['google_ads'] = [
                'impressions' => (int) $this->number($row['ads_impressions'] ?? 0),
                'clicks' => (int) $this->number($row['ads_clicks'] ?? 0),
                'conversions' => $this->number($row['ads_conversions'] ?? 0),
                'cost' => $this->number($row['ads_cost'] ?? 0),
                'currency_code' => 'CNY',
            ];
        }

        if ($this->hasEvidence($row, 'gsc')) {
            $payloads['google_search_console'] = [
                'impressions' => (int) $this->number($row['gsc_impressions'] ?? 0),
                'clicks' => (int) $this->number($row['gsc_clicks'] ?? 0),
                'conversions' => 0,
                'cost' => 0,
                'average_position' => $this->nullableNumber($row['gsc_avg_position'] ?? null),
            ];
        }
        if ($this->hasEvidence($row, 'alibaba')) {
            $payloads['alibaba'] = [
                'impressions' => (int) $this->number($row['alibaba_impressions'] ?? 0),
                'clicks' => (int) $this->number($row['alibaba_clicks'] ?? 0),
                'conversions' => 0,
                'cost' => 0,
            ];
        }

        return $payloads;
    }

    private function hasEvidence(array $row, string $source): bool
    {
        $sources = array_filter(array_map('trim', explode(',', strtolower((string) ($row['evidence_sources'] ?? '')))));
        return in_array($source, $sources, true);
    }

    private function normalize(string $keyword): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($keyword))) ?? mb_strtolower(trim($keyword));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function number(mixed $value): float
    {
        $value = trim((string) $value);
        return $value === '' ? 0.0 : (float) $value;
    }

    private function nullableNumber(mixed $value): ?float
    {
        $value = trim((string) $value);
        return $value === '' ? null : (float) $value;
    }

    /** @return array<string, mixed> */
    private function blocked(string $mode, string $projectKey, string $reason): array
    {
        return [
            'mode' => $mode, 'project_key' => $projectKey,
            'keywords_seen' => 0, 'keywords_applied' => 0,
            'evidence_rows_seen' => 0, 'evidence_rows_applied' => 0,
            'actions' => [], 'blocked' => [$reason],
        ];
    }
}

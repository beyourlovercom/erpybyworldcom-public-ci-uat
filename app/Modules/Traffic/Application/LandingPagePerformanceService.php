<?php

namespace App\Modules\Traffic\Application;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\Traffic\Infrastructure\Models\TrafficGa4DailyMetric;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LandingPagePerformanceService
{
    public function __construct(private readonly BusinessProjectRepository $projects) {}

    /** @return array<string, mixed> */
    public function build(string $projectKey, string $dateFrom, string $dateTo): array
    {
        $project = $this->projects->findByKey($projectKey);
        if ($project === null) {
            throw new InvalidArgumentException("Unknown business project [{$projectKey}].");
        }

        $projectId = (int) $project->id;
        $sites = DB::table('business_project_sites')
            ->where('business_project_id', $projectId)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['id', 'site_key', 'domain_normalized', 'base_url', 'is_primary']);

        $target = $sites->firstWhere('is_primary', 1) ?? $sites->first();
        $legacy = $sites->first(fn ($site) => ! (bool) $site->is_primary);

        $targetDomain = strtolower((string) ($target?->domain_normalized ?? ''));
        $legacyDomain = strtolower((string) ($legacy?->domain_normalized ?? ''));

        $ga4Rows = TrafficGa4DailyMetric::query()
            ->where('business_project_id', $projectId)
            ->where('scope', 'landing_page')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->get();

        $targetGa4Pages = $ga4Rows
            ->groupBy(fn ($row): string => $this->normalizePath((string) $row->dimension_value))
            ->map(function ($rows, string $path): array {
                $sessions = (int) $rows->sum('sessions');
                $engaged = (int) $rows->sum('engaged_sessions');

                return [
                    'path' => $path,
                    'sessions' => $sessions,
                    'engaged_sessions' => $engaged,
                    'engagement_rate' => $sessions > 0 ? round(($engaged / $sessions) * 100, 2) : 0.0,
                    'key_events' => round($rows->sum(fn ($row): float => (float) $row->key_events), 4),
                ];
            })
            ->sortByDesc('sessions')
            ->values();

        $gscRaw = DB::table('seo_gsc_daily_metrics')
            ->join('seo_keywords', 'seo_keywords.id', '=', 'seo_gsc_daily_metrics.seo_keyword_id')
            ->where('seo_keywords.business_project_id', $projectId)
            ->whereBetween('seo_gsc_daily_metrics.metric_date', [$dateFrom, $dateTo])
            ->selectRaw(
                'seo_gsc_daily_metrics.page_url, '
                .'COALESCE(SUM(seo_gsc_daily_metrics.impressions),0) as impressions, '
                .'COALESCE(SUM(seo_gsc_daily_metrics.clicks),0) as clicks, '
                .'CASE WHEN SUM(seo_gsc_daily_metrics.impressions) > 0 '
                .'THEN SUM(seo_gsc_daily_metrics.clicks) / SUM(seo_gsc_daily_metrics.impressions) ELSE 0 END as ctr, '
                .'CASE WHEN SUM(seo_gsc_daily_metrics.impressions) > 0 '
                .'THEN SUM(seo_gsc_daily_metrics.average_position * seo_gsc_daily_metrics.impressions) / SUM(seo_gsc_daily_metrics.impressions) ELSE NULL END as average_position'
            )
            ->groupBy('seo_gsc_daily_metrics.page_url')
            ->get();

        $mappedGsc = $gscRaw->map(function ($row) use ($targetDomain, $legacyDomain): ?array {
            $url = trim((string) $row->page_url);
            $host = $this->normalizeHost((string) parse_url($url, PHP_URL_HOST));
            $role = null;

            if ($targetDomain !== '' && $host === $targetDomain) {
                $role = 'target';
            } elseif ($legacyDomain !== '' && $this->hostBelongsTo($host, $legacyDomain)) {
                $role = 'legacy';
            }

            if ($role === null) {
                return null;
            }

            return [
                'url' => $url,
                'domain' => $host,
                'path' => $this->normalizePath((string) parse_url($url, PHP_URL_PATH)),
                'site_role' => $role,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'ctr' => round(((float) $row->ctr) * 100, 2),
                'average_position' => $row->average_position === null ? null : round((float) $row->average_position, 2),
            ];
        })->filter()->values();

        $targetGscPages = $mappedGsc->where('site_role', 'target')->sortByDesc('clicks')->values();
        $legacyGscPages = $mappedGsc->where('site_role', 'legacy')->sortByDesc('clicks')->values();

        $ga4ByPath = $targetGa4Pages->keyBy('path');

        $migrationOpportunities = $legacyGscPages
            ->map(function (array $row) use ($ga4ByPath): array {
                $targetPage = $ga4ByPath->get($row['path']);
                $hasTargetPath = is_array($targetPage);

                $priority = $hasTargetPath
                    ? 'P2'
                    : ((int) $row['clicks'] > 0 ? 'P0' : 'P1');

                return [
                    ...$row,
                    'target_path_present' => $hasTargetPath,
                    'target_sessions' => $hasTargetPath ? (int) $targetPage['sessions'] : null,
                    'target_engaged_sessions' => $hasTargetPath ? (int) $targetPage['engaged_sessions'] : null,
                    'target_engagement_rate' => $hasTargetPath ? (float) $targetPage['engagement_rate'] : null,
                    'migration_state' => $hasTargetPath ? 'target_path_present' : 'migration_candidate',
                    'priority' => $priority,
                    'priority_reason' => match ($priority) {
                        'P0' => 'Legacy page has GSC clicks and no target path.',
                        'P1' => 'Legacy page has GSC impressions but no target path.',
                        default => 'Target path exists; optimize and verify migration quality.',
                    },
                ];
            })
            ->sort(function (array $a, array $b): int {
                $rank = ['P0' => 0, 'P1' => 1, 'P2' => 2];
                $priority = ($rank[$a['priority']] ?? 9) <=> ($rank[$b['priority']] ?? 9);
                if ($priority !== 0) {
                    return $priority;
                }

                $clicks = $b['clicks'] <=> $a['clicks'];

                return $clicks !== 0 ? $clicks : ($b['impressions'] <=> $a['impressions']);
            })
            ->values();

        $migrationContentPlan = $this->buildMigrationContentPlan(
            $migrationOpportunities->where('priority', 'P1')->values()
        );
        $migrationBacklogHandoff = $this->buildMigrationBacklogHandoff($projectId, $migrationContentPlan);
        $migrationMapping = $this->buildMigrationMapping(
            (string) ($target?->base_url ?? ''),
            $migrationOpportunities,
            $migrationBacklogHandoff
        );

        $inquiryRows = DB::table('case_attributions')
            ->where('business_project_id', $projectId)
            ->whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->get(['id', 'source_url', 'source_kind', 'attribution_state']);

        $excludedInquiryEvidence = 0;
        $targetInquiryRows = [];

        foreach ($inquiryRows as $row) {
            $sourceUrl = trim((string) ($row->source_url ?? ''));
            if ($sourceUrl === '') {
                continue;
            }

            $host = $this->normalizeHost((string) parse_url($sourceUrl, PHP_URL_HOST));

            if ($host !== $targetDomain) {
                $excludedInquiryEvidence++;
                continue;
            }

            $targetInquiryRows[] = [
                'domain' => $host,
                'path' => $this->normalizePath((string) parse_url($sourceUrl, PHP_URL_PATH)),
                'source_kind' => (string) $row->source_kind,
                'attribution_state' => (string) $row->attribution_state,
            ];
        }

        $targetInquiryPages = collect($targetInquiryRows)
            ->groupBy(fn (array $row): string => $row['domain'].'|'.$row['path'])
            ->map(function ($rows, string $key): array {
                [$domain, $path] = explode('|', $key, 2);

                return [
                    'domain' => $domain,
                    'path' => $path,
                    'inquiries' => $rows->count(),
                    'resolved' => $rows->where('attribution_state', 'resolved')->count(),
                    'partial' => $rows->where('attribution_state', 'partial')->count(),
                    'unresolved' => $rows->where('attribution_state', 'unresolved')->count(),
                ];
            })
            ->sortByDesc('inquiries')
            ->values();

        $appliedMigrationRows = DB::table('seo_content_backlog')
            ->where('business_project_id', $projectId)
            ->where('rationale', 'like', 'Legacy migration cluster;%')
            ->count();

        $preexistingReuseRows = collect($migrationBacklogHandoff)
            ->where('handoff_state', 'REUSE')
            ->where('match_origin', 'preexisting_backlog')
            ->count();

        return [
            'project' => [
                'key' => (string) $project->project_key,
                'name' => (string) $project->name,
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'site_strategy' => [
                'target_domain' => $targetDomain,
                'target_base_url' => (string) ($target?->base_url ?? ''),
                'legacy_domain' => $legacyDomain,
                'legacy_base_url' => (string) ($legacy?->base_url ?? ''),
                'strategy' => 'legacy_reference_to_target_migration',
            ],
            'summary' => [
                'target_ga4_pages' => $targetGa4Pages->count(),
                'target_gsc_pages' => $targetGscPages->count(),
                'target_gsc_impressions' => (int) $targetGscPages->sum('impressions'),
                'target_gsc_clicks' => (int) $targetGscPages->sum('clicks'),
                'legacy_reference_pages' => $legacyGscPages->count(),
                'legacy_gsc_impressions' => (int) $legacyGscPages->sum('impressions'),
                'legacy_gsc_clicks' => (int) $legacyGscPages->sum('clicks'),
                'migration_candidates' => $migrationOpportunities->where('migration_state', 'migration_candidate')->count(),
                'target_path_present' => $migrationOpportunities->where('migration_state', 'target_path_present')->count(),
                'priority_p0' => $migrationOpportunities->where('priority', 'P0')->count(),
                'priority_p1' => $migrationOpportunities->where('priority', 'P1')->count(),
                'priority_p2' => $migrationOpportunities->where('priority', 'P2')->count(),
                'p1_content_clusters' => count($migrationContentPlan),
                'p1_blog_clusters' => collect($migrationContentPlan)->where('recommended_target_type', 'Blog')->count(),
                'p1_product_clusters' => collect($migrationContentPlan)->where('recommended_target_type', 'Product')->count(),
                'p1_category_clusters' => collect($migrationContentPlan)->where('recommended_target_type', 'Category')->count(),
                'handoff_reuse' => collect($migrationBacklogHandoff)->where('handoff_state', 'REUSE')->count(),
                'handoff_applied_migration_rows' => $appliedMigrationRows,
                'handoff_preexisting_reuse' => $preexistingReuseRows,
                'handoff_review' => collect($migrationBacklogHandoff)->where('handoff_state', 'REVIEW')->count(),
                'handoff_new' => collect($migrationBacklogHandoff)->where('handoff_state', 'NEW')->count(),
                'mapping_total' => count($migrationMapping),
                'mapping_target_present' => collect($migrationMapping)->where('mapping_state', 'TARGET_PATH_PRESENT')->count(),
                'mapping_pending_create' => collect($migrationMapping)->where('mapping_state', 'PENDING_CREATE')->count(),
                'target_inquiry_pages' => $targetInquiryPages->count(),
                'excluded_non_target_inquiry_evidence' => $excludedInquiryEvidence,
            ],
            'target_ga4_pages' => $targetGa4Pages->take(50)->values()->all(),
            'target_gsc_pages' => $targetGscPages->take(50)->values()->all(),
            'legacy_reference_pages' => $legacyGscPages->take(100)->values()->all(),
            'migration_opportunities' => $migrationOpportunities->take(100)->values()->all(),
            'migration_content_plan' => array_slice($migrationContentPlan, 0, 100),
            'migration_backlog_handoff' => array_slice($migrationBacklogHandoff, 0, 100),
            'migration_mapping' => $migrationMapping,
            'target_inquiry_pages' => $targetInquiryPages->take(50)->values()->all(),
            'contract' => [
                'version' => 'traffic-landing-page-migration-v2',
                'target_site_source' => 'business_project_sites.is_primary',
                'legacy_site_source' => 'business_project_sites.non_primary',
                'migration_match_policy' => 'legacy_gsc_path_to_target_ga4_path',
                'priority_policy' => [
                    'P0' => 'legacy_clicks_gt_0_and_target_path_missing',
                    'P1' => 'legacy_impressions_gt_0_and_target_path_missing',
                    'P2' => 'target_path_present_optimize_verify',
                ],
                'execution_handoff' => 'seo_content_backlog_after_owner_approval',
                'content_cluster_policy' => 'strip_language_host_and_legacy_numeric_suffix',
                'content_type_policy' => 'info_news_to_blog_product_html_to_product_directory_to_category',
                'topic_cluster_policy' => 'deterministic_keyword_rules',
                'backlog_handoff_policy' => 'reuse_exact_url_or_title_then_review_token_similarity_ge_0_60_else_new',
                'review_resolution_policy' => 'explicit_owner-reviewed_intent_and_page_type_resolution_v1',
                'backlog_write_state' => $appliedMigrationRows > 0 ? 'applied' : 'preview_only',
                'migration_mapping_policy' => 'target_exact_path_or_pending_cluster_without_fabricated_url',
                'legacy_gsc_role' => 'reference_only',
                'target_gsc_role' => 'target_visibility',
                'ads_landing_page_state' => 'not_available_in_current_ads_daily_model',
                'canonical_landing_page_state' => 'not_backfilled',
                'revenue_state' => 'excluded',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function buildMigrationMapping(
        string $targetBaseUrl,
        \Illuminate\Support\Collection $migrationOpportunities,
        array $migrationBacklogHandoff
    ): array {
        $targetBaseUrl = rtrim($targetBaseUrl, '/');
        $rows = [];

        $targetGroups = $migrationOpportunities
            ->where('priority', 'P2')
            ->groupBy(fn (array $row): string => (string) $row['path']);

        foreach ($targetGroups as $path => $members) {
            $legacyUrls = $members->pluck('url')->unique()->values()->all();

            $rows[] = [
                'mapping_state' => 'TARGET_PATH_PRESENT',
                'target_url' => $targetBaseUrl !== '' ? $this->targetUrlFromPath($targetBaseUrl, $path) : null,
                'target_path' => $path,
                'backlog_id' => null,
                'cluster_key' => null,
                'title' => null,
                'legacy_urls' => $legacyUrls,
                'legacy_versions' => count($legacyUrls),
                'legacy_impressions' => (int) $members->sum('impressions'),
                'legacy_clicks' => (int) $members->sum('clicks'),
                'target_sessions' => (int) ($members->first()['target_sessions'] ?? 0),
                'note' => 'Target path exists in GA4; migration quality still requires SEO/content verification.',
            ];
        }

        foreach ($migrationBacklogHandoff as $handoff) {
            if (($handoff['handoff_state'] ?? null) !== 'NEW' && ($handoff['match_origin'] ?? null) !== 'migration_applied') {
                continue;
            }

            $clusterKey = (string) $handoff['cluster_key'];
            $legacyMembers = $migrationOpportunities
                ->where('priority', 'P1')
                ->filter(fn (array $row): bool => $this->migrationClusterKey((string) $row['path']) === $clusterKey)
                ->values();

            $legacyUrls = $legacyMembers->pluck('url')->unique()->values()->all();

            $rows[] = [
                'mapping_state' => 'PENDING_CREATE',
                'target_url' => null,
                'target_path' => null,
                'backlog_id' => $handoff['matched_backlog_id'],
                'cluster_key' => $clusterKey,
                'title' => $handoff['title'],
                'legacy_urls' => $legacyUrls,
                'legacy_versions' => count($legacyUrls),
                'legacy_impressions' => (int) $legacyMembers->sum('impressions'),
                'legacy_clicks' => (int) $legacyMembers->sum('clicks'),
                'target_sessions' => null,
                'note' => 'Backlog exists, but target page has not been created; no target URL is fabricated.',
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $rank = ['PENDING_CREATE' => 0, 'TARGET_PATH_PRESENT' => 1];
            $state = ($rank[$a['mapping_state']] ?? 9) <=> ($rank[$b['mapping_state']] ?? 9);
            if ($state !== 0) {
                return $state;
            }

            return $b['legacy_impressions'] <=> $a['legacy_impressions'];
        });

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $contentPlan
     *  @return array<int, array<string, mixed>>
     */
    private function buildMigrationBacklogHandoff(int $projectId, array $contentPlan): array
    {
        $backlogs = DB::table('seo_content_backlog')
            ->where('business_project_id', $projectId)
            ->get(['id', 'title', 'target_url', 'topic_cluster', 'content_type', 'status', 'priority', 'rationale']);

        $rows = [];
        foreach ($contentPlan as $cluster) {
            $state = 'NEW';
            $matched = null;
            $bestSimilarity = 0.0;
            $normalizedClusterTitle = $this->normalizeBacklogTitle((string) $cluster['title']);

            foreach ($backlogs as $backlog) {
                $backlogClusterKey = $this->clusterKeyFromTargetUrl($backlog->target_url);
                if ($backlogClusterKey !== null && $backlogClusterKey === $cluster['cluster_key']) {
                    $state = 'REUSE';
                    $matched = $backlog;
                    $bestSimilarity = 1.0;
                    break;
                }

                if ($normalizedClusterTitle !== '' && $normalizedClusterTitle === $this->normalizeBacklogTitle((string) $backlog->title)) {
                    $state = 'REUSE';
                    $matched = $backlog;
                    $bestSimilarity = 1.0;
                    break;
                }

                $similarity = $this->backlogTokenSimilarity((string) $cluster['title'], (string) $backlog->title);
                if ($similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $matched = $backlog;
                }
            }

            if ($state !== 'REUSE' && $bestSimilarity >= 0.60) {
                $state = 'REVIEW';
            }

            $reviewResolution = $this->migrationReviewResolution((string) $cluster['cluster_key']);
            if ($state === 'REVIEW' && $reviewResolution !== null) {
                $state = $reviewResolution['handoff_state'];
            }

            $rows[] = [
                ...$cluster,
                'handoff_state' => $state,
                'review_resolution' => $reviewResolution['decision'] ?? null,
                'review_resolution_reason' => $reviewResolution['reason'] ?? null,
                'match_similarity' => round($bestSimilarity, 2),
                'matched_backlog_id' => $matched?->id,
                'matched_backlog_title' => $matched?->title,
                'matched_backlog_status' => $matched?->status,
                'matched_backlog_content_type' => $matched?->content_type,
                'matched_backlog_target_url' => $matched?->target_url,
                'match_origin' => $matched !== null && str_starts_with((string) ($matched->rationale ?? ''), 'Legacy migration cluster;')
                    ? 'migration_applied'
                    : ($matched !== null ? 'preexisting_backlog' : null),
                'proposed_backlog' => [
                    'seo_keyword_id' => null,
                    'topic_cluster' => $cluster['seo_topic_cluster'],
                    'content_type' => 'blog',
                    'priority' => 'P1',
                    'status' => 'planned',
                    'title' => $cluster['title'],
                    'target_url' => null,
                    'rationale' => sprintf(
                        'Legacy migration cluster; versions=%d impressions=%d avg_position=%s; source=Traffic OS Migration Content Plan.',
                        (int) $cluster['legacy_versions'],
                        (int) $cluster['legacy_impressions'],
                        $cluster['average_position'] === null ? 'n/a' : number_format((float) $cluster['average_position'], 2, '.', '')
                    ),
                ],
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $rank = ['NEW' => 0, 'REVIEW' => 1, 'REUSE' => 2];
            $state = ($rank[$a['handoff_state']] ?? 9) <=> ($rank[$b['handoff_state']] ?? 9);
            if ($state !== 0) {
                return $state;
            }

            return $b['legacy_impressions'] <=> $a['legacy_impressions'];
        });

        return $rows;
    }

    /** @return array{decision:string,handoff_state:string,reason:string}|null */
    private function migrationReviewResolution(string $clusterKey): ?array
    {
        return match ($clusterKey) {
            'benefits-of-solar-irrigation-system' => [
                'decision' => 'KEEP_NEW',
                'handoff_state' => 'NEW',
                'reason' => 'Informational benefits intent is distinct from the existing commercial product/category optimization target.',
            ],
            'drip-irrigation-accessories-and-tools' => [
                'decision' => 'REUSE',
                'handoff_state' => 'REUSE',
                'reason' => 'Existing target already covers the same accessories-and-tools product/list intent.',
            ],
            'agriculture-drip-irrigation-pipe' => [
                'decision' => 'REUSE',
                'handoff_state' => 'REUSE',
                'reason' => 'Same agriculture drip-pipe product intent is already covered by the existing drip irrigation pipe optimization task.',
            ],
            'drip-irrigation-pipe-connection-method' => [
                'decision' => 'KEEP_NEW',
                'handoff_state' => 'NEW',
                'reason' => 'Connection-method intent is instructional and distinct from the existing commercial drip-pipe product page.',
            ],
            default => null,
        };
    }

    private function normalizeBacklogTitle(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: $value;
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        return trim($value);
    }

    private function clusterKeyFromTargetUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = trim($path, '/');
        $slug = preg_replace('#^(info|news)/#', '', $slug) ?: $slug;
        $slug = preg_replace('#-\d+\.html$#', '', $slug) ?: $slug;
        $slug = preg_replace('#\.html$#', '', $slug) ?: $slug;

        return strtolower($slug);
    }

    private function backlogTokenSimilarity(string $left, string $right): float
    {
        $leftTokens = array_values(array_filter(explode(' ', $this->normalizeBacklogTitle($left))));
        $rightTokens = array_values(array_filter(explode(' ', $this->normalizeBacklogTitle($right))));

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $leftTokens = array_values(array_unique($leftTokens));
        $rightTokens = array_values(array_unique($rightTokens));
        $intersection = count(array_intersect($leftTokens, $rightTokens));
        $union = count(array_unique(array_merge($leftTokens, $rightTokens)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildMigrationContentPlan(\Illuminate\Support\Collection $rows): array
    {
        $clusters = [];

        foreach ($rows as $row) {
            $path = (string) $row['path'];
            $key = $this->migrationClusterKey($path);
            $clusters[$key][] = $row;
        }

        $plan = [];
        foreach ($clusters as $key => $members) {
            $first = $members[0];
            $impressions = array_sum(array_map(fn (array $row): int => (int) $row['impressions'], $members));
            $clicks = array_sum(array_map(fn (array $row): int => (int) $row['clicks'], $members));
            $weightedPositionNumerator = 0.0;
            $weightedPositionDenominator = 0;

            foreach ($members as $member) {
                if ($member['average_position'] !== null && (int) $member['impressions'] > 0) {
                    $weightedPositionNumerator += ((float) $member['average_position']) * (int) $member['impressions'];
                    $weightedPositionDenominator += (int) $member['impressions'];
                }
            }

            $targetType = $this->recommendedTargetType((string) $first['path']);
            $title = $this->titleFromClusterKey($key);

            $plan[] = [
                'cluster_key' => $key,
                'title' => $title,
                'recommended_target_type' => $targetType,
                'seo_topic_cluster' => $this->topicClusterFor($key),
                'execution_action' => match ($targetType) {
                    'Blog' => 'NEW_BLOG',
                    'Product' => 'NEW_PRODUCT_PAGE',
                    'Category' => 'NEW_CATEGORY_PAGE',
                    default => 'CONTENT_REVIEW',
                },
                'legacy_versions' => count($members),
                'legacy_domains' => array_values(array_unique(array_map(fn (array $row): string => (string) $row['domain'], $members))),
                'legacy_impressions' => $impressions,
                'legacy_clicks' => $clicks,
                'average_position' => $weightedPositionDenominator > 0
                    ? round($weightedPositionNumerator / $weightedPositionDenominator, 2)
                    : null,
                'sample_path' => (string) $first['path'],
            ];
        }

        usort($plan, function (array $a, array $b): int {
            $impressions = $b['legacy_impressions'] <=> $a['legacy_impressions'];

            return $impressions !== 0 ? $impressions : ($b['legacy_versions'] <=> $a['legacy_versions']);
        });

        return $plan;
    }

    private function migrationClusterKey(string $path): string
    {
        $slug = trim($path, '/');
        $slug = preg_replace('#^(info|news)/#', '', $slug) ?: $slug;
        $slug = preg_replace('#-\d+\.html$#', '', $slug) ?: $slug;
        $slug = preg_replace('#\.html$#', '', $slug) ?: $slug;

        return strtolower($slug);
    }

    private function titleFromClusterKey(string $key): string
    {
        $title = str_replace(['-', '_'], ' ', $key);
        $title = preg_replace('/\s+/', ' ', $title) ?: $title;

        return ucwords(trim($title));
    }

    private function recommendedTargetType(string $path): string
    {
        $trimmed = trim($path, '/');
        if (str_starts_with($trimmed, 'info/') || str_starts_with($trimmed, 'news/')) {
            return 'Blog';
        }

        if (str_ends_with($trimmed, '.html')) {
            return 'Product';
        }

        if (substr_count($trimmed, '/') <= 1) {
            return 'Category';
        }

        return 'Content Review';
    }

    private function topicClusterFor(string $key): string
    {
        $value = strtolower(str_replace('-', ' ', $key));

        return match (true) {
            str_contains($value, 'hydropon') => 'Hydroponics',
            str_contains($value, 'fertigat') => 'Fertigation',
            str_contains($value, 'solar') => 'Solar Irrigation / Pump',
            str_contains($value, 'micro sprinkler') => 'Micro Sprinkler',
            str_contains($value, 'sprinkler'), str_contains($value, 'sprinkle'), str_contains($value, 'rain gun') => 'Sprinkler Irrigation',
            str_contains($value, 'drip tape') => 'Drip Tape',
            str_contains($value, 'drip pipe'), str_contains($value, 'drip hose'), str_contains($value, 'drip line') => 'Drip Pipe / Line',
            str_contains($value, 'drip irrigation kit'), str_contains($value, '1 hectare'), str_contains($value, 'one hectare') => 'Drip Irrigation Kit',
            str_contains($value, 'dripper'), str_contains($value, 'emitter') => 'Dripper / Emitter',
            str_contains($value, 'filter') => 'Filter',
            str_contains($value, 'valve') => 'Valve',
            str_contains($value, 'connector'), str_contains($value, 'fitting') => 'Connector / Fitting',
            str_contains($value, 'greenhouse') => 'Greenhouse Irrigation',
            str_contains($value, 'drip irrigation') => 'Drip Irrigation System',
            str_contains($value, 'irrigation') => 'Irrigation General',
            default => 'Other',
        };
    }

    private function targetUrlFromPath(string $baseUrl, string $path): string
    {
        $path = $this->normalizePath($path);
        if (str_ends_with($path, '.html/')) {
            $path = rtrim($path, '/');
        }

        return rtrim($baseUrl, '/').$path;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        return preg_replace('/^www\./', '', $host) ?: $host;
    }

    private function hostBelongsTo(string $host, string $rootDomain): bool
    {
        if ($host === '' || $rootDomain === '') {
            return false;
        }

        return $host === $rootDomain || str_ends_with($host, '.'.$rootDomain);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: $path;

        return $path === '/' ? '/' : rtrim($path, '/').'/';
    }
}

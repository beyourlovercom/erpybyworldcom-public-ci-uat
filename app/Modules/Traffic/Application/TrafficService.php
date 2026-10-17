<?php

namespace App\Modules\Traffic\Application;

use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use Illuminate\Support\Collection;

final class TrafficService
{
    public function __construct(private readonly TrafficRepository $repository) {}

    public function upsertTrafficSourceAccount(int $businessProjectId, string $provider, string $externalAccountId, array $attributes = []): object { return $this->repository->upsertSourceAccount($businessProjectId, $provider, $externalAccountId, $attributes); }
    public function upsertTrafficCampaign(int $trafficSourceAccountId, string $externalCampaignId, array $attributes = []): object { return $this->repository->upsertCampaign($trafficSourceAccountId, $externalCampaignId, $attributes); }
    public function upsertTrafficDailyMetric(int $trafficCampaignId, string $metricDate, array $attributes): object { return $this->repository->upsertDailyMetric($trafficCampaignId, $metricDate, $attributes); }
    public function upsertTrafficInquiryDailyMetric(int $businessProjectId, string $metricDate, string $sourceType, array $attributes): object { return $this->repository->upsertInquiryDailyMetric($businessProjectId, $metricDate, $sourceType, $attributes); }

    /** @return Collection<int, object> */
    public function listProjectSourceAccounts(int $businessProjectId): Collection { return $this->repository->listProjectSourceAccounts($businessProjectId); }
    /** @return Collection<int, object> */
    public function listProjectDailyTrafficMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection { return $this->repository->listProjectDailyTrafficMetrics($businessProjectId, $dateFrom, $dateTo); }
    /** @return Collection<int, object> */
    public function listProjectDailyInquiryMetrics(int $businessProjectId, string $dateFrom, string $dateTo): Collection { return $this->repository->listProjectDailyInquiryMetrics($businessProjectId, $dateFrom, $dateTo); }
    public function registerLandingPage(int $businessProjectId, string $pageKey, array $attributes): object { return $this->repository->registerLandingPage($businessProjectId, $pageKey, $attributes); }
    /** @return Collection<int, object> */
    public function listLandingPages(int $businessProjectId, string $status = 'active'): Collection { return $this->repository->listLandingPages($businessProjectId, $status); }

    /** Resolve explicit inquiry evidence only; metrics are deliberately not consulted. */
    public function resolveAttributionEvidence(int $businessProjectId, array $evidence): array
    {
        $source = strtolower((string) ($this->text($evidence['utm_source'] ?? null) ?? ''));
        $campaignReference = $this->text($evidence['utm_campaign'] ?? null);
        $campaignState = 'not_applicable'; $campaignId = null; $accountId = null; $methods = [];
        if ($campaignReference !== null) {
            $needle = strtolower($campaignReference);
            $matches = $this->repository->listProjectCampaigns($businessProjectId)->filter(function (object $campaign) use ($needle, $source): bool {
                $provider = strtolower((string) ($campaign->sourceAccount?->provider ?? ''));
                $providerMatches = $source === '' || $provider === '' || $source === $provider || ($source === 'google' && str_contains($provider, 'google')) || ($source === 'meta' && str_contains($provider, 'meta'));
                return $providerMatches && in_array($needle, [strtolower(trim((string) $campaign->external_campaign_id)), strtolower(trim((string) $campaign->name))], true);
            })->values();
            if ($matches->count() === 1) { $campaign = $matches->first(); $campaignState = 'matched'; $campaignId = (int) $campaign->id; $accountId = (int) $campaign->traffic_source_account_id; $methods[] = 'campaign_exact'; }
            else { $campaignState = $matches->count() > 1 ? 'ambiguous' : 'unresolved'; }
        } elseif ($this->text($evidence['gclid'] ?? null) !== null) { $campaignState = 'unresolved'; }

        if ($accountId === null && $source !== '') {
            $accounts = $this->repository->listProjectSourceAccounts($businessProjectId)->filter(function (object $account) use ($source): bool {
                $provider = strtolower((string) ($account->provider ?? ''));
                return $source === $provider || ($source === 'google' && str_contains($provider, 'google')) || ($source === 'meta' && str_contains($provider, 'meta'));
            })->values();
            if ($accounts->count() === 1) { $accountId = (int) $accounts->first()->id; $methods[] = 'provider_account_exact'; }
        }

        $sourceUrl = $this->text($evidence['source_url'] ?? null); $landingState = 'not_applicable'; $landingPageId = null;
        if ($sourceUrl !== null) {
            $identity = $this->urlIdentity($sourceUrl); $path = $this->urlPath($sourceUrl);
            $matches = $this->repository->listLandingPages($businessProjectId, 'active')->filter(function (object $page) use ($identity, $path): bool {
                $canonical = $this->text($page->canonical_url ?? null);
                return ($canonical !== null && $identity !== null && $this->urlIdentity($canonical) === $identity) || ($this->text($page->path ?? null) !== null && $path !== null && $this->normalizePath((string) $page->path) === $path);
            })->values();
            if ($matches->count() === 1) { $landingState = 'matched'; $landingPageId = (int) $matches->first()->id; $methods[] = 'landing_exact'; }
            else { $landingState = $matches->count() > 1 ? 'ambiguous' : 'unresolved'; }
        }
        return ['traffic_source_account_id' => $accountId, 'traffic_campaign_id' => $campaignId, 'landing_page_id' => $landingPageId, 'campaign_match_state' => $campaignState, 'landing_page_match_state' => $landingState, 'match_method' => $methods === [] ? null : implode('+', $methods)];
    }

    private function urlIdentity(string $url): ?string { $parts = parse_url(trim($url)); return is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']).$this->normalizePath((string) ($parts['path'] ?? '/')) : null; }
    private function urlPath(string $url): ?string { $parts = parse_url(trim($url)); return is_array($parts) && isset($parts['path']) ? $this->normalizePath((string) $parts['path']) : null; }
    private function normalizePath(string $path): string { return '/'.trim(strtolower($path), '/'); }
    private function text(mixed $value): ?string { if (! is_scalar($value)) return null; $value = trim((string) $value); return $value === '' ? null : $value; }
}

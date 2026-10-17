<?php

namespace App\Modules\Customers\Application;

use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
use Illuminate\Support\Collection;

final class CustomerReceivedTruthService
{
    public function totalsForProjectCompanies(int $projectId, array $companyIds): array
    {
        $companyIds = collect($companyIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        if ($companyIds === []) {
            return [];
        }

        return SalesPaymentReceipt::query()
            ->where('business_project_id', $projectId)
            ->whereIn('company_id', $companyIds)
            ->where('status', 'confirmed')
            ->selectRaw('company_id, currency, SUM(amount_micros) AS amount_micros, COUNT(*) AS receipt_count')
            ->groupBy('company_id', 'currency')
            ->orderBy('company_id')
            ->orderBy('currency')
            ->get()
            ->groupBy('company_id')
            ->map(fn (Collection $rows): array => $rows->map(fn ($row): array => [
                'currency' => $row->currency,
                'amount_micros' => (int) $row->amount_micros,
                'receipt_count' => (int) $row->receipt_count,
            ])->values()->all())
            ->all();
    }

    public function totalsForCompanyProjects(int $companyId, array $projectIds): array
    {
        $projectIds = collect($projectIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        if ($projectIds === []) {
            return [];
        }

        return SalesPaymentReceipt::query()
            ->where('company_id', $companyId)
            ->whereIn('business_project_id', $projectIds)
            ->where('status', 'confirmed')
            ->selectRaw('business_project_id, currency, SUM(amount_micros) AS amount_micros, COUNT(*) AS receipt_count')
            ->groupBy('business_project_id', 'currency')
            ->orderBy('business_project_id')
            ->orderBy('currency')
            ->get()
            ->groupBy('business_project_id')
            ->map(fn (Collection $rows): array => $rows->map(fn ($row): array => [
                'currency' => $row->currency,
                'amount_micros' => (int) $row->amount_micros,
                'receipt_count' => (int) $row->receipt_count,
            ])->values()->all())
            ->all();
    }
}

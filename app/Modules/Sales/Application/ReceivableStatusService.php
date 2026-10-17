<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunity;
use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
use App\Modules\Sales\Infrastructure\Models\SalesQuote;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReceivableStatusService
{
    public const STATUS_PENDING = 'pending_receivable';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERPAID = 'overpaid';

    public function __construct(
        private readonly BusinessProjectService $projects,
        private readonly AuditRecorder $audit,
    ) {}

    public function confirmManual(string $projectKey, int $opportunityId, int $amountMicros, string $currency, ?string $note, ?Authenticatable $actor): SalesOpportunity
    {
        $opportunity = $this->opportunity($projectKey, $opportunityId);
        $this->assertConfirmable($opportunity);

        if ($amountMicros <= 0) {
            throw new InvalidArgumentException('Receivable amount must be greater than zero.');
        }

        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Receivable currency must be a 3-letter ISO code.');
        }

        return $this->confirm($opportunity, [
            'receivable_amount_micros' => $amountMicros,
            'receivable_currency' => $currency,
            'receivable_source' => 'manual',
            'receivable_quote_id' => null,
            'receivable_note' => $this->nullableText($note),
        ], $actor);
    }

    public function confirmFromQuote(string $projectKey, int $opportunityId, int $quoteId, ?string $note, ?Authenticatable $actor): SalesOpportunity
    {
        $opportunity = $this->opportunity($projectKey, $opportunityId);
        $this->assertConfirmable($opportunity);

        $quote = SalesQuote::query()
            ->where('opportunity_id', $opportunity->id)
            ->findOrFail($quoteId);

        if ($quote->total_micros <= 0) {
            throw new InvalidArgumentException('Receivable quote total must be greater than zero.');
        }

        return $this->confirm($opportunity, [
            'receivable_amount_micros' => (int) $quote->total_micros,
            'receivable_currency' => strtoupper($quote->currency),
            'receivable_source' => 'quote',
            'receivable_quote_id' => $quote->id,
            'receivable_note' => $this->nullableText($note),
        ], $actor);
    }

    public function summary(string $projectKey, int $opportunityId): array
    {
        $opportunity = $this->opportunity($projectKey, $opportunityId);

        if ($opportunity->receivable_amount_micros === null || ! $opportunity->receivable_currency) {
            return [
                'status' => self::STATUS_PENDING,
                'amount_micros' => null,
                'currency' => null,
                'received_micros' => 0,
                'outstanding_micros' => null,
                'overpaid_micros' => 0,
                'source' => null,
                'quote_id' => null,
                'quote_number' => null,
                'confirmed_at' => null,
                'confirmed_by' => null,
                'note' => null,
                'other_currency_totals' => $this->otherCurrencyTotals($opportunity, null),
            ];
        }

        $currency = $opportunity->receivable_currency;
        $amount = (int) $opportunity->receivable_amount_micros;
        $received = (int) SalesPaymentReceipt::query()
            ->where('business_project_id', $opportunity->business_project_id)
            ->where('opportunity_id', $opportunity->id)
            ->where('status', 'confirmed')
            ->where('currency', $currency)
            ->sum('amount_micros');

        $status = match (true) {
            $received === 0 => self::STATUS_UNPAID,
            $received < $amount => self::STATUS_PARTIAL,
            $received === $amount => self::STATUS_PAID,
            default => self::STATUS_OVERPAID,
        };

        $opportunity->loadMissing(['receivableQuote:id,quote_number', 'receivableConfirmer:id,name']);

        return [
            'status' => $status,
            'amount_micros' => $amount,
            'currency' => $currency,
            'received_micros' => $received,
            'outstanding_micros' => max(0, $amount - $received),
            'overpaid_micros' => max(0, $received - $amount),
            'source' => $opportunity->receivable_source,
            'quote_id' => $opportunity->receivable_quote_id,
            'quote_number' => $opportunity->receivableQuote?->quote_number,
            'confirmed_at' => $opportunity->receivable_confirmed_at?->toIso8601String(),
            'confirmed_by' => $opportunity->receivableConfirmer ? [
                'id' => $opportunity->receivableConfirmer->id,
                'name' => $opportunity->receivableConfirmer->name,
            ] : null,
            'note' => $opportunity->receivable_note,
            'other_currency_totals' => $this->otherCurrencyTotals($opportunity, $currency),
        ];
    }

    private function confirm(SalesOpportunity $opportunity, array $truth, ?Authenticatable $actor): SalesOpportunity
    {
        return DB::transaction(function () use ($opportunity, $truth, $actor): SalesOpportunity {
            $before = $this->snapshot($opportunity);

            $opportunity->forceFill([
                ...$truth,
                'receivable_confirmed_at' => now(),
                'receivable_confirmed_by' => $actor?->getAuthIdentifier(),
            ])->save();

            $this->recordAudit(
                $actor,
                'sales.receivable.confirmed',
                $opportunity,
                $before,
                $this->snapshot($opportunity),
                'receivable_confirmed'
            );

            return $opportunity->fresh(['receivableQuote', 'receivableConfirmer']);
        });
    }

    private function opportunity(string $projectKey, int $opportunityId): SalesOpportunity
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new InvalidArgumentException('Business project not found.');
        }

        return SalesOpportunity::query()
            ->where('business_project_id', $project->id)
            ->findOrFail($opportunityId);
    }

    private function assertConfirmable(SalesOpportunity $opportunity): void
    {
        if ($opportunity->status === 'lost') {
            throw new InvalidArgumentException('Lost opportunity cannot receive a receivable baseline.');
        }
    }

    private function otherCurrencyTotals(SalesOpportunity $opportunity, ?string $primaryCurrency): array
    {
        return SalesPaymentReceipt::query()
            ->where('business_project_id', $opportunity->business_project_id)
            ->where('opportunity_id', $opportunity->id)
            ->where('status', 'confirmed')
            ->when($primaryCurrency !== null, fn ($query) => $query->where('currency', '!=', $primaryCurrency))
            ->selectRaw('currency, SUM(amount_micros) AS amount_micros, COUNT(*) AS receipt_count')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'currency' => $row->currency,
                'amount_micros' => (int) $row->amount_micros,
                'receipt_count' => (int) $row->receipt_count,
            ])
            ->values()
            ->all();
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function snapshot(Model $opportunity): array
    {
        return $opportunity->only([
            'receivable_amount_micros',
            'receivable_currency',
            'receivable_source',
            'receivable_quote_id',
            'receivable_confirmed_at',
            'receivable_confirmed_by',
            'receivable_note',
        ]);
    }

    private function recordAudit(?Authenticatable $actor, string $action, Model $entity, ?array $before, ?array $after, string $reason): void
    {
        $auditActor = $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem);
        $this->audit->record($auditActor, $action, get_class($entity), $entity->getKey(), $before, $after, $reason);
    }
}

<?php

namespace App\Modules\Sales\Application;

use App\Models\User;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\BusinessProjects\Application\BusinessProjectService;
use App\Modules\Sales\Infrastructure\Models\SalesOpportunity;
use App\Modules\Sales\Infrastructure\Models\SalesPaymentReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PaymentReceiptService
{
    public const PAYMENT_METHODS = ['bank_transfer', 'alipay', 'alibaba', 'paypal', 'stripe', 'other'];

    public function __construct(
        private readonly BusinessProjectService $projects,
        private readonly AuditRecorder $audit,
    ) {}

    public function record(string $projectKey, int $opportunityId, array $attributes, ?Authenticatable $actor): SalesPaymentReceipt
    {
        $project = $this->project($projectKey);
        $opportunity = SalesOpportunity::query()
            ->where('business_project_id', $project->id)
            ->findOrFail($opportunityId);

        $amountMicros = (int) ($attributes['amount_micros'] ?? 0);
        if ($amountMicros <= 0) {
            throw new InvalidArgumentException('Payment receipt amount must be greater than zero.');
        }

        $currency = strtoupper(trim((string) ($attributes['currency'] ?? $opportunity->currency)));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Payment receipt currency must be a 3-letter ISO code.');
        }

        $paymentMethod = strtolower(trim((string) ($attributes['payment_method'] ?? '')));
        if (! in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            throw new InvalidArgumentException('Unsupported payment method.');
        }

        return DB::transaction(function () use ($project, $opportunity, $attributes, $actor, $amountMicros, $currency, $paymentMethod): SalesPaymentReceipt {
            $receipt = SalesPaymentReceipt::create([
                'receipt_number' => $this->number(),
                'business_project_id' => $project->id,
                'opportunity_id' => $opportunity->id,
                'company_id' => $opportunity->company_id,
                'recorded_by' => $actor?->getAuthIdentifier(),
                'amount_micros' => $amountMicros,
                'currency' => $currency,
                'received_at' => $attributes['received_at'] ?? now(),
                'payment_method' => $paymentMethod,
                'entry_source' => 'manual',
                'provider_reference' => $this->nullableText($attributes['provider_reference'] ?? null),
                'evidence_reference' => $this->nullableText($attributes['evidence_reference'] ?? null),
                'note' => $this->nullableText($attributes['note'] ?? null),
                'status' => 'confirmed',
            ]);

            $this->recordAudit($actor, 'sales.payment_receipt.recorded', $receipt, null, $this->snapshot($receipt), 'payment_receipt_recorded');

            return $receipt->fresh(['project', 'opportunity', 'company', 'recorder']);
        });
    }

    public function void(string $projectKey, int $receiptId, string $reason, ?Authenticatable $actor): SalesPaymentReceipt
    {
        $project = $this->project($projectKey);
        $receipt = SalesPaymentReceipt::query()
            ->where('business_project_id', $project->id)
            ->findOrFail($receiptId);

        return $this->voidReceipt($receipt, $reason, $actor);
    }

    public function voidForOpportunity(string $projectKey, int $opportunityId, int $receiptId, string $reason, ?Authenticatable $actor): SalesPaymentReceipt
    {
        $project = $this->project($projectKey);
        $receipt = SalesPaymentReceipt::query()
            ->where('business_project_id', $project->id)
            ->where('opportunity_id', $opportunityId)
            ->findOrFail($receiptId);

        return $this->voidReceipt($receipt, $reason, $actor);
    }

    public function listForOpportunity(string $projectKey, int $opportunityId): Collection
    {
        $project = $this->project($projectKey);

        return SalesPaymentReceipt::query()
            ->with(['recorder:id,name,email', 'voider:id,name,email'])
            ->where('business_project_id', $project->id)
            ->where('opportunity_id', $opportunityId)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();
    }

    public function ledger(string $projectKey, array $filters): Collection
    {
        $project = $this->project($projectKey);
        $query = $this->ledgerQuery((int) $project->id, $filters)
            ->with([
                'company:id,name',
                'opportunity:id,opportunity_number,title',
                'recorder:id,name,email',
                'voider:id,name,email',
            ]);

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    public function confirmedTotalsForProject(string $projectKey, array $filters): array
    {
        $project = $this->project($projectKey);

        return $this->ledgerQuery((int) $project->id, $filters)
            ->where('status', 'confirmed')
            ->get(['currency', 'amount_micros'])
            ->groupBy('currency')
            ->map(fn ($rows, string $currency): array => [
                'currency' => $currency,
                'amount_micros' => (int) $rows->sum('amount_micros'),
                'receipt_count' => $rows->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    public function auditTrail(Collection $receipts): array
    {
        $receiptIds = $receipts->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if ($receiptIds === []) {
            return [];
        }

        $records = AuditRecord::query()
            ->where('entity_type', SalesPaymentReceipt::class)
            ->whereIn('entity_id', $receiptIds)
            ->whereIn('action', ['sales.payment_receipt.recorded', 'sales.payment_receipt.voided'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'actor_id', 'action', 'entity_id', 'source', 'reason', 'created_at']);

        $actors = User::query()
            ->whereIn('id', $records->pluck('actor_id')->filter()->unique()->values())
            ->get(['id', 'name'])
            ->keyBy('id');

        return $records
            ->groupBy(fn ($record): int => (int) $record->entity_id)
            ->map(fn ($events): array => $events->map(fn ($event): array => [
                'id' => (int) $event->id,
                'action' => (string) $event->action,
                'actor_id' => $event->actor_id === null ? null : (int) $event->actor_id,
                'actor_name' => $event->actor_id === null ? 'System' : ($actors->get((int) $event->actor_id)?->name ?? 'Unknown'),
                'source' => (string) $event->source,
                'reason' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all())
            ->all();
    }

    public function confirmedTotalsForOpportunity(string $projectKey, int $opportunityId): array
    {
        return $this->listForOpportunity($projectKey, $opportunityId)
            ->where('status', 'confirmed')
            ->groupBy('currency')
            ->map(fn ($rows, string $currency): array => [
                'currency' => $currency,
                'amount_micros' => (int) $rows->sum('amount_micros'),
                'receipt_count' => $rows->count(),
            ])
            ->values()
            ->all();
    }

    private function ledgerQuery(int $projectId, array $filters): Builder
    {
        $query = SalesPaymentReceipt::query()->where('business_project_id', $projectId);
        if (isset($filters['owner_id'])) {
            $ownerId = (int) $filters['owner_id'];
            $query->whereHas('opportunity', fn (Builder $q) => $q->where('owner_id', $ownerId));
        }

        if (! empty($filters['date_from'])) {
            $query->where('received_at', '>=', CarbonImmutable::parse((string) $filters['date_from'])->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('received_at', '<', CarbonImmutable::parse((string) $filters['date_to'])->addDay()->startOfDay());
        }
        if (! empty($filters['currency'])) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder
                    ->where('receipt_number', 'like', $like)
                    ->orWhere('provider_reference', 'like', $like)
                    ->orWhere('evidence_reference', 'like', $like)
                    ->orWhere('note', 'like', $like)
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', $like))
                    ->orWhereHas('opportunity', fn (Builder $opportunity) => $opportunity
                        ->where('opportunity_number', 'like', $like)
                        ->orWhere('title', 'like', $like));
            });
        }

        return $query;
    }

    private function voidReceipt(SalesPaymentReceipt $receipt, string $reason, ?Authenticatable $actor): SalesPaymentReceipt
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Void reason is required.');
        }

        return DB::transaction(function () use ($receipt, $reason, $actor): SalesPaymentReceipt {
            if ($receipt->status === 'voided') {
                return $receipt;
            }

            $before = $this->snapshot($receipt);
            $receipt->forceFill([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $actor?->getAuthIdentifier(),
                'void_reason' => $reason,
            ])->save();

            $this->recordAudit($actor, 'sales.payment_receipt.voided', $receipt, $before, $this->snapshot($receipt), 'payment_receipt_voided');

            return $receipt->fresh(['project', 'opportunity', 'company', 'recorder', 'voider']);
        });
    }

    private function project(string $projectKey): object
    {
        $project = $this->projects->findByKey($projectKey);
        if (! $project || $project->status !== 'active') {
            throw new InvalidArgumentException('Business project not found.');
        }

        return $project;
    }

    private function number(): string
    {
        return 'YBY-PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function snapshot(Model $receipt): array
    {
        return $receipt->only([
            'business_project_id', 'opportunity_id', 'company_id', 'recorded_by',
            'amount_micros', 'currency', 'received_at', 'payment_method', 'entry_source',
            'provider_reference', 'evidence_reference', 'status', 'voided_at', 'voided_by', 'void_reason',
        ]);
    }

    private function recordAudit(?Authenticatable $actor, string $action, Model $entity, ?array $before, ?array $after, string $reason): void
    {
        $auditActor = $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem);
        $this->audit->record($auditActor, $action, get_class($entity), $entity->getKey(), $before, $after, $reason);
    }
}

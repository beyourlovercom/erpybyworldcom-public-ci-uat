<?php

namespace App\Modules\Inquiries\Experience\Console;

use App\Modules\Inquiries\Application\InquiryAttributionService;
use Illuminate\Console\Command;
use Throwable;

final class ResolveInquiryAttributionCommand extends Command
{
    protected $signature = 'inquiries:attribution:resolve {project=yby-irrigation} {--apply : Persist deterministic attribution resolution}';
    protected $description = 'Resolve Canonical Case attribution using explicit UTM/click/landing-page evidence without guessing.';

    public function handle(InquiryAttributionService $service): int
    {
        try {
            $summary = $service->resolveProject((string) $this->argument('project'), (bool) $this->option('apply'));
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
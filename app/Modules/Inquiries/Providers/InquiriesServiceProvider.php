<?php

namespace App\Modules\Inquiries\Providers;

use App\Modules\Inquiries\Application\Contracts\InquiryCaseRepository;
use App\Modules\Inquiries\Experience\Console\SyncLocalWordPressInquiriesCommand;
use App\Modules\Inquiries\Experience\Console\ResolveInquiryAttributionCommand;
use App\Modules\Inquiries\Infrastructure\Repositories\EloquentInquiryCaseRepository;
use Illuminate\Support\ServiceProvider;

final class InquiriesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InquiryCaseRepository::class, EloquentInquiryCaseRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncLocalWordPressInquiriesCommand::class, ResolveInquiryAttributionCommand::class]);
        }
    }
}

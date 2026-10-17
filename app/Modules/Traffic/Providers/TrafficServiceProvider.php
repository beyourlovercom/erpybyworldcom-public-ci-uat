<?php

namespace App\Modules\Traffic\Providers;

use App\Modules\Traffic\Application\Contracts\Ga4ReadClient;
use App\Modules\Traffic\Application\Contracts\GoogleAdsReadClient;
use App\Modules\Traffic\Application\Contracts\GscReadClient;
use App\Modules\Traffic\Application\Contracts\InquiryMetricSignalReader;
use App\Modules\Traffic\Application\Contracts\MetaReadClient;
use App\Modules\Traffic\Application\Contracts\TrafficRepository;
use App\Modules\Traffic\Application\Contracts\YouTubeReadClient;
use App\Modules\Traffic\Experience\Console\Ga4TrafficSyncCommand;
use App\Modules\Traffic\Experience\Console\GoogleAdsTrafficSyncCommand;
use App\Modules\Traffic\Experience\Console\GscSeoSyncCommand;
use App\Modules\Traffic\Experience\Console\InquiryMetricsSyncCommand;
use App\Modules\Traffic\Experience\Console\MetaTrafficSyncCommand;
use App\Modules\Traffic\Experience\Console\SeoBacklogMergeCommand;
use App\Modules\Traffic\Experience\Console\SeoClusterConsolidationCommand;
use App\Modules\Traffic\Experience\Console\SeoContentBacklogCommand;
use App\Modules\Traffic\Experience\Console\SeoGrowthExistingPublicationCommand;
use App\Modules\Traffic\Experience\Console\SeoKeywordSeedImportCommand;
use App\Modules\Traffic\Experience\Console\SeoNewContentPublicationCommand;
use App\Modules\Traffic\Experience\Console\SeoOpportunityRefeedCommand;
use App\Modules\Traffic\Experience\Console\SeoOptimizationWorkflowCommand;
use App\Modules\Traffic\Experience\Console\SeoPostPublishMeasurementCommand;
use App\Modules\Traffic\Experience\Console\YouTubeTrafficSyncCommand;
use App\Modules\Traffic\Infrastructure\Ga4\HttpGa4ReadClient;
use App\Modules\Traffic\Infrastructure\GoogleAds\HttpGoogleAdsReadClient;
use App\Modules\Traffic\Infrastructure\Gsc\HttpGscReadClient;
use App\Modules\Traffic\Infrastructure\Meta\UnavailableMetaReadClient;
use App\Modules\Traffic\Infrastructure\Repositories\EloquentTrafficRepository;
use App\Modules\Traffic\Infrastructure\YouTube\HttpYouTubeReadClient;
use Illuminate\Support\ServiceProvider;

final class TrafficServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TrafficRepository::class, EloquentTrafficRepository::class);
        $this->app->bind(Ga4ReadClient::class, HttpGa4ReadClient::class);
        $this->app->bind(GoogleAdsReadClient::class, HttpGoogleAdsReadClient::class);
        $this->app->bind(GscReadClient::class, HttpGscReadClient::class);
        $this->app->bind(InquiryMetricSignalReader::class, HttpGoogleAdsReadClient::class);
        $this->app->bind(MetaReadClient::class, UnavailableMetaReadClient::class);
        $this->app->bind(YouTubeReadClient::class, HttpYouTubeReadClient::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Ga4TrafficSyncCommand::class,
                GoogleAdsTrafficSyncCommand::class,
                GscSeoSyncCommand::class,
                InquiryMetricsSyncCommand::class,
                MetaTrafficSyncCommand::class,
                YouTubeTrafficSyncCommand::class,
                SeoKeywordSeedImportCommand::class,
                SeoBacklogMergeCommand::class,
                SeoClusterConsolidationCommand::class,
                SeoContentBacklogCommand::class,
                SeoOpportunityRefeedCommand::class,
                SeoNewContentPublicationCommand::class,
                SeoGrowthExistingPublicationCommand::class,
                SeoOptimizationWorkflowCommand::class,
                SeoPostPublishMeasurementCommand::class,
            ]);
        }
    }
}

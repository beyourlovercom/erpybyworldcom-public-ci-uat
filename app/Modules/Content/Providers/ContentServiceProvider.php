<?php

namespace App\Modules\Content\Providers;

use App\Modules\Content\Application\Contracts\BrandContextProvider;
use App\Modules\Content\Application\Contracts\ContentArticleRepository;
use App\Modules\Content\Application\Contracts\ContentSourceAdapter;
use App\Modules\Content\Application\Contracts\DocumentImportRepository;
use App\Modules\Content\Application\Contracts\DocumentReadClient;
use App\Modules\Content\Application\Contracts\DraftPublishingAdapter;
use App\Modules\Content\Application\Contracts\EditorialAiClient;
use App\Modules\Content\Application\Contracts\EditorialRepository;
use App\Modules\Content\Application\Contracts\GuidelineRepository;
use App\Modules\Content\Application\Contracts\InternalLinkProvider;
use App\Modules\Content\Application\Contracts\PublicationRepository;
use App\Modules\Content\Application\Contracts\PublishingAdapter;
use App\Modules\Content\Application\Contracts\WriterPoolRepository;
use App\Modules\Content\Infrastructure\Ai\OpenAiEditorialClient;
use App\Modules\Content\Infrastructure\Ai\UnavailableEditorialAiClient;
use App\Modules\Content\Infrastructure\AndyCoreContentPublishingAdapter;
use App\Modules\Content\Infrastructure\EloquentContentArticleRepository;
use App\Modules\Content\Infrastructure\EloquentDocumentImportRepository;
use App\Modules\Content\Infrastructure\EloquentEditorialRepository;
use App\Modules\Content\Infrastructure\EloquentGuidelineRepository;
use App\Modules\Content\Infrastructure\EloquentPublicationRepository;
use App\Modules\Content\Infrastructure\EloquentWriterPoolRepository;
use App\Modules\Content\Infrastructure\GoogleDocs\HttpGoogleDocsReadClient;
use App\Modules\Content\Infrastructure\PreviewOnlyPublishingAdapter;
use App\Modules\Content\Infrastructure\Yby\YbyBrandContextProvider;
use App\Modules\Content\Infrastructure\Yby\YbyInternalLinkProvider;
use App\Modules\Content\Infrastructure\Yby\YbySeoContentSourceAdapter;
use Illuminate\Support\ServiceProvider;

final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContentArticleRepository::class, EloquentContentArticleRepository::class);
        $this->app->bind(ContentSourceAdapter::class, YbySeoContentSourceAdapter::class);
        $this->app->bind(WriterPoolRepository::class, EloquentWriterPoolRepository::class);
        $this->app->bind(GuidelineRepository::class, EloquentGuidelineRepository::class);
        $this->app->bind(BrandContextProvider::class, YbyBrandContextProvider::class);
        $this->app->bind(InternalLinkProvider::class, YbyInternalLinkProvider::class);
        $this->app->bind(DocumentImportRepository::class, EloquentDocumentImportRepository::class);
        $this->app->bind(DocumentReadClient::class, HttpGoogleDocsReadClient::class);
        $this->app->bind(EditorialRepository::class, EloquentEditorialRepository::class);
        $this->app->bind(PublicationRepository::class, EloquentPublicationRepository::class);
        $this->app->bind(PreviewOnlyPublishingAdapter::class);
        $this->app->bind(AndyCoreContentPublishingAdapter::class);
        $this->app->bind(PublishingAdapter::class, AndyCoreContentPublishingAdapter::class);
        $this->app->bind(DraftPublishingAdapter::class, AndyCoreContentPublishingAdapter::class);
        $this->app->bind(EditorialAiClient::class, function ($app): EditorialAiClient {
            return config('services.content_ai.provider', 'openai') === 'openai'
                ? $app->make(OpenAiEditorialClient::class)
                : $app->make(UnavailableEditorialAiClient::class);
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}

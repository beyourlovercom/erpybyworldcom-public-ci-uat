<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Providers\ContentServiceProvider;
use App\Modules\ModuleManifest;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ContentArchitectureTest extends TestCase
{
    public function test_content_provider_is_registered_in_the_canonical_manifest(): void
    {
        self::assertContains(ContentServiceProvider::class, ModuleManifest::providers());
    }

    public function test_portable_application_layer_has_no_traffic_or_infrastructure_dependency(): void
    {
        $root = dirname(__DIR__, 3).'/app/Modules/Content/Application';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'App\\Modules\\Traffic\\')
                || str_contains($source, 'App\\Modules\\Content\\Infrastructure\\')) {
                $violations[] = $file->getFilename();
            }
        }

        self::assertSame([], $violations);
    }
}

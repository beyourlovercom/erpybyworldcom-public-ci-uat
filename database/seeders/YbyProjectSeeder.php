<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class YbyProjectSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'YBY project seeding is restricted to local/testing environments.');
        abort_unless(Schema::hasTable('business_projects'), 500, 'YBY project seeding requires the migrated schema.');

        $projectClass = 'App\\Modules\\BusinessProjects\\Infrastructure\\Models\\BusinessProject';
        $siteClass = 'App\\Modules\\BusinessProjects\\Infrastructure\\Models\\BusinessProjectSite';

        $projects = [
            'yby-irrigation' => [
                'name' => 'YBY Irrigation',
                'sites' => [
                    ['site_key' => 'ybyirrigation-com', 'domain' => 'ybyirrigation.com', 'url' => 'https://ybyirrigation.com', 'primary' => true],
                    ['site_key' => 'yby-irrigation-com', 'domain' => 'yby-irrigation.com', 'url' => 'https://yby-irrigation.com', 'primary' => false],
                ],
            ],
            'yby-bottle' => [
                'name' => 'YBY Bottle',
                'sites' => [
                    ['site_key' => 'ybybottle-com', 'domain' => 'ybybottle.com', 'url' => 'https://ybybottle.com', 'primary' => true],
                ],
            ],
            'yby-world' => [
                'name' => 'YBY World',
                'sites' => [
                    ['site_key' => 'ybyworld-com', 'domain' => 'ybyworld.com', 'url' => 'https://www.ybyworld.com', 'primary' => true],
                ],
            ],
        ];

        foreach ($projects as $projectKey => $definition) {
            $project = $projectClass::firstOrCreate(
                ['project_key' => $projectKey],
                ['name' => $definition['name'], 'status' => 'active'],
            );
            foreach ($definition['sites'] as $site) {
                $siteClass::firstOrCreate(
                    ['domain_normalized' => $site['domain']],
                    [
                        'business_project_id' => $project->id,
                        'site_key' => $site['site_key'],
                        'base_url' => $site['url'],
                        'status' => 'active',
                        'is_primary' => $site['primary'],
                    ],
                );
            }
        }
    }
}
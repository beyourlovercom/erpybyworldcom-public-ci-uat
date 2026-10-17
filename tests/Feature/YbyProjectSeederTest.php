<?php

namespace Tests\Feature;

use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use Database\Seeders\YbyProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class YbyProjectSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_registers_three_canonical_yby_projects(): void
    {
        $this->seed(YbyProjectSeeder::class);

        $this->assertSame(
            ['yby-irrigation', 'yby-bottle', 'yby-world'],
            BusinessProject::query()->orderBy('id')->pluck('project_key')->all(),
        );
        $this->assertDatabaseHas('business_project_sites', ['domain_normalized' => 'ybyirrigation.com']);
        $this->assertDatabaseHas('business_project_sites', ['domain_normalized' => 'yby-irrigation.com']);
        $this->assertDatabaseHas('business_project_sites', ['domain_normalized' => 'ybybottle.com']);
        $this->assertDatabaseHas('business_project_sites', ['domain_normalized' => 'ybyworld.com']);
        $this->assertSame(4, BusinessProjectSite::count());
    }
}
<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class GscAutomaticSyncScheduleTest extends TestCase
{
    public function test_scheduler_registers_bounded_daily_gsc_sync_when_enabled(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 10:00:00', 'Asia/Shanghai'));
        config([
            'services.google_search_console.sync_enabled' => true,
            'services.google_search_console.sync_project' => 'yby-irrigation',
            'services.google_search_console.sync_site' => 'ybyirrigation-com',
            'services.google_search_console.sync_property' => 'sc-domain:ybyirrigation.com',
            'services.google_search_console.sync_time' => '04:20',
            'services.google_search_console.sync_timezone' => 'Asia/Shanghai',
            'services.google_search_console.sync_lookback_days' => 7,
            'services.google_search_console.sync_data_lag_days' => 2,
        ]);

        require __DIR__.'/../../routes/console.php';

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => $event->description === 'traffic.gsc.seo.daily');

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('20 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(180, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertStringContainsString('traffic:seo-gsc:sync', $event->command);
        $this->assertStringContainsString('yby-irrigation', $event->command);
        $this->assertStringContainsString('ybyirrigation-com', $event->command);
        $this->assertStringContainsString('sc-domain:ybyirrigation.com', $event->command);
        $this->assertStringContainsString('--from=2026-09-10', $event->command);
        $this->assertStringContainsString('--to=2026-09-16', $event->command);
        $this->assertStringContainsString('--apply', $event->command);
        $this->assertStringContainsString('--no-interaction', $event->command);

        CarbonImmutable::setTestNow();
    }
}

